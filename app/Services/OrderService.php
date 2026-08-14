<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Support\Money;

/**
 * الطلبات | Order service (§4.4).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * آلة حالة الطلب — عشر حالات، والانتقالات المسموحة فقط:
 *
 *   new ──confirm──► confirmed ──prepare──► preparing ──ready──► ready
 *                                                                  │
 *                          shipped ◄──ship──────────────────────────┘
 *                             │
 *                             └──deliver──► delivered ──complete──► completed
 *
 *   الإلغاء متاح من: new · confirmed · preparing
 *   النزاع متاح من:  shipped · delivered · completed
 *   الاسترداد متاح من: disputed · cancelled (بعد دفع مؤكَّد)
 *
 * لماذا لا يُلغى طلب شُحن؟ لأن البضاعة غادرت البائع فعلاً؛ الحالة الصحيحة حينها
 * نزاع يُحلّ ثم استرداد، لا إلغاء يمحو الأثر.
 * Why can't a shipped order be cancelled? The goods have physically left the
 * seller. The honest path is dispute → resolution → refund, not a cancellation
 * that erases the trail.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class OrderService
{
    /** @var array<string,array<int,string>> */
    private const TRANSITIONS = [
        'new'       => ['confirm', 'cancel'],
        'confirmed' => ['prepare', 'cancel'],
        'preparing' => ['ready', 'cancel'],
        'ready'     => ['ship', 'deliver'],
        'shipped'   => ['deliver', 'dispute'],
        'delivered' => ['complete', 'dispute'],
        'completed' => ['dispute'],
        'cancelled' => ['refund'],
        'disputed'  => ['resolve', 'refund'],
        'refunded'  => [],
    ];

    /** @var array<string,string> */
    private const RESULTING_STATUS = [
        'confirm'  => 'confirmed',
        'prepare'  => 'preparing',
        'ready'    => 'ready',
        'ship'     => 'shipped',
        'deliver'  => 'delivered',
        'complete' => 'completed',
        'cancel'   => 'cancelled',
        'dispute'  => 'disputed',
        'resolve'  => 'completed',
        'refund'   => 'refunded',
    ];

    /** إجراءات يقوم بها العميل لا البائع | Customer-initiated actions. */
    private const CUSTOMER_ACTIONS = ['dispute'];

    /** إجراءات تستوجب سبباً | Actions requiring a written reason. */
    private const REASON_REQUIRED = ['cancel', 'dispute', 'refund'];

    public function __construct(
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    public function can(string $currentStatus, string $action): bool
    {
        return in_array($action, self::TRANSITIONS[$currentStatus] ?? [], true);
    }

    /** @return array<int,string> */
    public function availableActions(string $currentStatus): array
    {
        return self::TRANSITIONS[$currentStatus] ?? [];
    }

    /** هل يستوجب الإجراء سبباً مكتوباً؟ | Does the action require a written reason? */
    public function requiresReason(string $action): bool
    {
        return in_array($action, self::REASON_REQUIRED, true);
    }

    /** هل الإجراء من حق العميل لا البائع؟ | Is the action customer-initiated? */
    public function isCustomerAction(string $action): bool
    {
        return in_array($action, self::CUSTOMER_ACTIONS, true);
    }

    // ═══════════════════ إنشاء الطلبات | Order placement ═══════════════════

    /**
     * إتمام الشراء | Check out a cart into one order per seller (§4.4).
     *
     * كل ما يلي يقع داخل معاملة واحدة: التحقق من توفّر الأصناف وأسعارها،
     * إنشاء الطلبات وأصنافها، خصم المخزون، كتابة سجل الحالة، وتفريغ السلة.
     * إما أن تكتمل كلها أو لا يُنشأ شيء — طلب بلا خصم مخزون يبيع ما لا يوجد،
     * وخصم مخزون بلا طلب يُخفي بضاعة قائمة.
     *
     * @param  array<string,mixed> $customer
     * @return array<int,int> معرّفات الطلبات المُنشأة
     */
    public function checkout(int $cartId, array $customer, ?int $paymentMethodId, Request $request): array
    {
        return Database::transaction(function () use ($cartId, $customer, $paymentMethodId, $request): array {
            $items = $this->lockCartItems($cartId);

            if ($items === []) {
                throw new HttpException(422, 'السلة فارغة.');
            }

            // التجميع حسب البائع | Group by seller
            $bySeller = [];
            foreach ($items as $item) {
                $bySeller[(int) $item['seller_organization_id']][] = $item;
            }

            $orderIds = [];

            foreach ($bySeller as $sellerId => $sellerItems) {
                $orderIds[] = $this->createOrderForSeller(
                    $sellerId,
                    $sellerItems,
                    $customer,
                    $paymentMethodId,
                    $request,
                );
            }

            Database::statement(
                "UPDATE carts SET status = 'converted', converted_at = NOW() WHERE id = ?",
                [$cartId],
            );
            Database::statement('DELETE FROM cart_items WHERE cart_id = ?', [$cartId]);

            return $orderIds;
        });
    }

    /**
     * قفل أصناف السلة وقراءة حالتها الحالية | Lock cart rows and re-read state.
     *
     * `FOR UPDATE` على صفوف الإعلانات يمنع تجاوز المخزون عند طلبين متزامنين
     * على آخر قطعة: الثاني ينتظر حتى يُثبَّت الأول ثم يرى الكمية المحدّثة.
     * Row locks prevent two concurrent checkouts from both selling the last
     * unit: the second waits for the first to commit and then sees the truth.
     *
     * @return array<int,array<string,mixed>>
     */
    private function lockCartItems(int $cartId): array
    {
        return Database::select(
            'SELECT ci.*, l.name_ar, l.sku, l.unit_of_measure, l.status AS listing_status,
                    l.pricing_mode, l.price AS current_price, l.vat_rate AS current_vat_rate,
                    l.available_quantity, l.track_inventory, l.min_order_quantity,
                    l.delivery_fee, l.currency_code, l.deleted_at AS listing_deleted_at,
                    o.status AS seller_status
               FROM cart_items ci
               JOIN listings l ON l.id = ci.listing_id
               JOIN organizations o ON o.id = ci.seller_organization_id
              WHERE ci.cart_id = ?
              ORDER BY ci.seller_organization_id, ci.id
                FOR UPDATE',
            [$cartId],
        );
    }

    /**
     * إنشاء طلب لبائع واحد | Create one seller's order.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function createOrderForSeller(
        int $sellerId,
        array $items,
        array $customer,
        ?int $paymentMethodId,
        Request $request,
    ): int {
        $currency    = 'EGP';
        $subtotal    = Money::zero($currency);
        $vatTotal    = Money::zero($currency);
        $deliveryFee = Money::zero($currency);
        $lines       = [];

        foreach ($items as $item) {
            $this->assertPurchasable($item);

            $quantity  = (float) $item['quantity'];
            // السعر يُقرأ من الإعلان لا من السلة: سعر السلة قد يكون قديماً،
            // والعميل يجب أن يدفع السعر المعروض وقت الشراء لا وقت الإضافة.
            $unitPrice = Money::fromDecimal((string) $item['current_price'], $currency);
            $vatRate   = (float) $item['current_vat_rate'];

            $lineSubtotal = $unitPrice->times($quantity);
            $lineVat      = $lineSubtotal->percentage($vatRate);
            $lineTotal    = $lineSubtotal->plus($lineVat);

            $subtotal = $subtotal->plus($lineSubtotal);
            $vatTotal = $vatTotal->plus($lineVat);

            if ($item['delivery_fee'] !== null) {
                // أعلى رسوم توصيل بين أصناف البائع، لا مجموعها — شحنة واحدة
                $itemFee = Money::fromDecimal((string) $item['delivery_fee'], $currency);
                if ($itemFee->greaterThan($deliveryFee)) {
                    $deliveryFee = $itemFee;
                }
            }

            $lines[] = [
                'listing_id'      => (int) $item['listing_id'],
                'name_ar'         => (string) $item['name_ar'],
                'sku'             => $item['sku'],
                'unit_of_measure' => $item['unit_of_measure'],
                'quantity'        => $quantity,
                'unit_price'      => $unitPrice,
                'vat_rate'        => $vatRate,
                'line_subtotal'   => $lineSubtotal,
                'line_vat'        => $lineVat,
                'line_total'      => $lineTotal,
                'track_inventory' => (int) $item['track_inventory'] === 1,
            ];
        }

        $total = $subtotal->plus($vatTotal)->plus($deliveryFee);

        $orderId = Database::insert(
            'INSERT INTO orders
                (order_number, organization_id, customer_user_id, customer_name,
                 customer_phone, customer_email, governorate_id, city_id,
                 delivery_address, customer_note, tracking_token, status,
                 subtotal, vat_amount, delivery_fee, total, currency_code,
                 payment_method_id, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->generateOrderNumber(),
                $sellerId,
                $customer['user_id'] ?? null,
                mb_substr((string) $customer['name'], 0, 150),
                mb_substr((string) $customer['phone'], 0, 30),
                $customer['email'] ?? null,
                $customer['governorate_id'] ?? null,
                $customer['city_id'] ?? null,
                $customer['address'] ?? null,
                $customer['note'] ?? null,
                bin2hex(random_bytes(24)),
                'new',
                $subtotal->toDecimal(),
                $vatTotal->toDecimal(),
                $deliveryFee->toDecimal(),
                $total->toDecimal(),
                $currency,
                $paymentMethodId,
                $customer['source'] ?? 'marketplace',
            ],
        );

        foreach ($lines as $line) {
            Database::statement(
                'INSERT INTO order_items
                    (order_id, organization_id, listing_id, name_ar, sku, unit_of_measure,
                     quantity, unit_price, vat_rate, line_subtotal, line_vat, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $orderId, $sellerId, $line['listing_id'], $line['name_ar'], $line['sku'],
                    $line['unit_of_measure'], $line['quantity'],
                    $line['unit_price']->toDecimal(), $line['vat_rate'],
                    $line['line_subtotal']->toDecimal(), $line['line_vat']->toDecimal(),
                    $line['line_total']->toDecimal(),
                ],
            );

            if ($line['track_inventory'] && $line['listing_id'] !== null) {
                $this->decrementStock($line['listing_id'], (float) $line['quantity']);
            }

            Database::statement(
                'UPDATE listings SET order_count = order_count + 1 WHERE id = ?',
                [$line['listing_id']],
            );
        }

        $this->recordStatus($orderId, $sellerId, null, 'new', null, 'customer', 'إنشاء الطلب');

        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'order.created',
            category: AuditLogger::CATEGORY_ORDER,
            entityType: 'order',
            entityId: $orderId,
            description: 'طلب جديد بقيمة ' . $total->format(),
            userId: $customer['user_id'] ?? null,
            organizationId: $sellerId,
        );

        $this->notifications->notifyOrganizationMembers(
            organizationId: $sellerId,
            type: 'order.created',
            title: 'طلب جديد',
            body: 'وصل طلب جديد بقيمة ' . $total->format() . ' من ' . $customer['name'] . '.',
            severity: 'success',
            actionUrl: url('/app/orders/' . $orderId),
            actionLabel: 'عرض الطلب',
            entityType: 'order',
            entityId: $orderId,
        );

        return $orderId;
    }

    /**
     * التحقق من إمكانية شراء الصنف | Verify a line is actually purchasable.
     *
     * يُعاد الفحص عند الدفع لا عند الإضافة فقط، لأن الإعلان قد يُسحب أو ينفد
     * أو تُوقف منشأته بين اللحظتين.
     */
    private function assertPurchasable(array $item): void
    {
        $name = (string) $item['name_ar'];

        if ($item['listing_deleted_at'] !== null || $item['listing_status'] !== 'published') {
            throw new HttpException(422, "الصنف «{$name}» لم يعد متاحاً للشراء. يرجى إزالته من السلة.");
        }

        if ($item['seller_status'] !== 'verified') {
            throw new HttpException(422, "المنشأة البائعة للصنف «{$name}» غير متاحة حالياً.");
        }

        if ($item['pricing_mode'] !== 'fixed' || $item['current_price'] === null) {
            throw new HttpException(422, "الصنف «{$name}» يُطلب بعرض سعر ولا يُضاف إلى السلة.");
        }

        $quantity = (float) $item['quantity'];

        if ($quantity < (float) $item['min_order_quantity']) {
            throw new HttpException(
                422,
                "الحد الأدنى لطلب «{$name}» هو " . number_ar((float) $item['min_order_quantity'], 0) . '.',
            );
        }

        if ((int) $item['track_inventory'] === 1) {
            $available = (float) ($item['available_quantity'] ?? 0);

            if ($available < $quantity) {
                throw new HttpException(
                    422,
                    "الكمية المتاحة من «{$name}» هي " . number_ar($available, 0) . ' فقط.',
                );
            }
        }
    }

    private function decrementStock(int $listingId, float $quantity): void
    {
        // الشرط على الكمية يمنع السالب حتى لو تسابق طلبان رغم القفل
        $affected = Database::affectingStatement(
            'UPDATE listings
                SET available_quantity = available_quantity - ?
              WHERE id = ? AND track_inventory = 1 AND available_quantity >= ?',
            [$quantity, $listingId, $quantity],
        );

        if ($affected === 0) {
            throw new HttpException(422, 'تغيّرت الكمية المتاحة لأحد الأصناف. يرجى مراجعة السلة.');
        }
    }

    private function generateOrderNumber(): string
    {
        // NP-YYMM-XXXXXX — مقروء للعميل وفريد عملياً
        return 'NP-' . date('ym') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    // ═══════════════════ تغيير الحالة | Status transitions ═══════════════════

    /**
     * تنفيذ إجراء على الطلب | Perform an order action.
     *
     * @throws HttpException|AuthorizationException
     */
    public function transition(
        int $orderId,
        int $organizationId,
        string $action,
        ?int $actorUserId,
        string $actorType,
        Request $request,
        ?string $note = null,
    ): array {
        return Database::transaction(function () use (
            $orderId, $organizationId, $action, $actorUserId, $actorType, $request, $note
        ): array {
            // القفل يمنع تغييرين متزامنين على نفس الطلب
            $order = Database::selectOne(
                'SELECT * FROM orders WHERE id = ? AND organization_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$orderId, $organizationId],
            );

            if ($order === null) {
                throw new HttpException(404, 'الطلب المطلوب غير موجود.');
            }

            $from = (string) $order['status'];

            if (!$this->can($from, $action)) {
                throw new HttpException(
                    422,
                    'لا يمكن تنفيذ هذا الإجراء على طلب حالته: ' . $this->statusLabel($from) . '.',
                );
            }

            if (in_array($action, self::REASON_REQUIRED, true) && trim((string) $note) === '') {
                throw new HttpException(422, 'يجب توضيح السبب لتنفيذ هذا الإجراء.');
            }

            // إجراءات العميل لا يقوم بها البائع والعكس
            if (in_array($action, self::CUSTOMER_ACTIONS, true) && $actorType === 'seller') {
                throw new AuthorizationException(
                    'هذا الإجراء متاح للعميل فقط.',
                    'marketplace.order.update_status',
                    'order',
                );
            }

            $to = self::RESULTING_STATUS[$action];

            $extra    = '';
            $bindings = [$to];

            if ($action === 'cancel') {
                $extra      = ', cancelled_reason = ?';
                $bindings[] = mb_substr((string) $note, 0, 500);
            } elseif ($action === 'confirm') {
                $extra = ', confirmed_at = NOW()';
            } elseif ($action === 'deliver') {
                $extra = ', delivered_at = NOW()';
            } elseif ($action === 'complete') {
                $extra = ', completed_at = NOW()';
            } elseif ($action === 'refund') {
                $extra = ", payment_status = 'refunded'";
            }

            $bindings[] = $orderId;
            $bindings[] = $organizationId;

            Database::statement(
                "UPDATE orders SET status = ?{$extra} WHERE id = ? AND organization_id = ?",
                $bindings,
            );

            // الإلغاء يُعيد المخزون | Cancellation returns stock
            if (in_array($action, ['cancel', 'refund'], true) && $from !== 'cancelled') {
                $this->restoreStock($orderId);
            }

            $this->recordStatus($orderId, $organizationId, $from, $to, $actorUserId, $actorType, $note);

            $this->audit->setRequest($request);
            $this->audit->logStatusChange(
                'order', $orderId, $from, $to, AuditLogger::CATEGORY_ORDER, $organizationId,
            );

            $this->notifyStatusChange($order, $to, $note);

            return ['from' => $from, 'to' => $to, 'action' => $action];
        });
    }

    /** إعادة المخزون بعد الإلغاء | Return stock after cancellation. */
    private function restoreStock(int $orderId): void
    {
        $items = Database::select(
            'SELECT listing_id, quantity FROM order_items WHERE order_id = ? AND listing_id IS NOT NULL',
            [$orderId],
        );

        foreach ($items as $item) {
            Database::statement(
                'UPDATE listings
                    SET available_quantity = available_quantity + ?
                  WHERE id = ? AND track_inventory = 1',
                [(float) $item['quantity'], (int) $item['listing_id']],
            );
        }
    }

    private function recordStatus(
        int $orderId,
        int $organizationId,
        ?string $from,
        string $to,
        ?int $actorUserId,
        string $actorType,
        ?string $note,
    ): void {
        Database::statement(
            'INSERT INTO order_status_history
                (order_id, organization_id, from_status, to_status, actor_user_id, actor_type, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $orderId, $organizationId, $from, $to, $actorUserId,
                in_array($actorType, ['seller', 'customer', 'platform', 'system'], true) ? $actorType : 'system',
                $note === null ? null : mb_substr($note, 0, 500),
            ],
        );
    }

    private function notifyStatusChange(array $order, string $to, ?string $note): void
    {
        $orderNumber = (string) $order['order_number'];

        // إشعار العميل المسجَّل | Notify the registered customer
        if ($order['customer_user_id'] !== null) {
            $this->notifications->notify(
                userId: (int) $order['customer_user_id'],
                type: 'order.status_changed',
                title: 'تحديث حالة الطلب ' . $orderNumber,
                body: 'أصبحت حالة طلبك: ' . $this->statusLabel($to)
                    . ($note !== null && $note !== '' ? ' — ' . $note : ''),
                severity: in_array($to, ['cancelled', 'disputed', 'refunded'], true) ? 'warning' : 'info',
                actionUrl: url('/orders/track/' . $order['tracking_token']),
                actionLabel: 'تتبّع الطلب',
                entityType: 'order',
                entityId: (int) $order['id'],
            );
        }

        // إشعار البائع عند الإجراءات القادمة من العميل
        if (in_array($to, ['disputed', 'cancelled'], true)) {
            $this->notifications->notifyOrganizationMembers(
                organizationId: (int) $order['organization_id'],
                type: 'order.' . $to,
                title: 'الطلب ' . $orderNumber . ': ' . $this->statusLabel($to),
                body: $note ?? '',
                severity: 'warning',
                actionUrl: url('/app/orders/' . $order['id']),
                actionLabel: 'عرض الطلب',
                entityType: 'order',
                entityId: (int) $order['id'],
            );
        }
    }

    // ═══════════════════ تسميات | Labels ═══════════════════

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'new'       => 'جديد',
            'confirmed' => 'مؤكَّد',
            'preparing' => 'قيد التجهيز',
            'ready'     => 'جاهز للتسليم',
            'shipped'   => 'تم الشحن',
            'delivered' => 'تم التسليم',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            'disputed'  => 'محل نزاع',
            'refunded'  => 'مُسترد',
            default     => $status,
        };
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'new'                    => 'np-badge--pending',
            'confirmed', 'preparing' => 'np-badge--review',
            'ready', 'shipped'       => 'np-badge--info',
            'delivered', 'completed' => 'np-badge--success',
            'cancelled', 'refunded'  => 'np-badge--muted',
            'disputed'               => 'np-badge--danger',
            default                  => 'np-badge--draft',
        };
    }

    public function actionLabel(string $action): string
    {
        return match ($action) {
            'confirm'  => 'تأكيد الطلب',
            'prepare'  => 'بدء التجهيز',
            'ready'    => 'جاهز للتسليم',
            'ship'     => 'تم الشحن',
            'deliver'  => 'تم التسليم',
            'complete' => 'إتمام الطلب',
            'cancel'   => 'إلغاء الطلب',
            'dispute'  => 'فتح نزاع',
            'resolve'  => 'إغلاق النزاع',
            'refund'   => 'تسجيل استرداد',
            default    => $action,
        };
    }
}
