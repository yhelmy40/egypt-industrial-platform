<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Services\CartService;
use App\Services\OrderService;
use Tests\TestCase;

/**
 * دورة حياة الطلب | Order state machine (§4.4).
 *
 * الانتقالات المسموحة معرّفة في خريطة واحدة داخل الخدمة. هذه الاختبارات تمنع
 * أي مسار جانبي: لا قفز فوق الحالات، ولا إلغاء لطلب شُحن، ولا تنفيذ البائع
 * لإجراء هو حق العميل.
 */
final class OrderLifecycleTest extends TestCase
{
    private OrderService $orders;

    private CartService $carts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new OrderService();
        $this->carts  = new CartService();
    }

    // ═══════════════════ خريطة الانتقالات | Transition map ═══════════════════

    public function test_allowed_transitions_match_the_documented_map(): void
    {
        $this->assertTrue($this->orders->can('new', 'confirm'));
        $this->assertTrue($this->orders->can('confirmed', 'prepare'));
        $this->assertTrue($this->orders->can('preparing', 'ready'));
        $this->assertTrue($this->orders->can('ready', 'ship'));
        $this->assertTrue($this->orders->can('shipped', 'deliver'));
        $this->assertTrue($this->orders->can('delivered', 'complete'));
    }

    public function test_status_cannot_skip_forward(): void
    {
        $this->assertFalse($this->orders->can('new', 'ship'));
        $this->assertFalse($this->orders->can('new', 'complete'));
        $this->assertFalse($this->orders->can('confirmed', 'deliver'));
    }

    /**
     * الطلب المشحون لا يُلغى | A shipped order cannot be cancelled.
     *
     * البضاعة غادرت البائع فعلاً؛ المسار الأمين نزاع ثم استرداد، لا إلغاء
     * يمحو أثر المعاملة.
     */
    public function test_a_shipped_or_delivered_order_cannot_be_cancelled(): void
    {
        $this->assertFalse($this->orders->can('shipped', 'cancel'));
        $this->assertFalse($this->orders->can('delivered', 'cancel'));
        $this->assertFalse($this->orders->can('completed', 'cancel'));

        $this->assertTrue($this->orders->can('shipped', 'dispute'));
        $this->assertTrue($this->orders->can('disputed', 'refund'));
    }

    public function test_a_refunded_order_is_terminal(): void
    {
        $this->assertSame([], $this->orders->availableActions('refunded'));
    }

    public function test_reason_is_required_for_destructive_actions_only(): void
    {
        $this->assertTrue($this->orders->requiresReason('cancel'));
        $this->assertTrue($this->orders->requiresReason('dispute'));
        $this->assertTrue($this->orders->requiresReason('refund'));

        $this->assertFalse($this->orders->requiresReason('confirm'));
        $this->assertFalse($this->orders->requiresReason('ship'));
        $this->assertFalse($this->orders->requiresReason('complete'));
    }

    // ═══════════════════ التنفيذ الفعلي | Executing transitions ═══════════════════

    public function test_a_transition_writes_history_and_moves_the_status(): void
    {
        [$organizationId, $orderId] = $this->placeOrder();
        $actorId = $this->createUser();

        $result = $this->orders->transition(
            orderId: $orderId,
            organizationId: $organizationId,
            action: 'confirm',
            actorUserId: $actorId,
            actorType: 'seller',
            request: $this->request('POST', '/app/orders/' . $orderId . '/status'),
        );

        $this->assertSame(['from' => 'new', 'to' => 'confirmed', 'action' => 'confirm'], $result);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $orderId, 'from_status' => 'new', 'to_status' => 'confirmed',
        ]);
        $this->assertNotNull(
            Database::scalar('SELECT confirmed_at FROM orders WHERE id = ?', [$orderId]),
        );
    }

    public function test_an_invalid_transition_is_refused_and_changes_nothing(): void
    {
        [$organizationId, $orderId] = $this->placeOrder();

        try {
            $this->orders->transition(
                orderId: $orderId,
                organizationId: $organizationId,
                action: 'complete',
                actorUserId: $this->createUser(),
                actorType: 'seller',
                request: $this->request('POST', '/status'),
            );
            $this->fail('كان يجب رفض القفز من «جديد» إلى «مكتمل».');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'new']);
    }

    public function test_cancelling_without_a_reason_is_refused(): void
    {
        [$organizationId, $orderId] = $this->placeOrder();

        $this->expectException(HttpException::class);

        $this->orders->transition(
            orderId: $orderId,
            organizationId: $organizationId,
            action: 'cancel',
            actorUserId: $this->createUser(),
            actorType: 'seller',
            request: $this->request('POST', '/status'),
            note: '   ',
        );
    }

    /** البائع لا يفتح نزاعاً نيابةً عن العميل | The seller cannot dispute for the customer. */
    public function test_seller_cannot_perform_a_customer_action(): void
    {
        [$organizationId, $orderId] = $this->placeOrder();

        Database::statement("UPDATE orders SET status = 'delivered' WHERE id = ?", [$orderId]);

        $this->expectException(AuthorizationException::class);

        $this->orders->transition(
            orderId: $orderId,
            organizationId: $organizationId,
            action: 'dispute',
            actorUserId: $this->createUser(),
            actorType: 'seller',
            request: $this->request('POST', '/status'),
            note: 'محاولة من البائع',
        );
    }

    public function test_cancelling_returns_the_reserved_stock(): void
    {
        [$organizationId, $orderId, $listingId] = $this->placeOrder(quantity: 12, stock: 50);

        $this->assertSame(
            '38.000',
            Database::scalar('SELECT available_quantity FROM listings WHERE id = ?', [$listingId]),
        );

        $this->orders->transition(
            orderId: $orderId,
            organizationId: $organizationId,
            action: 'cancel',
            actorUserId: $this->createUser(),
            actorType: 'seller',
            request: $this->request('POST', '/status'),
            note: 'نفدت المادة الخام',
        );

        $this->assertSame(
            '50.000',
            Database::scalar('SELECT available_quantity FROM listings WHERE id = ?', [$listingId]),
            'الإلغاء يجب أن يعيد الكمية المحجوزة إلى المخزون.',
        );
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'cancelled_reason' => 'نفدت المادة الخام']);
    }

    /**
     * لا انتقال عبر حدود المنشأة | No transition across tenant boundaries.
     *
     * القيد على organization_id في جملة التحديث نفسها: منشأة أخرى تعرف رقم
     * الطلب لا تستطيع تحريكه، وتحصل على 404 لا 403 حتى لا يتأكد لها وجوده.
     */
    public function test_another_organization_cannot_transition_the_order(): void
    {
        [, $orderId] = $this->placeOrder();
        $intruderId  = $this->createOrganization(['status' => 'verified']);

        try {
            $this->orders->transition(
                orderId: $orderId,
                organizationId: $intruderId,
                action: 'confirm',
                actorUserId: $this->createUser(),
                actorType: 'seller',
                request: $this->request('POST', '/status'),
            );
            $this->fail('كان يجب رفض تحريك طلب منشأة أخرى.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'new']);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /**
     * إنشاء طلب حقيقي عبر مسار الشراء | Place a real order through checkout.
     *
     * @return array{0:int,1:int,2:int} [organizationId, orderId, listingId]
     */
    private function placeOrder(float $quantity = 1, float $stock = 50): array
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $listingId      = $this->createListing($organizationId, ['available_quantity' => $stock]);

        $cart   = $this->carts->resolveCart(null, bin2hex(random_bytes(32)));
        $cartId = (int) $cart['id'];
        $this->carts->add($cartId, $listingId, $quantity);

        $orderIds = $this->orders->checkout(
            $cartId,
            ['name' => 'عميل اختبار', 'phone' => '01000000000', 'address' => 'عنوان اختبار'],
            null,
            $this->request('POST', '/checkout'),
        );

        return [$organizationId, $orderIds[0], $listingId];
    }
}
