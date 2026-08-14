<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\InventoryRepository;
use App\Repositories\InvoiceRepository;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use Tests\TestCase;

/**
 * دورة حياة الفاتورة | Invoice lifecycle (§4.10, §14).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * قاعدتان محروستان هنا:
 *
 *  1. **المسودة تُحرَّر والمستند لا يُحرَّر.** بعد الإصدار يحمل العميل نسخة،
 *     فتعديل بند يُنتج نسختين تحملان الرقم نفسه.
 *  2. **المنصة تسجّل ولا تحصّل.** المقبوض تدوين لمبلغ استُلم خارج المنصة،
 *     ولا يتجاوز المتبقّي على الفاتورة (§14).
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class InvoiceLifecycleTest extends TestCase
{
    private InvoiceService $invoices;

    private InvoiceRepository $repository;

    private InventoryService $inventory;

    private InventoryRepository $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invoices   = new InvoiceService();
        $this->repository = new InvoiceRepository();
        $this->inventory  = new InventoryService();
        $this->items      = new InventoryRepository();
    }

    // ═══════════════════ الترقيم | Numbering ═══════════════════

    /** الترقيم تسلسلي داخل المنشأة | Numbering is sequential within the organization. */
    public function test_invoice_numbers_are_sequential_per_organization(): void
    {
        $orgA = $this->createOrganization(['status' => 'verified']);
        $orgB = $this->createOrganization(['status' => 'verified']);

        $a1 = $this->invoices->create($orgA, ['customer_name_ar' => 'عميل'], null);
        $a2 = $this->invoices->create($orgA, ['customer_name_ar' => 'عميل'], null);
        $b1 = $this->invoices->create($orgB, ['customer_name_ar' => 'عميل'], null);

        $year = date('y');

        $this->assertSame("INV-{$year}-0001", $a1['number']);
        $this->assertSame("INV-{$year}-0002", $a2['number']);

        // تسلسل المنشأة الثانية يبدأ من واحد: رقم فاتورة لا يكشف حجم غيرها
        $this->assertSame("INV-{$year}-0001", $b1['number']);
    }

    // ═══════════════════ المجاميع | Totals ═══════════════════

    /** المجموع يُحسب من البنود لا يُستقبل | The total is computed from the lines, never accepted. */
    public function test_totals_are_computed_from_the_lines(): void
    {
        [$orgId, $invoiceId] = $this->draft();

        $this->invoices->addLine($invoiceId, $orgId, [
            'name_ar' => 'بند أول', 'quantity' => 3, 'unit_price' => 100.00, 'vat_rate' => 14,
        ]);
        $this->invoices->addLine($invoiceId, $orgId, [
            'name_ar' => 'بند ثانٍ', 'quantity' => 2, 'unit_price' => 50.50, 'vat_rate' => 0,
        ]);

        $invoice = $this->repository->findOwned($invoiceId, $orgId);

        // 300.00 + 101.00 = 401.00 ، والضريبة 14% على 300 فقط = 42.00
        $this->assertSame('401.00', (string) $invoice['subtotal']);
        $this->assertSame('42.00', (string) $invoice['vat_amount']);
        $this->assertSame('443.00', (string) $invoice['total']);
    }

    /** الخصم لا يتجاوز قيمة البنود | The discount is capped at the subtotal. */
    public function test_a_discount_cannot_exceed_the_subtotal(): void
    {
        [$orgId, $invoiceId] = $this->draft();

        $this->invoices->addLine($invoiceId, $orgId, [
            'name_ar' => 'بند', 'quantity' => 1, 'unit_price' => 100.00,
        ]);

        $this->invoices->applyDiscount($invoiceId, $orgId, 500.00);

        $invoice = $this->repository->findOwned($invoiceId, $orgId);

        $this->assertSame('100.00', (string) $invoice['discount_amount']);
        $this->assertSame('0.00', (string) $invoice['total']);
    }

    /** حذف بند يعيد الحساب | Removing a line recalculates. */
    public function test_removing_a_line_recalculates_the_total(): void
    {
        [$orgId, $invoiceId] = $this->draft();

        $lineId = $this->invoices->addLine($invoiceId, $orgId, [
            'name_ar' => 'بند', 'quantity' => 1, 'unit_price' => 80.00,
        ]);
        $this->invoices->addLine($invoiceId, $orgId, [
            'name_ar' => 'بند ثانٍ', 'quantity' => 1, 'unit_price' => 20.00,
        ]);

        $this->invoices->removeLine($invoiceId, $orgId, $lineId);

        $this->assertSame('20.00', (string) $this->repository->findOwned($invoiceId, $orgId)['total']);
    }

    /** سعر البند يُنسخ لحظة الإضافة | The line price is copied at the moment of adding. */
    public function test_a_line_keeps_its_price_when_the_item_price_later_changes(): void
    {
        $orgId  = $this->createOrganization(['status' => 'verified']);
        $itemId = $this->inventory->create($orgId, [
            'sku' => 'P-1', 'name_ar' => 'صنف', 'sale_price' => 100.00, 'opening_quantity' => 10,
        ], null);

        $invoiceId = $this->invoices->create($orgId, ['customer_name_ar' => 'عميل'], null)['id'];
        $this->invoices->addLine($invoiceId, $orgId, ['item_id' => $itemId, 'quantity' => 2]);

        // يرفع صاحب المشروع السعر لاحقاً
        $this->inventory->update($itemId, $orgId, [
            'sku' => 'P-1', 'name_ar' => 'صنف', 'sale_price' => 250.00,
        ]);

        $line = $this->repository->lines($invoiceId)[0];

        $this->assertSame('100.00', (string) $line['unit_price']);
        $this->assertSame('200.00', (string) $this->repository->findOwned($invoiceId, $orgId)['total']);
    }

    // ═══════════════════ الإصدار | Issuing ═══════════════════

    /** فاتورة بلا بنود لا تُصدَر | An invoice with no lines cannot be issued. */
    public function test_an_empty_invoice_cannot_be_issued(): void
    {
        [$orgId, $invoiceId] = $this->draft();

        try {
            $this->invoices->issue($invoiceId, $orgId, null);
            $this->fail('كان يجب رفض إصدار فاتورة بلا بنود.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame('draft', $this->repository->findOwned($invoiceId, $orgId)['status']);
    }

    /** الإصدار يصرف المخزون | Issuing issues the stock. */
    public function test_issuing_an_invoice_issues_the_stock(): void
    {
        [$orgId, $invoiceId, $itemId] = $this->draftWithStockedItem(quantity: 4, onHand: 10);

        $this->invoices->issue($invoiceId, $orgId, null);

        $this->assertEqualsWithDelta(
            6.0,
            (float) $this->items->findOwned($itemId, $orgId)['quantity_on_hand'],
            0.001,
        );

        $movements = $this->items->movementsFor($itemId, $orgId);

        $this->assertSame('sale', $movements[0]['movement_type']);
        $this->assertSame('invoice', $movements[0]['reference_type']);
        $this->assertSame($invoiceId, (int) $movements[0]['reference_id']);
    }

    /** المسودة لا تحجز مخزوناً | A draft reserves no stock. */
    public function test_a_draft_reserves_no_stock(): void
    {
        [$orgId, , $itemId] = $this->draftWithStockedItem(quantity: 4, onHand: 10);

        $this->assertEqualsWithDelta(
            10.0,
            (float) $this->items->findOwned($itemId, $orgId)['quantity_on_hand'],
            0.001,
        );
    }

    /** نقص المخزون يُبطل الإصدار كله | Insufficient stock aborts the whole issue. */
    public function test_insufficient_stock_aborts_the_entire_issue(): void
    {
        [$orgId, $invoiceId, $itemId] = $this->draftWithStockedItem(quantity: 20, onHand: 5);

        try {
            $this->invoices->issue($invoiceId, $orgId, null);
            $this->fail('كان يجب رفض الإصدار لنقص المخزون.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // لا الحالة تغيّرت ولا المخزون تحرّك
        $this->assertSame('draft', $this->repository->findOwned($invoiceId, $orgId)['status']);
        $this->assertEqualsWithDelta(
            5.0,
            (float) $this->items->findOwned($itemId, $orgId)['quantity_on_hand'],
            0.001,
        );
        $this->assertCount(1, $this->items->movementsFor($itemId, $orgId));
    }

    /** لا تُصدَر مرتين | An invoice is not issued twice. */
    public function test_an_invoice_cannot_be_issued_twice(): void
    {
        [$orgId, $invoiceId] = $this->issuedInvoice();

        $this->expectException(HttpException::class);
        $this->invoices->issue($invoiceId, $orgId, null);
    }

    // ═══════════════════ المستند لا يُحرَّر | The document is immutable ═══════════════════

    /** لا تُضاف بنود بعد الإصدار | No lines are added after issuing. */
    public function test_no_line_can_be_added_after_issuing(): void
    {
        [$orgId, $invoiceId] = $this->issuedInvoice();

        try {
            $this->invoices->addLine($invoiceId, $orgId, [
                'name_ar' => 'بند مهرَّب', 'quantity' => 1, 'unit_price' => 999,
            ]);
            $this->fail('كان يجب رفض إضافة بند لفاتورة صادرة.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('بعد إصدارها', $e->getMessage());
        }

        $this->assertCount(1, $this->repository->lines($invoiceId));
    }

    /** لا تُحذف بنود بعد الإصدار | No line is removed after issuing. */
    public function test_no_line_can_be_removed_after_issuing(): void
    {
        [$orgId, $invoiceId] = $this->issuedInvoice();
        $lineId              = (int) $this->repository->lines($invoiceId)[0]['id'];

        $this->expectException(HttpException::class);
        $this->invoices->removeLine($invoiceId, $orgId, $lineId);
    }

    /** لا يُعدَّل الرأس بعد الإصدار | The header is not edited after issuing. */
    public function test_the_header_cannot_be_edited_after_issuing(): void
    {
        [$orgId, $invoiceId] = $this->issuedInvoice();

        $this->expectException(HttpException::class);
        $this->invoices->updateDraft($invoiceId, $orgId, ['customer_name_ar' => 'عميل آخر']);
    }

    /** لا يُطبَّق خصم بعد الإصدار | No discount is applied after issuing. */
    public function test_no_discount_can_be_applied_after_issuing(): void
    {
        [$orgId, $invoiceId] = $this->issuedInvoice();

        $this->expectException(HttpException::class);
        $this->invoices->applyDiscount($invoiceId, $orgId, 10.00);
    }

    // ═══════════════════ الإلغاء | Cancellation ═══════════════════

    /** الإلغاء يستوجب سبباً | Cancelling requires a reason. */
    public function test_cancelling_requires_a_written_reason(): void
    {
        [$orgId, $invoiceId] = $this->issuedInvoice();

        try {
            $this->invoices->cancel($invoiceId, $orgId, 'خطأ', null);
            $this->fail('كان يجب رفض الإلغاء بسبب أقصر من الحدّ.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame('issued', $this->repository->findOwned($invoiceId, $orgId)['status']);
    }

    /** الإلغاء يعيد المخزون | Cancelling returns the stock. */
    public function test_cancelling_an_issued_invoice_returns_the_stock(): void
    {
        [$orgId, $invoiceId, $itemId] = $this->draftWithStockedItem(quantity: 4, onHand: 10);
        $this->invoices->issue($invoiceId, $orgId, null);

        $this->invoices->cancel($invoiceId, $orgId, 'ألغى العميل الطلب قبل التسليم.', null);

        $this->assertEqualsWithDelta(
            10.0,
            (float) $this->items->findOwned($itemId, $orgId)['quantity_on_hand'],
            0.001,
        );

        // الإرجاع حركة مستقلة لا محو للحركة الأصلية
        $movements = $this->items->movementsFor($itemId, $orgId);
        $this->assertCount(3, $movements);
        $this->assertSame('return_in', $movements[0]['movement_type']);
    }

    /** فاتورة قُبض منها لا تُلغى | An invoice with receipts is not cancelled. */
    public function test_an_invoice_with_receipts_cannot_be_cancelled(): void
    {
        [$orgId, $invoiceId] = $this->issuedInvoice();
        $this->invoices->recordPayment($invoiceId, $orgId, ['amount' => 50.00], null);

        try {
            $this->invoices->cancel($invoiceId, $orgId, 'رغبة في الإلغاء بعد التحصيل.', null);
            $this->fail('كان يجب رفض إلغاء فاتورة سُجّلت عليها مقبوضات.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('مقبوضات', $e->getMessage());
        }
    }

    // ═══════════════════ المقبوضات | Receipts ═══════════════════

    /** المقبوض لا يتجاوز المتبقّي | A receipt cannot exceed the balance due. */
    public function test_a_receipt_cannot_exceed_the_balance_due(): void
    {
        [$orgId, $invoiceId] = $this->issuedInvoice(); // 100.00

        try {
            $this->invoices->recordPayment($invoiceId, $orgId, ['amount' => 150.00], null);
            $this->fail('كان يجب رفض مقبوض أكبر من المتبقّي.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame('0.00', (string) $this->repository->findOwned($invoiceId, $orgId)['amount_paid']);
    }

    /** السداد الجزئي ثم الكامل يحرّك الحالة | Partial then full payment moves the status. */
    public function test_partial_then_full_payment_moves_the_status(): void
    {
        [$orgId, $invoiceId] = $this->issuedInvoice(); // 100.00

        $this->invoices->recordPayment($invoiceId, $orgId, ['amount' => 40.00], null);
        $this->assertSame('partially_paid', $this->repository->findOwned($invoiceId, $orgId)['status']);

        $this->invoices->recordPayment($invoiceId, $orgId, ['amount' => 60.00], null);

        $invoice = $this->repository->findOwned($invoiceId, $orgId);
        $this->assertSame('paid', $invoice['status']);
        $this->assertSame('100.00', (string) $invoice['amount_paid']);
        $this->assertSame('0.00', (string) $invoice['balance_due']);
    }

    /** المسدَّد مشتقّ من الإيصالات | The paid total is derived from the receipts. */
    public function test_deleting_a_receipt_recomputes_the_paid_total(): void
    {
        [$orgId, $invoiceId] = $this->issuedInvoice();

        $receipt = $this->invoices->recordPayment($invoiceId, $orgId, ['amount' => 100.00], null);
        $this->assertSame('paid', $this->repository->findOwned($invoiceId, $orgId)['status']);

        $this->invoices->deletePayment($receipt['id'], $invoiceId, $orgId);

        $invoice = $this->repository->findOwned($invoiceId, $orgId);
        $this->assertSame('0.00', (string) $invoice['amount_paid']);
        $this->assertSame('issued', $invoice['status']);
    }

    /** لا مقبوضات على مسودة | No receipt against a draft. */
    public function test_no_receipt_against_a_draft(): void
    {
        [$orgId, $invoiceId] = $this->draft();

        try {
            $this->invoices->recordPayment($invoiceId, $orgId, ['amount' => 10.00], null);
            $this->fail('كان يجب رفض المقبوض على مسودة.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('أصدر الفاتورة', $e->getMessage());
        }
    }

    // ═══════════════════ العزل | Isolation ═══════════════════

    /** منشأة لا تقرأ فاتورة أخرى | One organization cannot read another's invoice. */
    public function test_another_organization_cannot_reach_the_invoice(): void
    {
        [, $invoiceId] = $this->issuedInvoice();
        $intruder      = $this->createOrganization(['status' => 'verified']);

        $this->assertNull($this->repository->findOwned($invoiceId, $intruder));

        $this->expectException(HttpException::class);
        $this->invoices->cancel($invoiceId, $intruder, 'محاولة إلغاء من الخارج.', null);
    }

    /** عميل منشأة أخرى لا يُربط بالفاتورة | Another organization's customer cannot be attached. */
    public function test_a_foreign_customer_cannot_be_attached(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);
        $other = $this->createOrganization(['status' => 'verified']);

        $foreignCustomer = Database::insert(
            'INSERT INTO crm_customers (organization_id, code, name_ar) VALUES (?, ?, ?)',
            [$other, 'C-1', 'عميل غريب'],
        );

        $this->expectException(HttpException::class);
        $this->invoices->create($orgId, ['customer_id' => $foreignCustomer], null);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @return array{0:int,1:int} [organizationId, invoiceId] */
    private function draft(): array
    {
        $orgId  = $this->createOrganization(['status' => 'verified']);
        $result = $this->invoices->create($orgId, ['customer_name_ar' => 'عميل اختباري'], null);

        return [$orgId, $result['id']];
    }

    /** فاتورة صادرة بقيمة 100.00 | An issued invoice worth 100.00. */
    private function issuedInvoice(): array
    {
        [$orgId, $invoiceId] = $this->draft();

        $this->invoices->addLine($invoiceId, $orgId, [
            'name_ar' => 'خدمة', 'quantity' => 1, 'unit_price' => 100.00,
        ]);
        $this->invoices->issue($invoiceId, $orgId, null);

        return [$orgId, $invoiceId];
    }

    /** @return array{0:int,1:int,2:int} [organizationId, invoiceId, itemId] */
    private function draftWithStockedItem(float $quantity, float $onHand): array
    {
        static $counter = 0;
        $counter++;

        $orgId = $this->createOrganization(['status' => 'verified']);

        $itemId = $this->inventory->create($orgId, [
            'sku'              => 'STK-' . $counter,
            'name_ar'          => 'صنف مخزون',
            'sale_price'       => 25.00,
            'opening_quantity' => $onHand,
        ], null);

        $invoiceId = $this->invoices->create($orgId, ['customer_name_ar' => 'عميل'], null)['id'];
        $this->invoices->addLine($invoiceId, $orgId, ['item_id' => $itemId, 'quantity' => $quantity]);

        return [$orgId, $invoiceId, $itemId];
    }
}
