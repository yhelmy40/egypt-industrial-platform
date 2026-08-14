<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\InventoryRepository;
use App\Services\InventoryService;
use Tests\TestCase;

/**
 * دفتر المخزون | The stock ledger (§4.10).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * القاعدة المحروسة هنا: **الرصيد مشتقّ لا مُدخَل.** كل تغيّر يمرّ بحركة تحمل
 * سببها وفاعلها ورصيدها بعدها. لا مسار في المنصة يكتب `quantity_on_hand`
 * دون أن يترك في الدفتر سطراً يفسّره.
 *
 * لو كُسرت هذه القاعدة لصار رقم المخزون رأياً لا سجلّاً: صاحب مشروع يجد رصيده
 * أقلّ مما يظنّ لا يملك ما يسأل عنه.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class StockLedgerTest extends TestCase
{
    private InventoryService $inventory;

    private InventoryRepository $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = new InventoryService();
        $this->items     = new InventoryRepository();
    }

    // ═══════════════════ الرصيد مشتقّ | The balance is derived ═══════════════════

    /** كل حركة تترك سطراً وتحرّك الرصيد | Every movement leaves a row and moves the balance. */
    public function test_the_balance_always_equals_the_sum_of_its_movements(): void
    {
        [$orgId, $itemId] = $this->item(['opening_quantity' => 100]);

        $this->inventory->record($itemId, $orgId, 'purchase', 50, null, unitCost: 12.5);
        $this->inventory->record($itemId, $orgId, 'sale', 30, null);
        $this->inventory->record($itemId, $orgId, 'damage', 5, null, reason: 'تلف أثناء النقل.');

        $item = $this->items->findOwned($itemId, $orgId);

        $this->assertEqualsWithDelta(115.0, (float) $item['quantity_on_hand'], 0.001);

        $ledgerSum = (float) Database::scalar(
            'SELECT SUM(quantity_delta) FROM erp_stock_movements WHERE item_id = ?',
            [$itemId],
        );

        $this->assertEqualsWithDelta(115.0, $ledgerSum, 0.001);
        $this->assertCount(4, $this->items->movementsFor($itemId, $orgId));
    }

    /** الرصيد الافتتاحي حركة لا قيمة ابتدائية | The opening balance is a movement, not a seed value. */
    public function test_the_opening_balance_is_recorded_as_a_movement(): void
    {
        [$orgId, $itemId] = $this->item(['opening_quantity' => 40]);

        $movements = $this->items->movementsFor($itemId, $orgId);

        $this->assertCount(1, $movements);
        $this->assertSame('opening', $movements[0]['movement_type']);
        $this->assertEqualsWithDelta(40.0, (float) $movements[0]['balance_after'], 0.001);
    }

    /** كل حركة تثبّت الرصيد بعدها | Each movement stamps the balance that followed it. */
    public function test_each_movement_stamps_the_running_balance(): void
    {
        [$orgId, $itemId] = $this->item(['opening_quantity' => 10]);

        $this->inventory->record($itemId, $orgId, 'purchase', 5, null);
        $this->inventory->record($itemId, $orgId, 'sale', 3, null);

        $movements = array_reverse($this->items->movementsFor($itemId, $orgId));
        $balances  = array_map(static fn (array $m): float => (float) $m['balance_after'], $movements);

        $this->assertSame([10.0, 15.0, 12.0], $balances);
    }

    // ═══════════════════ ما يرفضه الدفتر | What the ledger refuses ═══════════════════

    /** الرصيد لا يصير سالباً | The balance never goes negative. */
    public function test_stock_cannot_go_negative(): void
    {
        [$orgId, $itemId] = $this->item(['opening_quantity' => 5]);

        try {
            $this->inventory->record($itemId, $orgId, 'sale', 9, null);
            $this->fail('كان يجب رفض صرف أكثر من الرصيد.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('أكبر من الرصيد', $e->getMessage());
        }

        // الرفض لم يترك حركة معلّقة ولا رصيداً مغيَّراً
        $this->assertCount(1, $this->items->movementsFor($itemId, $orgId));
        $this->assertEqualsWithDelta(
            5.0,
            (float) $this->items->findOwned($itemId, $orgId)['quantity_on_hand'],
            0.001,
        );
    }

    /** التسوية بلا سبب مرفوضة | An adjustment without a reason is refused. */
    public function test_an_adjustment_requires_a_written_reason(): void
    {
        [$orgId, $itemId] = $this->item(['opening_quantity' => 10]);

        try {
            $this->inventory->record($itemId, $orgId, 'adjustment', -2, null, reason: '  ');
            $this->fail('كان يجب رفض تسوية بلا سبب.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('سبب', $e->getMessage());
        }

        $this->assertCount(1, $this->items->movementsFor($itemId, $orgId));
    }

    /** حركة واردة بكمية سالبة مرفوضة | An inbound movement with a negative quantity is refused. */
    public function test_an_inbound_movement_cannot_carry_a_negative_quantity(): void
    {
        [$orgId, $itemId] = $this->item(['opening_quantity' => 10]);

        $this->expectException(HttpException::class);
        $this->inventory->record($itemId, $orgId, 'purchase', -5, null);
    }

    /** حركة بكمية صفر مرفوضة | A zero-quantity movement is refused. */
    public function test_a_zero_movement_is_refused(): void
    {
        [$orgId, $itemId] = $this->item(['opening_quantity' => 10]);

        $this->expectException(HttpException::class);
        $this->inventory->record($itemId, $orgId, 'purchase', 0, null);
    }

    /** الخدمة لا حركات عليها | A service item accepts no movements. */
    public function test_a_service_item_accepts_no_stock_movements(): void
    {
        [$orgId, $itemId] = $this->item(['item_type' => 'service']);

        try {
            $this->inventory->record($itemId, $orgId, 'purchase', 5, null);
            $this->fail('كان يجب رفض حركة على صنف خدمي.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /** نوع حركة مجهول مرفوض | An unknown movement type is refused. */
    public function test_an_unknown_movement_type_is_refused(): void
    {
        [$orgId, $itemId] = $this->item(['opening_quantity' => 10]);

        $this->expectException(HttpException::class);
        $this->inventory->record($itemId, $orgId, 'whatever', 5, null);
    }

    // ═══════════════════ التسوية بالجرد | Adjust to a counted balance ═══════════════════

    /** التسوية تستقبل المعدود وتحسب الفرق | The stocktake takes the count and derives the delta. */
    public function test_adjusting_to_a_count_records_the_difference(): void
    {
        [$orgId, $itemId] = $this->item(['opening_quantity' => 100]);

        $this->inventory->adjustToCount($itemId, $orgId, 93, 'جرد شهري: عجز 7 وحدات.', null);

        $item      = $this->items->findOwned($itemId, $orgId);
        $movements = $this->items->movementsFor($itemId, $orgId);

        $this->assertEqualsWithDelta(93.0, (float) $item['quantity_on_hand'], 0.001);
        $this->assertSame('adjustment', $movements[0]['movement_type']);
        $this->assertEqualsWithDelta(-7.0, (float) $movements[0]['quantity_delta'], 0.001);
        $this->assertStringContainsString('عجز', (string) $movements[0]['reason_ar']);
    }

    /** جرد مطابق لا يُنتج حركة | A matching count produces no movement. */
    public function test_a_matching_count_writes_no_movement(): void
    {
        [$orgId, $itemId] = $this->item(['opening_quantity' => 20]);

        $result = $this->inventory->adjustToCount($itemId, $orgId, 20, 'جرد مطابق.', null);

        $this->assertNull($result);
        $this->assertCount(1, $this->items->movementsFor($itemId, $orgId));
    }

    // ═══════════════════ التعديل لا يمسّ الرصيد | Editing never touches the balance ═══════════════════

    /** تعديل بيانات الصنف لا يغيّر رصيده | Editing an item's details leaves the balance alone. */
    public function test_editing_an_item_cannot_change_its_balance(): void
    {
        [$orgId, $itemId] = $this->item(['opening_quantity' => 77]);

        // حتى لو أُرسل الرصيد في المدخلات، لا يقرؤه مسار التعديل
        $this->inventory->update($itemId, $orgId, [
            'sku'              => 'SKU-EDITED',
            'name_ar'          => 'اسم معدَّل',
            'quantity_on_hand' => 9999,
        ]);

        $item = $this->items->findOwned($itemId, $orgId);

        $this->assertSame('اسم معدَّل', $item['name_ar']);
        $this->assertEqualsWithDelta(77.0, (float) $item['quantity_on_hand'], 0.001);
        $this->assertCount(1, $this->items->movementsFor($itemId, $orgId));
    }

    /** إيقاف التتبّع لصنف له رصيد مرفوض | Untracking an item that still holds stock is refused. */
    public function test_untracking_an_item_with_stock_is_refused(): void
    {
        [$orgId, $itemId] = $this->item(['opening_quantity' => 12]);

        try {
            $this->inventory->update($itemId, $orgId, [
                'sku'         => 'SKU-1',
                'name_ar'     => 'صنف',
                'track_stock' => 0,
            ]);
            $this->fail('كان يجب رفض إيقاف التتبّع لصنف له رصيد.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    // ═══════════════════ العزل | Isolation ═══════════════════

    /** منشأة لا تحرّك مخزون أخرى | One organization cannot move another's stock. */
    public function test_another_organization_cannot_move_the_stock(): void
    {
        [, $itemId] = $this->item(['opening_quantity' => 10]);
        $intruder   = $this->createOrganization(['status' => 'verified']);

        try {
            $this->inventory->record($itemId, $intruder, 'sale', 1, null);
            $this->fail('كان يجب حجب الصنف عن منشأة أخرى.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    /** كود الصنف فريد داخل المنشأة لا عبر المنصة | The SKU is unique per organization, not globally. */
    public function test_the_sku_is_unique_within_the_organization_only(): void
    {
        [$orgId] = $this->item(['sku' => 'SHARED-SKU']);
        $other   = $this->createOrganization(['status' => 'verified']);

        // منشأة أخرى تستخدم الكود نفسه بلا تعارض
        $this->inventory->create($other, ['sku' => 'SHARED-SKU', 'name_ar' => 'صنف آخر'], null);

        // ونفس المنشأة لا تكرّره
        $this->expectException(HttpException::class);
        $this->inventory->create($orgId, ['sku' => 'SHARED-SKU', 'name_ar' => 'مكرّر'], null);
    }

    /** الإعلان المرتبط يتبع رصيد الصنف | A linked listing follows the item's balance. */
    public function test_a_linked_listing_mirrors_the_item_balance(): void
    {
        $orgId     = $this->createOrganization(['status' => 'verified']);
        $listingId = $this->createListing($orgId, ['track_inventory' => 1, 'available_quantity' => 0]);

        $itemId = $this->inventory->create($orgId, [
            'sku'              => 'LINKED-1',
            'name_ar'          => 'صنف مرتبط بإعلان',
            'listing_id'       => $listingId,
            'opening_quantity' => 25,
        ], null);

        $this->assertEqualsWithDelta(
            25.0,
            (float) Database::scalar('SELECT available_quantity FROM listings WHERE id = ?', [$listingId]),
            0.001,
        );

        $this->inventory->record($itemId, $orgId, 'sale', 5, null);

        $this->assertEqualsWithDelta(
            20.0,
            (float) Database::scalar('SELECT available_quantity FROM listings WHERE id = ?', [$listingId]),
            0.001,
        );
    }

    /** إعلان منشأة أخرى لا يُربط | Another organization's listing cannot be linked. */
    public function test_a_foreign_listing_cannot_be_linked(): void
    {
        $orgId   = $this->createOrganization(['status' => 'verified']);
        $other   = $this->createOrganization(['status' => 'verified']);
        $foreign = $this->createListing($other);

        $this->expectException(HttpException::class);
        $this->inventory->create($orgId, [
            'sku'        => 'X-1',
            'name_ar'    => 'محاولة ربط',
            'listing_id' => $foreign,
        ], null);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /**
     * @param  array<string,mixed> $attributes
     * @return array{0:int,1:int} [organizationId, itemId]
     */
    private function item(array $attributes = []): array
    {
        static $counter = 0;
        $counter++;

        $orgId = $this->createOrganization(['status' => 'verified']);

        $itemId = $this->inventory->create($orgId, array_merge([
            'sku'     => 'SKU-' . $counter,
            'name_ar' => 'صنف اختباري ' . $counter,
        ], $attributes), null);

        return [$orgId, $itemId];
    }
}
