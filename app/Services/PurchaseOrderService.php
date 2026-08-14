<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Support\DocumentNumber;
use App\Support\Money;

/**
 * خدمة أوامر الشراء والموردين | Purchase order and supplier service (§4.10).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **القاعدة الحاكمة: الاستلام هو ما يزيد المخزون، لا إصدار الأمر.**
 *
 * أمر شراء أُرسل للمورّد ولم تصل بضاعته ليس بضاعة في المخزن. زيادة الرصيد عند
 * الإرسال كانت ستجعل صاحب المشروع يبيع ما لم يستلمه، ويكتشف النقص عند التسليم
 * لا قبله.
 *
 * والاستلام **جزئي بطبيعته**: المورّد يرسل دفعة ثم دفعة، فيتراكم المستلم على
 * البند ولا يتجاوز المطلوب.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class PurchaseOrderService
{
    /** الحالات التي تقبل التحرير | Statuses that accept editing. */
    private const EDITABLE = ['draft'];

    /** الحالات التي تقبل الاستلام | Statuses that accept receiving. */
    private const RECEIVABLE = ['sent', 'partially_received'];

    public function __construct(
        private readonly InventoryService $inventory = new InventoryService(),
    ) {
    }

    // ═══════════════════ الموردون | Suppliers ═══════════════════

    /** @param array<string,mixed> $data */
    public function createSupplier(int $organizationId, array $data): int
    {
        $payload = $this->validateSupplier($organizationId, $data, null);

        return Database::insert(
            'INSERT INTO erp_suppliers
                (organization_id, code, name_ar, contact_person_ar, phone, email,
                 governorate_id, address, tax_number, payment_terms_ar, status, notes_ar)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $organizationId,
                $payload['code'],
                $payload['name_ar'],
                $payload['contact_person_ar'],
                $payload['phone'],
                $payload['email'],
                $payload['governorate_id'],
                $payload['address'],
                $payload['tax_number'],
                $payload['payment_terms_ar'],
                $payload['status'],
                $payload['notes_ar'],
            ],
        );
    }

    /** @param array<string,mixed> $data */
    public function updateSupplier(int $supplierId, int $organizationId, array $data): void
    {
        $this->requireSupplier($supplierId, $organizationId);
        $payload = $this->validateSupplier($organizationId, $data, $supplierId);

        Database::statement(
            'UPDATE erp_suppliers
                SET code = ?, name_ar = ?, contact_person_ar = ?, phone = ?, email = ?,
                    governorate_id = ?, address = ?, tax_number = ?, payment_terms_ar = ?,
                    status = ?, notes_ar = ?, updated_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [
                $payload['code'],
                $payload['name_ar'],
                $payload['contact_person_ar'],
                $payload['phone'],
                $payload['email'],
                $payload['governorate_id'],
                $payload['address'],
                $payload['tax_number'],
                $payload['payment_terms_ar'],
                $payload['status'],
                $payload['notes_ar'],
                $supplierId,
                $organizationId,
            ],
        );
    }

    public function archiveSupplier(int $supplierId, int $organizationId): void
    {
        $this->requireSupplier($supplierId, $organizationId);

        $open = (int) Database::scalar(
            "SELECT COUNT(*) FROM erp_purchase_orders
              WHERE supplier_id = ? AND organization_id = ? AND deleted_at IS NULL
                AND status IN ('sent','partially_received')",
            [$supplierId, $organizationId],
        );

        if ($open > 0) {
            throw new HttpException(
                422,
                'لهذا المورّد أوامر شراء لم تُستكمل. أغلقها أو ألغِها قبل الأرشفة.',
            );
        }

        Database::statement(
            "UPDATE erp_suppliers SET deleted_at = NOW(), status = 'inactive'
              WHERE id = ? AND organization_id = ?",
            [$supplierId, $organizationId],
        );
    }

    // ═══════════════════ أوامر الشراء | Purchase orders ═══════════════════

    /**
     * @param  array<string,mixed> $data
     * @return array{id:int,number:string}
     */
    public function create(int $organizationId, array $data, ?int $actorUserId): array
    {
        $supplierId = $this->requireSupplierId($organizationId, $data['supplier_id'] ?? null);
        $orderDate  = $this->date($data['order_date'] ?? null) ?? date('Y-m-d');
        $expected   = $this->date($data['expected_date'] ?? null);

        if ($expected !== null && $expected < $orderDate) {
            throw new HttpException(422, 'تاريخ التوريد المتوقّع قبل تاريخ الأمر.');
        }

        return Database::transaction(fn (): array => DocumentNumber::withNumber(
            'erp_purchase_orders',
            'po_number',
            $organizationId,
            'PO',
            fn (string $number): int => Database::insert(
                'INSERT INTO erp_purchase_orders
                    (organization_id, supplier_id, po_number, order_date, expected_date,
                     status, notes_ar, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $organizationId,
                    $supplierId,
                    $number,
                    $orderDate,
                    $expected,
                    'draft',
                    $this->text($data['notes_ar'] ?? null, 1000),
                    $actorUserId,
                ],
            ),
        ));
    }

    /**
     * إضافة بند | Add a line.
     *
     * @param array<string,mixed> $data
     */
    public function addLine(int $poId, int $organizationId, array $data): int
    {
        $this->requireEditable($poId, $organizationId);

        $quantity = (float) ($data['quantity_ordered'] ?? 0);

        if ($quantity <= 0) {
            throw new HttpException(422, 'كمية البند يجب أن تكون أكبر من صفر.');
        }

        $itemId = $data['item_id'] ?? null;

        if ($itemId === null || $itemId === '' || !is_numeric($itemId)) {
            throw new HttpException(422, 'اختر الصنف المطلوب شراؤه.');
        }

        $item = Database::selectOne(
            'SELECT id, name_ar, unit_of_measure, cost_price, vat_rate
               FROM erp_items
              WHERE id = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1',
            [(int) $itemId, $organizationId],
        );

        if ($item === null) {
            throw new HttpException(404, 'الصنف غير موجود في مشروعك.');
        }

        $unitCost = $data['unit_cost'] ?? null;

        if ($unitCost === null || $unitCost === '') {
            $unitCost = $item['cost_price'] ?? null;
        }

        if ($unitCost === null || !is_numeric($unitCost) || (float) $unitCost < 0) {
            throw new HttpException(422, 'اكتب تكلفة الوحدة.');
        }

        $lineTotal = Money::fromDecimal((float) $unitCost)->times($quantity);

        $order = (int) Database::scalar(
            'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM erp_purchase_order_items WHERE purchase_order_id = ?',
            [$poId],
        );

        $lineId = Database::insert(
            'INSERT INTO erp_purchase_order_items
                (purchase_order_id, organization_id, item_id, name_ar, unit_of_measure,
                 quantity_ordered, unit_cost, vat_rate, line_total, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $poId,
                $organizationId,
                (int) $itemId,
                (string) $item['name_ar'],
                $item['unit_of_measure'],
                $quantity,
                (float) $unitCost,
                (float) ($data['vat_rate'] ?? $item['vat_rate'] ?? 0),
                $lineTotal->toDecimal(),
                $order,
            ],
        );

        $this->recalculate($poId, $organizationId);

        return $lineId;
    }

    public function removeLine(int $poId, int $organizationId, int $lineId): void
    {
        $this->requireEditable($poId, $organizationId);

        Database::statement(
            'DELETE FROM erp_purchase_order_items
              WHERE id = ? AND purchase_order_id = ? AND organization_id = ?',
            [$lineId, $poId, $organizationId],
        );

        $this->recalculate($poId, $organizationId);
    }

    /** إعادة حساب المجاميع | Recalculate the totals from the lines. */
    public function recalculate(int $poId, int $organizationId): void
    {
        $lines = Database::select(
            'SELECT quantity_ordered, unit_cost, vat_rate
               FROM erp_purchase_order_items WHERE purchase_order_id = ?',
            [$poId],
        );

        $subtotal = Money::zero();
        $vat      = Money::zero();

        foreach ($lines as $line) {
            $lineTotal = Money::fromDecimal((float) $line['unit_cost'])
                ->times((float) $line['quantity_ordered']);
            $subtotal  = $subtotal->plus($lineTotal);
            $vat       = $vat->plus($lineTotal->percentage((float) $line['vat_rate']));
        }

        Database::statement(
            'UPDATE erp_purchase_orders
                SET subtotal = ?, vat_amount = ?, total = ?, updated_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [
                $subtotal->toDecimal(),
                $vat->toDecimal(),
                $subtotal->plus($vat)->toDecimal(),
                $poId,
                $organizationId,
            ],
        );
    }

    /** إرسال الأمر للمورّد | Send the order to the supplier. */
    public function send(int $poId, int $organizationId): void
    {
        $po = $this->requireEditable($poId, $organizationId);

        $lineCount = (int) Database::scalar(
            'SELECT COUNT(*) FROM erp_purchase_order_items WHERE purchase_order_id = ?',
            [$poId],
        );

        if ($lineCount === 0) {
            throw new HttpException(422, 'أضف بنداً واحداً على الأقل قبل إرسال أمر الشراء.');
        }

        Database::statement(
            "UPDATE erp_purchase_orders
                SET status = 'sent', sent_at = NOW(), updated_at = NOW()
              WHERE id = ? AND organization_id = ?",
            [$poId, $organizationId],
        );
    }

    /**
     * تسجيل استلام | Record a receipt of goods.
     *
     * **هنا وحده يزيد المخزون.** كل بند مستلم يُنتج حركة `purchase` بتكلفتها،
     * فيبقى الدفتر مفسِّراً لكل وحدة دخلت.
     *
     * @param array<int,array{line_id:int,quantity:float}> $received
     */
    public function receive(int $poId, int $organizationId, array $received, ?int $actorUserId): void
    {
        Database::transaction(function () use ($poId, $organizationId, $received, $actorUserId): void {
            $po = Database::selectOne(
                'SELECT * FROM erp_purchase_orders
                  WHERE id = ? AND organization_id = ? AND deleted_at IS NULL
                  FOR UPDATE',
                [$poId, $organizationId],
            );

            if ($po === null) {
                throw new HttpException(404, 'أمر الشراء غير موجود.');
            }

            if (!in_array((string) $po['status'], self::RECEIVABLE, true)) {
                throw new HttpException(
                    422,
                    (string) $po['status'] === 'draft'
                        ? 'أرسل أمر الشراء للمورّد قبل تسجيل الاستلام.'
                        : 'لا يُسجَّل استلام على أمر بهذا الوضع.',
                );
            }

            $anyReceived = false;

            foreach ($received as $entry) {
                $lineId   = (int) ($entry['line_id'] ?? 0);
                $quantity = (float) ($entry['quantity'] ?? 0);

                if ($lineId === 0 || $quantity <= 0) {
                    continue;
                }

                $line = Database::selectOne(
                    'SELECT * FROM erp_purchase_order_items
                      WHERE id = ? AND purchase_order_id = ? AND organization_id = ?
                      FOR UPDATE',
                    [$lineId, $poId, $organizationId],
                );

                if ($line === null) {
                    throw new HttpException(404, 'بند أمر الشراء غير موجود.');
                }

                $outstanding = round(
                    (float) $line['quantity_ordered'] - (float) $line['quantity_received'],
                    3,
                );

                // استلام أكثر من المطلوب يجعل الأمر يصف واقعاً لم يُتَّفق عليه
                if ($quantity - $outstanding > 0.0001) {
                    throw new HttpException(
                        422,
                        'الكمية المستلمة من «' . $line['name_ar'] . '» أكبر من المتبقّي في الأمر ('
                        . rtrim(rtrim(number_format($outstanding, 3), '0'), '.') . ').',
                    );
                }

                $this->inventory->record(
                    itemId: (int) $line['item_id'],
                    organizationId: $organizationId,
                    type: 'purchase',
                    delta: $quantity,
                    actorUserId: $actorUserId,
                    unitCost: (float) $line['unit_cost'],
                    referenceType: 'purchase_order',
                    referenceId: $poId,
                );

                Database::statement(
                    'UPDATE erp_purchase_order_items
                        SET quantity_received = quantity_received + ?
                      WHERE id = ?',
                    [$quantity, $lineId],
                );

                $anyReceived = true;
            }

            if (!$anyReceived) {
                throw new HttpException(422, 'اكتب الكميات المستلمة.');
            }

            $this->refreshReceiptStatus($poId, $organizationId);
        });
    }

    /**
     * تحديث حالة الاستلام | Refresh the receipt status.
     *
     * الحالة تتبع البنود لا العكس: أمر تُستلم كل بنوده يصير «مستلماً» تلقائياً،
     * فلا يبقى أمر مكتمل معلّقاً في القوائم بانتظار ضغطة.
     */
    private function refreshReceiptStatus(int $poId, int $organizationId): void
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS total_lines,
                    SUM(CASE WHEN quantity_received >= quantity_ordered THEN 1 ELSE 0 END) AS complete_lines,
                    SUM(CASE WHEN quantity_received > 0 THEN 1 ELSE 0 END) AS started_lines
               FROM erp_purchase_order_items
              WHERE purchase_order_id = ?',
            [$poId],
        );

        $total    = (int) ($row['total_lines'] ?? 0);
        $complete = (int) ($row['complete_lines'] ?? 0);
        $started  = (int) ($row['started_lines'] ?? 0);

        $status = match (true) {
            $total > 0 && $complete === $total => 'received',
            $started > 0                       => 'partially_received',
            default                            => 'sent',
        };

        Database::statement(
            'UPDATE erp_purchase_orders
                SET status = ?,
                    received_at = ' . ($status === 'received' ? 'NOW()' : 'NULL') . ',
                    updated_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [$status, $poId, $organizationId],
        );
    }

    /**
     * إلغاء أمر شراء | Cancel a purchase order.
     *
     * لا يُلغى أمر استُلم جزء منه: البضاعة دخلت المخزن فعلاً، وإلغاء الأمر
     * يترك مخزوناً بلا مستند يفسّر مصدره.
     */
    public function cancel(int $poId, int $organizationId, string $reason): void
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new HttpException(422, 'اكتب سبب إلغاء أمر الشراء.');
        }

        $po = $this->requirePurchaseOrder($poId, $organizationId);

        if ((string) $po['status'] === 'cancelled') {
            throw new HttpException(422, 'أمر الشراء ملغى بالفعل.');
        }

        $receivedLines = (int) Database::scalar(
            'SELECT COUNT(*) FROM erp_purchase_order_items
              WHERE purchase_order_id = ? AND quantity_received > 0',
            [$poId],
        );

        if ($receivedLines > 0) {
            throw new HttpException(
                422,
                'استُلم جزء من هذا الأمر بالفعل. إلغاؤه يترك مخزوناً بلا مستند يفسّر مصدره.',
            );
        }

        Database::statement(
            "UPDATE erp_purchase_orders
                SET status = 'cancelled', cancelled_reason_ar = ?, updated_at = NOW()
              WHERE id = ? AND organization_id = ?",
            [mb_substr($reason, 0, 500), $poId, $organizationId],
        );
    }

    // ═══════════════════ أدوات | Helpers ═══════════════════

    /** @return array<string,mixed> */
    private function requireEditable(int $poId, int $organizationId): array
    {
        $po = $this->requirePurchaseOrder($poId, $organizationId);

        if (!in_array((string) $po['status'], self::EDITABLE, true)) {
            throw new HttpException(
                422,
                'لا تُعدَّل بنود أمر شراء بعد إرساله للمورّد. ألغِ الأمر وأصدر بديلاً إن تغيّر الطلب.',
            );
        }

        return $po;
    }

    /** @return array<string,mixed> */
    private function requirePurchaseOrder(int $poId, int $organizationId): array
    {
        $po = Database::selectOne(
            'SELECT * FROM erp_purchase_orders
              WHERE id = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1',
            [$poId, $organizationId],
        );

        if ($po === null) {
            throw new HttpException(404, 'أمر الشراء غير موجود.');
        }

        return $po;
    }

    /** @return array<string,mixed> */
    private function requireSupplier(int $supplierId, int $organizationId): array
    {
        $supplier = Database::selectOne(
            'SELECT * FROM erp_suppliers
              WHERE id = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1',
            [$supplierId, $organizationId],
        );

        if ($supplier === null) {
            throw new HttpException(404, 'المورّد غير موجود.');
        }

        return $supplier;
    }

    private function requireSupplierId(int $organizationId, mixed $supplierId): int
    {
        if ($supplierId === null || $supplierId === '' || !is_numeric($supplierId)) {
            throw new HttpException(422, 'اختر المورّد.');
        }

        $this->requireSupplier((int) $supplierId, $organizationId);

        return (int) $supplierId;
    }

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function validateSupplier(int $organizationId, array $data, ?int $exceptId): array
    {
        $name = trim((string) ($data['name_ar'] ?? ''));

        if ($name === '') {
            throw new HttpException(422, 'اكتب اسم المورّد.');
        }

        $code = trim((string) ($data['code'] ?? ''));

        if ($code === '') {
            $count = (int) Database::scalar(
                'SELECT COUNT(*) FROM erp_suppliers WHERE organization_id = ?',
                [$organizationId],
            );
            $code = 'SUP-' . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
        }

        $sql      = 'SELECT 1 FROM erp_suppliers WHERE organization_id = ? AND code = ? AND deleted_at IS NULL';
        $bindings = [$organizationId, $code];

        if ($exceptId !== null) {
            $sql       .= ' AND id <> ?';
            $bindings[] = $exceptId;
        }

        if (Database::scalar($sql . ' LIMIT 1', $bindings) !== null) {
            throw new HttpException(422, 'كود المورّد مستخدم بالفعل. اختر كوداً آخر.');
        }

        $status = (string) ($data['status'] ?? 'active');

        return [
            'code'              => mb_substr($code, 0, 30),
            'name_ar'           => mb_substr($name, 0, 200),
            'contact_person_ar' => $this->text($data['contact_person_ar'] ?? null, 150),
            'phone'             => $this->text($data['phone'] ?? null, 30),
            'email'             => $this->text($data['email'] ?? null, 190),
            'governorate_id'    => $this->nullableInt($data['governorate_id'] ?? null),
            'address'           => $this->text($data['address'] ?? null, 500),
            'tax_number'        => $this->text($data['tax_number'] ?? null, 30),
            'payment_terms_ar'  => $this->text($data['payment_terms_ar'] ?? null, 200),
            'status'            => in_array($status, ['active', 'inactive'], true) ? $status : 'active',
            'notes_ar'          => $this->text($data['notes_ar'] ?? null, 1000),
        ];
    }

    private function text(mixed $value, int $length): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (int) $value;
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
            'draft'              => 'مسودة',
            'sent'               => 'مُرسَل للمورّد',
            'partially_received' => 'مستلَم جزئياً',
            'received'           => 'مستلَم بالكامل',
            'cancelled'          => 'ملغى',
            default              => $status,
        };
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'draft'              => 'np-badge--draft',
            'sent'               => 'np-badge--info',
            'partially_received' => 'np-badge--warning',
            'received'           => 'np-badge--success',
            'cancelled'          => 'np-badge--muted',
            default              => 'np-badge--draft',
        };
    }
}
