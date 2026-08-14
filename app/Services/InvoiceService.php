<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\InvoiceRepository;
use App\Support\DocumentNumber;
use App\Support\Money;

/**
 * خدمة الفواتير والمقبوضات | Invoice and receipt service (§4.10, §14).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **القاعدة الحاكمة: المسودة تُحرَّر والمستند لا يُحرَّر.**
 *
 * قبل الإصدار الفاتورة ورقة داخلية يعدّلها صاحب المشروع كما شاء. بعد الإصدار
 * صار للعميل نسخة منها، فتعديل بند أو سعر يُنتج نسختين مختلفتين تحملان الرقم
 * نفسه — وهذا بالضبط ما يجعل مستنداً غير صالح للاحتجاج به.
 *
 * التصحيح بعد الإصدار **إلغاء موثّق بسبب**، لا تحرير صامت. والإلغاء يُعيد
 * المخزون المصروف، فلا يبقى نقص في المخزن مقابل بيع لم يتمّ.
 *
 * وقاعدة ثانية: **المنصة لا تحصّل شيئاً.** «المقبوضات» هنا تسجيل لمبلغ
 * استلمه صاحب المشروع خارج المنصة، لا عملية دفع تمّت داخلها (§14).
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class InvoiceService
{
    /** الحالات التي تقبل التحرير | Statuses that accept editing. */
    private const EDITABLE = ['draft'];

    /** الحالات التي تقبل تسجيل مقبوضات | Statuses that accept receipts. */
    private const COLLECTIBLE = ['issued', 'partially_paid'];

    public function __construct(
        private readonly InvoiceRepository $invoices = new InvoiceRepository(),
        private readonly InventoryService $inventory = new InventoryService(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    // ═══════════════════ إنشاء وتحرير | Creating and editing ═══════════════════

    /**
     * إنشاء فاتورة مسودة | Create a draft invoice.
     *
     * @param  array<string,mixed> $data
     * @return array{id:int,number:string}
     */
    public function create(int $organizationId, array $data, ?int $actorUserId): array
    {
        $customer = $this->resolveCustomer($organizationId, $data);
        $issueAt  = $this->date($data['issue_date'] ?? null) ?? date('Y-m-d');
        $dueAt    = $this->date($data['due_date'] ?? null);

        if ($dueAt !== null && $dueAt < $issueAt) {
            throw new HttpException(422, 'تاريخ الاستحقاق قبل تاريخ الإصدار.');
        }

        return Database::transaction(fn (): array => DocumentNumber::withNumber(
            'erp_invoices',
            'invoice_number',
            $organizationId,
            'INV',
            fn (string $number): int => Database::insert(
                'INSERT INTO erp_invoices
                    (organization_id, invoice_number, customer_id, customer_name_ar,
                     customer_phone, customer_tax_number, order_id, issue_date, due_date,
                     status, notes_ar, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $organizationId,
                    $number,
                    $customer['id'],
                    $customer['name'],
                    $customer['phone'],
                    $customer['tax_number'],
                    $this->resolveOrder($organizationId, $data['order_id'] ?? null),
                    $issueAt,
                    $dueAt,
                    'draft',
                    $this->text($data['notes_ar'] ?? null, 1000),
                    $actorUserId,
                ],
            ),
        ));
    }

    /**
     * تعديل رأس فاتورة مسودة | Edit a draft invoice's header.
     *
     * @param array<string,mixed> $data
     */
    public function updateDraft(int $invoiceId, int $organizationId, array $data): void
    {
        $invoice  = $this->requireEditable($invoiceId, $organizationId);
        $customer = $this->resolveCustomer($organizationId, $data);
        $issueAt  = $this->date($data['issue_date'] ?? null) ?? (string) $invoice['issue_date'];
        $dueAt    = $this->date($data['due_date'] ?? null);

        if ($dueAt !== null && $dueAt < $issueAt) {
            throw new HttpException(422, 'تاريخ الاستحقاق قبل تاريخ الإصدار.');
        }

        Database::statement(
            'UPDATE erp_invoices
                SET customer_id = ?, customer_name_ar = ?, customer_phone = ?,
                    customer_tax_number = ?, issue_date = ?, due_date = ?,
                    notes_ar = ?, updated_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [
                $customer['id'],
                $customer['name'],
                $customer['phone'],
                $customer['tax_number'],
                $issueAt,
                $dueAt,
                $this->text($data['notes_ar'] ?? null, 1000),
                $invoiceId,
                $organizationId,
            ],
        );
    }

    /**
     * إضافة بند | Add a line.
     *
     * اسم الصنف وسعره **يُنسخان** لحظة الإضافة: تغيير سعر الصنف غداً يجب ألّا
     * يغيّر فاتورة صدرت بسعر اليوم.
     *
     * @param array<string,mixed> $data
     */
    public function addLine(int $invoiceId, int $organizationId, array $data): int
    {
        $this->requireEditable($invoiceId, $organizationId);

        $quantity = (float) ($data['quantity'] ?? 0);

        if ($quantity <= 0) {
            throw new HttpException(422, 'كمية البند يجب أن تكون أكبر من صفر.');
        }

        $itemId = $this->nullableInt($data['item_id'] ?? null);
        $item   = null;

        if ($itemId !== null) {
            $item = Database::selectOne(
                'SELECT id, name_ar, unit_of_measure, sale_price, vat_rate
                   FROM erp_items
                  WHERE id = ? AND organization_id = ? AND deleted_at IS NULL
                  LIMIT 1',
                [$itemId, $organizationId],
            );

            if ($item === null) {
                throw new HttpException(404, 'الصنف غير موجود في مشروعك.');
            }
        }

        $name = trim((string) ($data['name_ar'] ?? '')) ?: (string) ($item['name_ar'] ?? '');

        if ($name === '') {
            throw new HttpException(422, 'اكتب اسم البند أو اختر صنفاً.');
        }

        $unitPrice = $data['unit_price'] ?? null;

        if ($unitPrice === null || $unitPrice === '') {
            $unitPrice = $item['sale_price'] ?? null;
        }

        if ($unitPrice === null || !is_numeric($unitPrice) || (float) $unitPrice < 0) {
            throw new HttpException(422, 'اكتب سعر الوحدة للبند.');
        }

        $vatRate = $data['vat_rate'] ?? ($item['vat_rate'] ?? 0);

        // الإجمالي يُحسب بالقروش لا بالعشرية العائمة (§8)
        $lineTotal = Money::fromDecimal((float) $unitPrice)->times($quantity);

        $order = (int) Database::scalar(
            'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM erp_invoice_items WHERE invoice_id = ?',
            [$invoiceId],
        );

        $lineId = Database::insert(
            'INSERT INTO erp_invoice_items
                (invoice_id, organization_id, item_id, name_ar, unit_of_measure,
                 quantity, unit_price, vat_rate, line_total, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $invoiceId,
                $organizationId,
                $itemId,
                mb_substr($name, 0, 200),
                $this->text($data['unit_of_measure'] ?? ($item['unit_of_measure'] ?? null), 40),
                $quantity,
                (float) $unitPrice,
                (float) $vatRate,
                $lineTotal->toDecimal(),
                $order,
            ],
        );

        $this->recalculate($invoiceId, $organizationId);

        return $lineId;
    }

    /** حذف بند من مسودة | Remove a line from a draft. */
    public function removeLine(int $invoiceId, int $organizationId, int $lineId): void
    {
        $this->requireEditable($invoiceId, $organizationId);

        Database::statement(
            'DELETE FROM erp_invoice_items
              WHERE id = ? AND invoice_id = ? AND organization_id = ?',
            [$lineId, $invoiceId, $organizationId],
        );

        $this->recalculate($invoiceId, $organizationId);
    }

    /**
     * إعادة حساب المجاميع | Recalculate the totals.
     *
     * تُحسب من البنود دائماً ولا تُقبل من النموذج: مجموع يُرسل من المتصفّح
     * يمكن أن يخالف بنوده، والفاتورة التي لا يساوي مجموعها بنودها بلا قيمة.
     */
    public function recalculate(int $invoiceId, int $organizationId, ?float $discount = null): void
    {
        $lines = Database::select(
            'SELECT quantity, unit_price, vat_rate FROM erp_invoice_items WHERE invoice_id = ?',
            [$invoiceId],
        );

        $subtotal = Money::zero();
        $vat      = Money::zero();

        foreach ($lines as $line) {
            $lineTotal = Money::fromDecimal((float) $line['unit_price'])->times((float) $line['quantity']);
            $subtotal  = $subtotal->plus($lineTotal);
            $vat       = $vat->plus($lineTotal->percentage((float) $line['vat_rate']));
        }

        if ($discount === null) {
            $current  = Database::scalar(
                'SELECT discount_amount FROM erp_invoices WHERE id = ? AND organization_id = ?',
                [$invoiceId, $organizationId],
            );
            $discount = (float) ($current ?? 0);
        }

        $discountMoney = Money::fromDecimal(max(0.0, $discount));

        // خصم يتجاوز قيمة البنود يُنتج فاتورة سالبة؛ يُقصَر على المجموع
        if ($discountMoney->greaterThan($subtotal)) {
            $discountMoney = $subtotal;
        }

        $total = $subtotal->minus($discountMoney)->plus($vat);

        Database::statement(
            'UPDATE erp_invoices
                SET subtotal = ?, discount_amount = ?, vat_amount = ?, total = ?, updated_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [
                $subtotal->toDecimal(),
                $discountMoney->toDecimal(),
                $vat->toDecimal(),
                $total->toDecimal(),
                $invoiceId,
                $organizationId,
            ],
        );
    }

    /** تطبيق خصم على المسودة | Apply a discount to the draft. */
    public function applyDiscount(int $invoiceId, int $organizationId, float $discount): void
    {
        $this->requireEditable($invoiceId, $organizationId);

        if ($discount < 0) {
            throw new HttpException(422, 'الخصم لا يكون سالباً.');
        }

        $this->recalculate($invoiceId, $organizationId, $discount);
    }

    // ═══════════════════ الإصدار والإلغاء | Issuing and cancelling ═══════════════════

    /**
     * إصدار الفاتورة | Issue the invoice.
     *
     * نقطة اللاعودة: بعدها لا تُحرَّر البنود، ويُصرف المخزون مقابل الأصناف
     * المتتبَّعة. الصرف عند الإصدار لا عند الإنشاء، لأن المسودة قد لا تُصدر
     * أصلاً وحجز مخزون لمسودة يُظهر نقصاً لم يحدث.
     */
    public function issue(int $invoiceId, int $organizationId, ?int $actorUserId, $request = null): void
    {
        Database::transaction(function () use ($invoiceId, $organizationId, $actorUserId, $request): void {
            $invoice = Database::selectOne(
                'SELECT * FROM erp_invoices
                  WHERE id = ? AND organization_id = ? AND deleted_at IS NULL
                  FOR UPDATE',
                [$invoiceId, $organizationId],
            );

            if ($invoice === null) {
                throw new HttpException(404, 'الفاتورة غير موجودة.');
            }

            if ((string) $invoice['status'] !== 'draft') {
                throw new HttpException(422, 'الفاتورة صادرة بالفعل ولا تُصدَر مرتين.');
            }

            $lines = Database::select(
                'SELECT * FROM erp_invoice_items WHERE invoice_id = ?',
                [$invoiceId],
            );

            if ($lines === []) {
                throw new HttpException(
                    422,
                    'أضف بنداً واحداً على الأقل قبل إصدار الفاتورة. فاتورة بلا بنود لا تصف بيعاً.',
                );
            }

            // صرف المخزون للأصناف المتتبَّعة؛ نقص أي صنف يُبطل الإصدار كله
            foreach ($lines as $line) {
                if ($line['item_id'] === null) {
                    continue;
                }

                $this->inventory->issueForSale(
                    itemId: (int) $line['item_id'],
                    organizationId: $organizationId,
                    quantity: (float) $line['quantity'],
                    invoiceId: $invoiceId,
                    actorUserId: $actorUserId,
                );
            }

            Database::statement(
                "UPDATE erp_invoices
                    SET status = 'issued', issued_at = NOW(), issued_by = ?, updated_at = NOW()
                  WHERE id = ?",
                [$actorUserId, $invoiceId],
            );

            $this->audit->setRequest($request);
            $this->audit->logStatusChange(
                'erp_invoice',
                $invoiceId,
                'draft',
                'issued',
                AuditLogger::CATEGORY_FINANCE,
                $organizationId,
            );
        });
    }

    /**
     * إلغاء فاتورة | Cancel an invoice.
     *
     * يستوجب سبباً مكتوباً، ويُعيد المخزون المصروف إن كانت صادرة. الإلغاء بلا
     * إرجاع كان سيترك نقصاً في المخزن مقابل بيع لم يتمّ.
     */
    public function cancel(
        int $invoiceId,
        int $organizationId,
        string $reason,
        ?int $actorUserId,
        $request = null,
    ): void {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new HttpException(
                422,
                'اكتب سبب الإلغاء. مستند يُلغى بلا سبب يترك سؤالاً بلا إجابة في الدفاتر.',
            );
        }

        Database::transaction(function () use ($invoiceId, $organizationId, $reason, $actorUserId, $request): void {
            $invoice = Database::selectOne(
                'SELECT * FROM erp_invoices
                  WHERE id = ? AND organization_id = ? AND deleted_at IS NULL
                  FOR UPDATE',
                [$invoiceId, $organizationId],
            );

            if ($invoice === null) {
                throw new HttpException(404, 'الفاتورة غير موجودة.');
            }

            $from = (string) $invoice['status'];

            if ($from === 'cancelled') {
                throw new HttpException(422, 'الفاتورة ملغاة بالفعل.');
            }

            // فاتورة قُبض جزء منها: الإلغاء يترك مبلغاً مستلماً بلا مستند
            if ((float) $invoice['amount_paid'] > 0) {
                throw new HttpException(
                    422,
                    'سُجّلت مقبوضات على هذه الفاتورة. احذف المقبوضات أولاً أو أصدر إشعار خصم؛ '
                    . 'إلغاؤها الآن يترك مبلغاً مستلماً بلا مستند يفسّره.',
                );
            }

            // إرجاع المخزون المصروف عند الإصدار
            if ($from !== 'draft') {
                $lines = Database::select(
                    'SELECT item_id, quantity FROM erp_invoice_items
                      WHERE invoice_id = ? AND item_id IS NOT NULL',
                    [$invoiceId],
                );

                foreach ($lines as $line) {
                    $item = Database::selectOne(
                        'SELECT track_stock FROM erp_items
                          WHERE id = ? AND organization_id = ? AND deleted_at IS NULL',
                        [(int) $line['item_id'], $organizationId],
                    );

                    if ($item === null || (int) $item['track_stock'] !== 1) {
                        continue;
                    }

                    $this->inventory->record(
                        itemId: (int) $line['item_id'],
                        organizationId: $organizationId,
                        type: 'return_in',
                        delta: (float) $line['quantity'],
                        actorUserId: $actorUserId,
                        reason: 'إرجاع مخزون بعد إلغاء الفاتورة ' . $invoice['invoice_number'],
                        referenceType: 'invoice',
                        referenceId: $invoiceId,
                    );
                }
            }

            Database::statement(
                "UPDATE erp_invoices
                    SET status = 'cancelled', cancelled_reason_ar = ?, cancelled_at = NOW(),
                        cancelled_by = ?, updated_at = NOW()
                  WHERE id = ?",
                [mb_substr($reason, 0, 500), $actorUserId, $invoiceId],
            );

            $this->audit->setRequest($request);
            $this->audit->logStatusChange(
                'erp_invoice',
                $invoiceId,
                $from,
                'cancelled',
                AuditLogger::CATEGORY_FINANCE,
                $organizationId,
            );
        });
    }

    // ═══════════════════ المقبوضات | Receipts ═══════════════════

    /**
     * تسجيل مقبوض | Record a receipt against an invoice.
     *
     * **تسجيل لا تحصيل.** المبلغ استلمه صاحب المشروع نقداً أو تحويلاً خارج
     * المنصة، والمنصة تدوّنه فقط (§14).
     *
     * @return array{id:int,number:string}
     */
    public function recordPayment(
        int $invoiceId,
        int $organizationId,
        array $data,
        ?int $actorUserId,
    ): array {
        $amount = $data['amount'] ?? null;

        if ($amount === null || !is_numeric($amount) || (float) $amount <= 0) {
            throw new HttpException(422, 'اكتب مبلغ المقبوض.');
        }

        $method = (string) ($data['method'] ?? 'cash');

        if (!in_array($method, ['cash', 'bank_transfer', 'cheque', 'wallet', 'other'], true)) {
            $method = 'cash';
        }

        $paidAt = $this->date($data['paid_at'] ?? null) ?? date('Y-m-d');

        return Database::transaction(function () use (
            $invoiceId, $organizationId, $amount, $method, $paidAt, $data, $actorUserId
        ): array {
            $invoice = Database::selectOne(
                'SELECT * FROM erp_invoices
                  WHERE id = ? AND organization_id = ? AND deleted_at IS NULL
                  FOR UPDATE',
                [$invoiceId, $organizationId],
            );

            if ($invoice === null) {
                throw new HttpException(404, 'الفاتورة غير موجودة.');
            }

            if (!in_array((string) $invoice['status'], self::COLLECTIBLE, true)) {
                throw new HttpException(
                    422,
                    (string) $invoice['status'] === 'draft'
                        ? 'أصدر الفاتورة قبل تسجيل مقبوضات عليها.'
                        : 'لا تُسجَّل مقبوضات على فاتورة بهذا الوضع.',
                );
            }

            $balance = Money::fromDecimal((float) $invoice['total'])
                ->minus(Money::fromDecimal((float) $invoice['amount_paid']));

            $payment = Money::fromDecimal((float) $amount);

            // المقبوض لا يتجاوز المتبقّي: الزيادة تجعل «المسدَّد» يتجاوز
            // «الإجمالي» فتظهر ذمم سالبة في التقارير.
            if ($payment->greaterThan($balance)) {
                throw new HttpException(
                    422,
                    'المبلغ أكبر من المتبقّي على الفاتورة (' . $balance->format() . ').',
                );
            }

            $result = DocumentNumber::withNumber(
                'erp_payments',
                'receipt_number',
                $organizationId,
                'REC',
                fn (string $number): int => Database::insert(
                    'INSERT INTO erp_payments
                        (organization_id, invoice_id, receipt_number, amount, paid_at,
                         method, reference_ar, notes_ar, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $organizationId,
                        $invoiceId,
                        $number,
                        $payment->toDecimal(),
                        $paidAt,
                        $method,
                        $this->text($data['reference_ar'] ?? null, 200),
                        $this->text($data['notes_ar'] ?? null, 500),
                        $actorUserId,
                    ],
                ),
            );

            $this->refreshPaidTotal($invoiceId, $organizationId);

            return $result;
        });
    }

    /** حذف مقبوض | Delete a receipt (corrects a mistaken entry). */
    public function deletePayment(int $paymentId, int $invoiceId, int $organizationId): void
    {
        Database::transaction(function () use ($paymentId, $invoiceId, $organizationId): void {
            $affected = Database::affectingStatement(
                'DELETE FROM erp_payments
                  WHERE id = ? AND invoice_id = ? AND organization_id = ?',
                [$paymentId, $invoiceId, $organizationId],
            );

            if ($affected === 0) {
                throw new HttpException(404, 'الإيصال غير موجود.');
            }

            $this->refreshPaidTotal($invoiceId, $organizationId);
        });
    }

    /**
     * تحديث المسدَّد وحالة السداد | Refresh the paid total and payment status.
     *
     * `amount_paid` **مشتقّ دائماً** من مجموع الإيصالات، ولا يُكتب من نموذج.
     * الحالة تتبع الرقم لا العكس.
     */
    private function refreshPaidTotal(int $invoiceId, int $organizationId): void
    {
        $invoice = Database::selectOne(
            'SELECT total, status FROM erp_invoices WHERE id = ? AND organization_id = ?',
            [$invoiceId, $organizationId],
        );

        if ($invoice === null) {
            return;
        }

        $paid  = Money::fromDecimal((float) Database::scalar(
            'SELECT COALESCE(SUM(amount), 0) FROM erp_payments WHERE invoice_id = ?',
            [$invoiceId],
        ));
        $total = Money::fromDecimal((float) $invoice['total']);

        $status = match (true) {
            (string) $invoice['status'] === 'cancelled' => 'cancelled',
            $paid->isZero()                             => 'issued',
            $paid->minorUnits() >= $total->minorUnits() => 'paid',
            default                                     => 'partially_paid',
        };

        Database::statement(
            'UPDATE erp_invoices SET amount_paid = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [$paid->toDecimal(), $status, $invoiceId],
        );
    }

    // ═══════════════════ أدوات | Helpers ═══════════════════

    /** @return array<string,mixed> */
    private function requireEditable(int $invoiceId, int $organizationId): array
    {
        $invoice = $this->invoices->findOwned($invoiceId, $organizationId);

        if ($invoice === null) {
            throw new HttpException(404, 'الفاتورة غير موجودة.');
        }

        if (!in_array((string) $invoice['status'], self::EDITABLE, true)) {
            throw new HttpException(
                422,
                'لا تُعدَّل فاتورة بعد إصدارها — العميل يحمل نسخة منها. '
                . 'للتصحيح ألغِ الفاتورة بسبب مكتوب وأصدر بديلاً.',
            );
        }

        return $invoice;
    }

    /**
     * تحديد العميل | Resolve the customer.
     *
     * الاسم يُنسخ في الفاتورة حتى لو كان العميل مسجَّلاً: تعديل اسم العميل
     * لاحقاً يجب ألّا يغيّر اسماً على مستند صدر.
     *
     * @param  array<string,mixed> $data
     * @return array{id:?int,name:string,phone:?string,tax_number:?string}
     */
    private function resolveCustomer(int $organizationId, array $data): array
    {
        $customerId = $this->nullableInt($data['customer_id'] ?? null);

        if ($customerId !== null) {
            $customer = Database::selectOne(
                'SELECT id, name_ar, phone, tax_number FROM crm_customers
                  WHERE id = ? AND organization_id = ? AND deleted_at IS NULL
                  LIMIT 1',
                [$customerId, $organizationId],
            );

            if ($customer === null) {
                throw new HttpException(404, 'العميل غير موجود في مشروعك.');
            }

            return [
                'id'         => (int) $customer['id'],
                'name'       => (string) $customer['name_ar'],
                'phone'      => $customer['phone'] === null ? null : (string) $customer['phone'],
                'tax_number' => $customer['tax_number'] === null ? null : (string) $customer['tax_number'],
            ];
        }

        $name = trim((string) ($data['customer_name_ar'] ?? ''));

        if ($name === '') {
            throw new HttpException(422, 'اختر عميلاً أو اكتب اسم المشتري.');
        }

        return [
            'id'         => null,
            'name'       => mb_substr($name, 0, 200),
            'phone'      => $this->text($data['customer_phone'] ?? null, 30),
            'tax_number' => $this->text($data['customer_tax_number'] ?? null, 30),
        ];
    }

    private function resolveOrder(int $organizationId, mixed $orderId): ?int
    {
        $orderId = $this->nullableInt($orderId);

        if ($orderId === null) {
            return null;
        }

        $owns = Database::scalar(
            'SELECT 1 FROM orders WHERE id = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1',
            [$orderId, $organizationId],
        );

        if ($owns === null) {
            throw new HttpException(404, 'الطلب المطلوب ربطه غير موجود في مشروعك.');
        }

        return $orderId;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (int) $value;
    }

    private function text(mixed $value, int $length): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : date('Y-m-d', $time);
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'          => 'مسودة',
            'issued'         => 'صادرة',
            'partially_paid' => 'مسدَّدة جزئياً',
            'paid'           => 'مسدَّدة',
            'cancelled'      => 'ملغاة',
            default          => $status,
        };
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'draft'          => 'np-badge--draft',
            'issued'         => 'np-badge--info',
            'partially_paid' => 'np-badge--warning',
            'paid'           => 'np-badge--success',
            'cancelled'      => 'np-badge--muted',
            default          => 'np-badge--draft',
        };
    }
}
