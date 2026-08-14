<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Support\Money;

/**
 * عروض الأسعار | Request-for-quotation workflow (§4.4).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *   requested ──quote──► quoted ──accept──► accepted ──► order
 *        │                  │
 *        └──withdraw        ├──reject──► rejected
 *                           └──(انتهاء الصلاحية)──► expired
 *
 * القبول يُنشئ طلباً كامل البيانات دون إعادة إدخال — لأن مطالبة العميل بكتابة
 * بياناته مرة أخرى بعد التفاوض هي أكثر نقطة يُهجر عندها الشراء.
 * Acceptance creates a fully-populated order without re-entry: asking the
 * customer to retype everything after negotiating is where deals get dropped.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class QuotationService
{
    public function __construct(
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * طلب عرض سعر من العميل | Customer requests a quotation.
     *
     * @param  array<string,mixed> $data
     * @return array{id:int,number:string,token:string}
     */
    public function request(int $organizationId, ?int $listingId, array $data, Request $request): array
    {
        $organization = Database::selectOne(
            "SELECT * FROM organizations WHERE id = ? AND status = 'verified' AND deleted_at IS NULL",
            [$organizationId],
        );

        if ($organization === null) {
            throw new HttpException(404, 'المنشأة المطلوبة غير متاحة.');
        }

        $number = 'RFQ-' . date('ym') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $token  = bin2hex(random_bytes(24));

        $quotationId = Database::insert(
            'INSERT INTO quotations
                (quotation_number, organization_id, listing_id, customer_user_id,
                 customer_name, customer_phone, customer_email, governorate_id,
                 request_details, requested_quantity, needed_by, status, tracking_token)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $number,
                $organizationId,
                $listingId,
                $data['customer_user_id'] ?? null,
                mb_substr((string) $data['customer_name'], 0, 150),
                mb_substr((string) $data['customer_phone'], 0, 30),
                $data['customer_email'] ?? null,
                $data['governorate_id'] ?? null,
                mb_substr((string) $data['request_details'], 0, 2000),
                $data['requested_quantity'] ?? null,
                $data['needed_by'] ?? null,
                'requested',
                $token,
            ],
        );

        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'quotation.requested',
            category: AuditLogger::CATEGORY_ORDER,
            entityType: 'quotation',
            entityId: $quotationId,
            description: 'طلب عرض سعر جديد: ' . $number,
            userId: $data['customer_user_id'] ?? null,
            organizationId: $organizationId,
        );

        $this->notifications->notifyOrganizationMembers(
            organizationId: $organizationId,
            type: 'quotation.requested',
            title: 'طلب عرض سعر جديد',
            body: 'وصل طلب عرض سعر من ' . $data['customer_name'] . '.',
            severity: 'info',
            actionUrl: url('/app/quotations/' . $quotationId),
            actionLabel: 'عرض الطلب',
            entityType: 'quotation',
            entityId: $quotationId,
        );

        return ['id' => $quotationId, 'number' => $number, 'token' => $token];
    }

    /**
     * تقديم عرض السعر من البائع | Seller submits the quote.
     *
     * @param array<int,array<string,mixed>> $items
     */
    public function submitQuote(
        int $quotationId,
        int $organizationId,
        array $items,
        array $terms,
        ?int $actorId,
        Request $request,
    ): void {
        Database::transaction(function () use ($quotationId, $organizationId, $items, $terms, $actorId, $request): void {
            $quotation = Database::selectOne(
                'SELECT * FROM quotations
                  WHERE id = ? AND organization_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$quotationId, $organizationId],
            );

            if ($quotation === null) {
                throw new HttpException(404, 'طلب عرض السعر غير موجود.');
            }

            if (!in_array($quotation['status'], ['requested', 'quoted'], true)) {
                throw new HttpException(422, 'لا يمكن تعديل عرض سعر في حالته الحالية.');
            }

            $validItems = array_values(array_filter(
                $items,
                static fn (array $item): bool =>
                    trim((string) ($item['description'] ?? '')) !== ''
                    && is_numeric($item['unit_price'] ?? null),
            ));

            if ($validItems === []) {
                throw new HttpException(422, 'يجب إضافة بند واحد على الأقل بسعر صحيح.');
            }

            Database::statement('DELETE FROM quotation_items WHERE quotation_id = ?', [$quotationId]);

            $subtotal = Money::zero();
            $vatTotal = Money::zero();

            foreach ($validItems as $index => $item) {
                $quantity  = max(0.001, (float) ($item['quantity'] ?? 1));
                $unitPrice = Money::fromDecimal((string) $item['unit_price']);
                $vatRate   = (float) ($item['vat_rate'] ?? 0);

                $lineSubtotal = $unitPrice->times($quantity);
                $lineVat      = $lineSubtotal->percentage($vatRate);

                $subtotal = $subtotal->plus($lineSubtotal);
                $vatTotal = $vatTotal->plus($lineVat);

                Database::statement(
                    'INSERT INTO quotation_items
                        (quotation_id, organization_id, listing_id, description, quantity,
                         unit_of_measure, unit_price, vat_rate, line_total, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $quotationId, $organizationId, $quotation['listing_id'],
                        mb_substr((string) $item['description'], 0, 300),
                        $quantity,
                        $item['unit_of_measure'] ?? null,
                        $unitPrice->toDecimal(),
                        $vatRate,
                        $lineSubtotal->plus($lineVat)->toDecimal(),
                        $index,
                    ],
                );
            }

            $deliveryFee = Money::fromDecimal((string) ($terms['delivery_fee'] ?? 0));
            $total       = $subtotal->plus($vatTotal)->plus($deliveryFee);

            Database::statement(
                "UPDATE quotations
                    SET status = 'quoted', subtotal = ?, vat_amount = ?, delivery_fee = ?,
                        total = ?, lead_time_days = ?, terms = ?, valid_until = ?,
                        quoted_by = ?, quoted_at = NOW()
                  WHERE id = ? AND organization_id = ?",
                [
                    $subtotal->toDecimal(), $vatTotal->toDecimal(), $deliveryFee->toDecimal(),
                    $total->toDecimal(),
                    $terms['lead_time_days'] ?? null,
                    isset($terms['terms']) ? mb_substr((string) $terms['terms'], 0, 2000) : null,
                    $terms['valid_until'] ?? null,
                    $actorId, $quotationId, $organizationId,
                ],
            );

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'quotation.quoted',
                category: AuditLogger::CATEGORY_ORDER,
                entityType: 'quotation',
                entityId: $quotationId,
                description: 'تقديم عرض سعر بقيمة ' . $total->format(),
                userId: $actorId,
                organizationId: $organizationId,
            );

            if ($quotation['customer_user_id'] !== null) {
                $this->notifications->notify(
                    userId: (int) $quotation['customer_user_id'],
                    type: 'quotation.quoted',
                    title: 'وصلك عرض سعر',
                    body: 'قدّمت المنشأة عرض سعر بقيمة ' . $total->format() . '.',
                    severity: 'success',
                    actionUrl: url('/quotations/track/' . $quotation['tracking_token']),
                    actionLabel: 'عرض السعر',
                    organizationId: $organizationId,
                    entityType: 'quotation',
                    entityId: $quotationId,
                );
            }
        });
    }

    /**
     * ردّ العميل على العرض | Customer accepts or rejects.
     *
     * القبول يحوّل العرض إلى طلب بحالة «مؤكَّد» مباشرة: العميل والبائع اتفقا
     * على السعر والشروط، فلا معنى لإعادة الطلب إلى حالة «جديد» للتأكيد.
     * Acceptance creates an order already in `confirmed`: both sides have
     * agreed on price and terms, so re-confirming would be theatre.
     *
     * @return int|null معرّف الطلب عند القبول
     */
    public function respond(string $trackingToken, string $decision, ?int $actorUserId, Request $request): ?int
    {
        if (!in_array($decision, ['accept', 'reject'], true)) {
            throw new HttpException(422, 'قرار غير معروف.');
        }

        // انتهاء الصلاحية يُسجَّل في معاملة مستقلة ثم يُرمى الخطأ بعدها.
        // لو رُمي الخطأ داخل نفس المعاملة لتراجع تسجيل الانتهاء معه، فيبقى
        // العرض «مقدَّماً» في قاعدة البيانات رغم انقضاء أجله.
        // Expiry is committed in its own transaction, then the error is thrown.
        // Throwing inside the same transaction would roll the expiry back and
        // leave a lapsed quote sitting in `quoted` forever.
        if ($this->expireIfLapsed($trackingToken)) {
            throw new HttpException(422, 'انتهت صلاحية عرض السعر. يمكنك طلب عرض جديد.');
        }

        return Database::transaction(function () use ($trackingToken, $decision, $actorUserId, $request): ?int {
            $quotation = Database::selectOne(
                'SELECT * FROM quotations WHERE tracking_token = ? AND deleted_at IS NULL FOR UPDATE',
                [$trackingToken],
            );

            if ($quotation === null) {
                throw new HttpException(404, 'عرض السعر غير موجود.');
            }

            if ($quotation['status'] !== 'quoted') {
                throw new HttpException(422, 'لا يمكن الرد على هذا العرض في حالته الحالية.');
            }

            if ($decision === 'reject') {
                Database::statement(
                    "UPDATE quotations SET status = 'rejected', responded_at = NOW() WHERE id = ?",
                    [(int) $quotation['id']],
                );

                $this->notifications->notifyOrganizationMembers(
                    organizationId: (int) $quotation['organization_id'],
                    type: 'quotation.rejected',
                    title: 'لم يُقبل عرض السعر ' . $quotation['quotation_number'],
                    body: 'اعتذر العميل عن عرض السعر المقدَّم.',
                    severity: 'warning',
                    actionUrl: url('/app/quotations/' . $quotation['id']),
                    entityType: 'quotation',
                    entityId: (int) $quotation['id'],
                );

                return null;
            }

            $orderId = $this->convertToOrder($quotation, $actorUserId);

            Database::statement(
                "UPDATE quotations
                    SET status = 'accepted', responded_at = NOW(), converted_order_id = ?
                  WHERE id = ?",
                [$orderId, (int) $quotation['id']],
            );

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'quotation.accepted',
                category: AuditLogger::CATEGORY_ORDER,
                entityType: 'quotation',
                entityId: (int) $quotation['id'],
                description: 'قبول عرض السعر وتحويله إلى الطلب رقم ' . $orderId,
                userId: $actorUserId,
                organizationId: (int) $quotation['organization_id'],
            );

            $this->notifications->notifyOrganizationMembers(
                organizationId: (int) $quotation['organization_id'],
                type: 'quotation.accepted',
                title: 'تم قبول عرض السعر ' . $quotation['quotation_number'],
                body: 'قبل العميل العرض وتحوّل إلى طلب مؤكَّد.',
                severity: 'success',
                actionUrl: url('/app/orders/' . $orderId),
                actionLabel: 'عرض الطلب',
                entityType: 'order',
                entityId: $orderId,
            );

            return $orderId;
        });
    }

    /** تحويل عرض سعر مقبول إلى طلب | Convert an accepted quotation into an order. */
    /**
     * وسم العرض المنقضي | Mark a lapsed quote as expired.
     *
     * @return bool هل كان العرض منقضياً؟
     */
    private function expireIfLapsed(string $trackingToken): bool
    {
        $quotation = Database::selectOne(
            "SELECT id, valid_until FROM quotations
              WHERE tracking_token = ? AND status = 'quoted' AND deleted_at IS NULL
              LIMIT 1",
            [$trackingToken],
        );

        if ($quotation === null || $quotation['valid_until'] === null) {
            return false;
        }

        if (strtotime((string) $quotation['valid_until']) >= strtotime('today')) {
            return false;
        }

        Database::statement(
            "UPDATE quotations SET status = 'expired' WHERE id = ? AND status = 'quoted'",
            [(int) $quotation['id']],
        );

        return true;
    }

    private function convertToOrder(array $quotation, ?int $actorUserId): int
    {
        $organizationId = (int) $quotation['organization_id'];

        $orderId = Database::insert(
            'INSERT INTO orders
                (order_number, organization_id, customer_user_id, customer_name,
                 customer_phone, customer_email, governorate_id, tracking_token,
                 status, subtotal, vat_amount, delivery_fee, total, currency_code,
                 source, quotation_id, confirmed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                'NP-' . date('ym') . '-' . strtoupper(bin2hex(random_bytes(3))),
                $organizationId,
                $quotation['customer_user_id'],
                $quotation['customer_name'],
                $quotation['customer_phone'],
                $quotation['customer_email'],
                $quotation['governorate_id'],
                bin2hex(random_bytes(24)),
                'confirmed',
                $quotation['subtotal'] ?? '0.00',
                $quotation['vat_amount'] ?? '0.00',
                $quotation['delivery_fee'] ?? '0.00',
                $quotation['total'] ?? '0.00',
                $quotation['currency_code'] ?? 'EGP',
                'quotation',
                (int) $quotation['id'],
            ],
        );

        $items = Database::select(
            'SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order ASC',
            [(int) $quotation['id']],
        );

        foreach ($items as $item) {
            $unitPrice    = Money::fromDecimal((string) $item['unit_price']);
            $lineSubtotal = $unitPrice->times((float) $item['quantity']);
            $lineVat      = $lineSubtotal->percentage((float) $item['vat_rate']);

            Database::statement(
                'INSERT INTO order_items
                    (order_id, organization_id, listing_id, name_ar, unit_of_measure,
                     quantity, unit_price, vat_rate, line_subtotal, line_vat, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $orderId, $organizationId, $item['listing_id'],
                    mb_substr((string) $item['description'], 0, 200),
                    $item['unit_of_measure'],
                    $item['quantity'],
                    $unitPrice->toDecimal(),
                    $item['vat_rate'],
                    $lineSubtotal->toDecimal(),
                    $lineVat->toDecimal(),
                    $lineSubtotal->plus($lineVat)->toDecimal(),
                ],
            );
        }

        Database::statement(
            'INSERT INTO order_status_history
                (order_id, organization_id, from_status, to_status, actor_user_id, actor_type, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $orderId, $organizationId, null, 'confirmed', $actorUserId, 'customer',
                'إنشاء الطلب من عرض سعر مقبول رقم ' . $quotation['quotation_number'],
            ],
        );

        return $orderId;
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'requested' => 'بانتظار عرض السعر',
            'quoted'    => 'تم تقديم العرض',
            'accepted'  => 'مقبول',
            'rejected'  => 'مرفوض',
            'expired'   => 'منتهي الصلاحية',
            'withdrawn' => 'مسحوب',
            default     => $status,
        };
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'requested' => 'np-badge--pending',
            'quoted'    => 'np-badge--info',
            'accepted'  => 'np-badge--success',
            'rejected', 'expired', 'withdrawn' => 'np-badge--muted',
            default     => 'np-badge--draft',
        };
    }
}
