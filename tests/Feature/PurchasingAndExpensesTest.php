<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\InventoryRepository;
use App\Services\ExpenseService;
use App\Services\InventoryService;
use App\Services\PurchaseOrderService;
use Tests\TestCase;

/**
 * المشتريات والمصروفات | Purchasing and expenses (§4.10, §14).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * القاعدة المحروسة: **الاستلام هو ما يزيد المخزون، لا إصدار الأمر.** أمر شراء
 * أُرسل ولم تصل بضاعته ليس بضاعة في المخزن، وزيادة الرصيد عند الإرسال كانت
 * ستجعل صاحب المشروع يبيع ما لم يستلمه.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class PurchasingAndExpensesTest extends TestCase
{
    private PurchaseOrderService $purchasing;

    private ExpenseService $expenses;

    private InventoryService $inventory;

    private InventoryRepository $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasing = new PurchaseOrderService();
        $this->expenses   = new ExpenseService();
        $this->inventory  = new InventoryService();
        $this->items      = new InventoryRepository();
    }

    // ═══════════════════ الاستلام يزيد المخزون | Receiving moves stock ═══════════════════

    /** الإرسال لا يزيد المخزون | Sending the order moves no stock. */
    public function test_sending_a_purchase_order_does_not_move_stock(): void
    {
        [$orgId, $poId, $itemId] = $this->purchaseOrder(quantity: 100);

        $this->purchasing->send($poId, $orgId);

        $this->assertEqualsWithDelta(
            0.0,
            (float) $this->items->findOwned($itemId, $orgId)['quantity_on_hand'],
            0.001,
        );
        $this->assertSame([], $this->items->movementsFor($itemId, $orgId));
    }

    /** الاستلام يزيد المخزون بحركة مرجعية | Receiving moves stock with a referenced movement. */
    public function test_receiving_moves_stock_and_references_the_order(): void
    {
        [$orgId, $poId, $itemId, $lineId] = $this->purchaseOrder(quantity: 100);
        $this->purchasing->send($poId, $orgId);

        $this->purchasing->receive($poId, $orgId, [['line_id' => $lineId, 'quantity' => 100]], null);

        $this->assertEqualsWithDelta(
            100.0,
            (float) $this->items->findOwned($itemId, $orgId)['quantity_on_hand'],
            0.001,
        );

        $movement = $this->items->movementsFor($itemId, $orgId)[0];

        $this->assertSame('purchase', $movement['movement_type']);
        $this->assertSame('purchase_order', $movement['reference_type']);
        $this->assertSame($poId, (int) $movement['reference_id']);
    }

    /** الاستلام الجزئي يتراكم والحالة تتبعه | Partial receipts accumulate and drive the status. */
    public function test_partial_receipts_accumulate_and_close_the_order(): void
    {
        [$orgId, $poId, $itemId, $lineId] = $this->purchaseOrder(quantity: 100);
        $this->purchasing->send($poId, $orgId);

        $this->purchasing->receive($poId, $orgId, [['line_id' => $lineId, 'quantity' => 40]], null);

        $this->assertSame('partially_received', $this->po($poId, $orgId)['status']);
        $this->assertEqualsWithDelta(
            40.0,
            (float) $this->items->findOwned($itemId, $orgId)['quantity_on_hand'],
            0.001,
        );

        $this->purchasing->receive($poId, $orgId, [['line_id' => $lineId, 'quantity' => 60]], null);

        $po = $this->po($poId, $orgId);

        $this->assertSame('received', $po['status']);
        $this->assertNotNull($po['received_at']);
        $this->assertEqualsWithDelta(
            100.0,
            (float) $this->items->findOwned($itemId, $orgId)['quantity_on_hand'],
            0.001,
        );
    }

    /** الاستلام لا يتجاوز المطلوب | Receiving cannot exceed what was ordered. */
    public function test_receiving_more_than_ordered_is_refused(): void
    {
        [$orgId, $poId, $itemId, $lineId] = $this->purchaseOrder(quantity: 50);
        $this->purchasing->send($poId, $orgId);

        try {
            $this->purchasing->receive($poId, $orgId, [['line_id' => $lineId, 'quantity' => 80]], null);
            $this->fail('كان يجب رفض استلام أكثر من المطلوب.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('أكبر من المتبقّي', $e->getMessage());
        }

        // لا الرصيد تحرّك ولا المستلم تغيّر
        $this->assertEqualsWithDelta(
            0.0,
            (float) $this->items->findOwned($itemId, $orgId)['quantity_on_hand'],
            0.001,
        );
    }

    /** لا استلام قبل الإرسال | No receipt before the order is sent. */
    public function test_no_receipt_before_the_order_is_sent(): void
    {
        [$orgId, $poId, , $lineId] = $this->purchaseOrder(quantity: 10);

        try {
            $this->purchasing->receive($poId, $orgId, [['line_id' => $lineId, 'quantity' => 5]], null);
            $this->fail('كان يجب رفض الاستلام على مسودة.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('أرسل أمر الشراء', $e->getMessage());
        }
    }

    /** الاستلام يحدّث آخر تكلفة معروفة | Receiving refreshes the last known cost. */
    public function test_receiving_updates_the_last_known_cost(): void
    {
        [$orgId, $poId, $itemId, $lineId] = $this->purchaseOrder(quantity: 10, unitCost: 37.50);
        $this->purchasing->send($poId, $orgId);
        $this->purchasing->receive($poId, $orgId, [['line_id' => $lineId, 'quantity' => 10]], null);

        $this->assertSame('37.50', (string) $this->items->findOwned($itemId, $orgId)['cost_price']);
    }

    // ═══════════════════ أمر الشراء | The order document ═══════════════════

    /** أمر بلا بنود لا يُرسل | An empty order is not sent. */
    public function test_an_empty_order_cannot_be_sent(): void
    {
        $orgId      = $this->createOrganization(['status' => 'verified']);
        $supplierId = $this->purchasing->createSupplier($orgId, ['name_ar' => 'مورّد']);
        $poId       = $this->purchasing->create($orgId, ['supplier_id' => $supplierId], null)['id'];

        $this->expectException(HttpException::class);
        $this->purchasing->send($poId, $orgId);
    }

    /** لا تُعدَّل البنود بعد الإرسال | Lines are not edited after sending. */
    public function test_lines_cannot_be_edited_after_sending(): void
    {
        [$orgId, $poId, $itemId] = $this->purchaseOrder(quantity: 10);
        $this->purchasing->send($poId, $orgId);

        try {
            $this->purchasing->addLine($poId, $orgId, ['item_id' => $itemId, 'quantity_ordered' => 5]);
            $this->fail('كان يجب رفض إضافة بند بعد الإرسال.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /** المجاميع تُحسب من البنود | Totals come from the lines. */
    public function test_totals_are_computed_from_the_lines(): void
    {
        [$orgId, $poId] = $this->purchaseOrder(quantity: 4, unitCost: 25.00, vatRate: 14);

        $po = $this->po($poId, $orgId);

        $this->assertSame('100.00', (string) $po['subtotal']);
        $this->assertSame('14.00', (string) $po['vat_amount']);
        $this->assertSame('114.00', (string) $po['total']);
    }

    /** أمر استُلم جزء منه لا يُلغى | A partly received order is not cancelled. */
    public function test_a_partly_received_order_cannot_be_cancelled(): void
    {
        [$orgId, $poId, , $lineId] = $this->purchaseOrder(quantity: 10);
        $this->purchasing->send($poId, $orgId);
        $this->purchasing->receive($poId, $orgId, [['line_id' => $lineId, 'quantity' => 3]], null);

        try {
            $this->purchasing->cancel($poId, $orgId, 'تغيّرت خطة الشراء.');
            $this->fail('كان يجب رفض إلغاء أمر استُلم جزء منه.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('مستند يفسّر مصدره', $e->getMessage());
        }
    }

    /** الإلغاء يستوجب سبباً | Cancelling requires a reason. */
    public function test_cancelling_an_order_requires_a_reason(): void
    {
        [$orgId, $poId] = $this->purchaseOrder(quantity: 10);

        $this->expectException(HttpException::class);
        $this->purchasing->cancel($poId, $orgId, 'لا');
    }

    /** صنف منشأة أخرى لا يُشترى | Another organization's item cannot be ordered. */
    public function test_a_foreign_item_cannot_be_ordered(): void
    {
        [$orgId, $poId] = $this->purchaseOrder(quantity: 10);

        $other       = $this->createOrganization(['status' => 'verified']);
        $foreignItem = $this->inventory->create($other, ['sku' => 'F-1', 'name_ar' => 'صنف غريب'], null);

        $this->expectException(HttpException::class);
        $this->purchasing->addLine($poId, $orgId, ['item_id' => $foreignItem, 'quantity_ordered' => 1]);
    }

    /** مورّد له أوامر مفتوحة لا يُؤرشَف | A supplier with open orders is not archived. */
    public function test_a_supplier_with_open_orders_cannot_be_archived(): void
    {
        [$orgId, $poId] = $this->purchaseOrder(quantity: 10);
        $this->purchasing->send($poId, $orgId);

        $supplierId = (int) $this->po($poId, $orgId)['supplier_id'];

        $this->expectException(HttpException::class);
        $this->purchasing->archiveSupplier($supplierId, $orgId);
    }

    // ═══════════════════ المصروفات | Expenses ═══════════════════

    /** المصروف يُرقَّم تسلسلياً | Expenses are numbered sequentially. */
    public function test_expenses_are_numbered_sequentially(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);

        $first  = $this->expenses->create($orgId, ['description_ar' => 'إيجار', 'amount' => 5000], null);
        $second = $this->expenses->create($orgId, ['description_ar' => 'كهرباء', 'amount' => 800], null);

        $year = date('y');

        $this->assertSame("EXP-{$year}-0001", $first['number']);
        $this->assertSame("EXP-{$year}-0002", $second['number']);
    }

    /** مصروف بتاريخ مستقبلي مرفوض | A future-dated expense is refused. */
    public function test_a_future_dated_expense_is_refused(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);

        try {
            $this->expenses->create($orgId, [
                'description_ar' => 'مصروف لم يخرج بعد',
                'amount'         => 100,
                'spent_at'       => date('Y-m-d', time() + 86400 * 7),
            ], null);
            $this->fail('كان يجب رفض مصروف بتاريخ مستقبلي.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('المستقبل', $e->getMessage());
        }
    }

    /** المبلغ إلزامي وموجب | The amount is required and positive. */
    public function test_an_expense_needs_a_positive_amount(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);

        $this->expectException(HttpException::class);
        $this->expenses->create($orgId, ['description_ar' => 'بلا مبلغ', 'amount' => 0], null);
    }

    /** مورّد منشأة أخرى لا يُربط | A foreign supplier cannot be attached. */
    public function test_a_foreign_supplier_cannot_be_attached_to_an_expense(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);
        $other = $this->createOrganization(['status' => 'verified']);

        $foreign = $this->purchasing->createSupplier($other, ['name_ar' => 'مورّد غريب']);

        $this->expectException(HttpException::class);
        $this->expenses->create($orgId, [
            'description_ar' => 'شراء',
            'amount'         => 100,
            'supplier_id'    => $foreign,
        ], null);
    }

    /** منشأة لا تحذف مصروف أخرى | One organization cannot delete another's expense. */
    public function test_another_organization_cannot_delete_the_expense(): void
    {
        $orgId    = $this->createOrganization(['status' => 'verified']);
        $intruder = $this->createOrganization(['status' => 'verified']);

        $expense = $this->expenses->create($orgId, ['description_ar' => 'إيجار', 'amount' => 100], null);

        $this->expectException(HttpException::class);
        $this->expenses->delete($expense['id'], $intruder);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @return array{0:int,1:int,2:int,3:int} [orgId, poId, itemId, lineId] */
    private function purchaseOrder(float $quantity, float $unitCost = 10.00, float $vatRate = 0): array
    {
        static $counter = 0;
        $counter++;

        $orgId      = $this->createOrganization(['status' => 'verified']);
        $supplierId = $this->purchasing->createSupplier($orgId, ['name_ar' => 'مورّد ' . $counter]);

        $itemId = $this->inventory->create($orgId, [
            'sku'     => 'PO-' . $counter,
            'name_ar' => 'صنف مشترى ' . $counter,
        ], null);

        $poId = $this->purchasing->create($orgId, ['supplier_id' => $supplierId], null)['id'];

        $lineId = $this->purchasing->addLine($poId, $orgId, [
            'item_id'          => $itemId,
            'quantity_ordered' => $quantity,
            'unit_cost'        => $unitCost,
            'vat_rate'         => $vatRate,
        ]);

        return [$orgId, $poId, $itemId, $lineId];
    }

    /** @return array<string,mixed> */
    private function po(int $poId, int $organizationId): array
    {
        return Database::selectOne(
            'SELECT * FROM erp_purchase_orders WHERE id = ? AND organization_id = ?',
            [$poId, $organizationId],
        ) ?? [];
    }
}
