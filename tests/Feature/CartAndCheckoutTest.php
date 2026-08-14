<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Services\CartService;
use App\Services\OrderService;
use App\Support\Money;
use Tests\TestCase;

/**
 * السلة وإتمام الشراء | Cart and checkout (§4.4).
 *
 * أهم ما تحرسه هذه الاختبارات: الحسابات بالقروش لا بالكسور العشرية، وانقسام
 * السلة إلى طلب لكل بائع، وخصم المخزون داخل نفس المعاملة التي تُنشئ الطلب.
 */
final class CartAndCheckoutTest extends TestCase
{
    private CartService $carts;

    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carts  = new CartService();
        $this->orders = new OrderService();
    }

    // ═══════════════════ الحساب النقدي | Money ═══════════════════

    public function test_money_avoids_floating_point_drift(): void
    {
        // 0.1 + 0.2 بالكسور العشرية = 0.30000000000000004
        $sum = Money::fromDecimal('0.10')->plus(Money::fromDecimal('0.20'));

        $this->assertSame(30, $sum->minorUnits());
        $this->assertSame('0.30', $sum->toDecimal());
    }

    public function test_money_rounds_vat_consistently(): void
    {
        $line = Money::fromDecimal('99.99')->times(3);

        $this->assertSame('299.97', $line->toDecimal());
        $this->assertSame('42.00', $line->percentage(14)->toDecimal());
    }

    // ═══════════════════ السلة | Cart ═══════════════════

    public function test_quote_only_listing_cannot_be_added_to_the_cart(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $listingId      = $this->createListing($organizationId, [
            'pricing_mode' => 'quote', 'price' => null, 'track_inventory' => 0,
            'available_quantity' => null,
        ]);

        $cart = $this->carts->resolveCart(null, bin2hex(random_bytes(32)));

        $this->expectException(HttpException::class);
        $this->carts->add((int) $cart['id'], $listingId, 1);
    }

    public function test_unpublished_listing_cannot_be_added_to_the_cart(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $listingId      = $this->createListing($organizationId, ['status' => 'draft']);

        $cart = $this->carts->resolveCart(null, bin2hex(random_bytes(32)));

        $this->expectException(HttpException::class);
        $this->carts->add((int) $cart['id'], $listingId, 1);
    }

    public function test_listing_of_an_unverified_seller_cannot_be_added_to_the_cart(): void
    {
        $organizationId = $this->createOrganization(['status' => 'submitted']);
        $listingId      = $this->createListing($organizationId);

        $cart = $this->carts->resolveCart(null, bin2hex(random_bytes(32)));

        $this->expectException(HttpException::class);
        $this->carts->add((int) $cart['id'], $listingId, 1);
    }

    public function test_quantity_beyond_available_stock_is_refused(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $listingId      = $this->createListing($organizationId, ['available_quantity' => 5]);

        $cart = $this->carts->resolveCart(null, bin2hex(random_bytes(32)));

        $this->expectException(HttpException::class);
        $this->carts->add((int) $cart['id'], $listingId, 6);
    }

    public function test_cart_totals_group_by_seller_and_sum_correctly(): void
    {
        $sellerA = $this->createOrganization(['status' => 'verified']);
        $sellerB = $this->createOrganization(['status' => 'verified']);

        $listingA = $this->createListing($sellerA, ['price' => 100.00, 'vat_rate' => 14.00]);
        $listingB = $this->createListing($sellerB, ['price' => 250.00, 'vat_rate' => 0.00]);

        $cart   = $this->carts->resolveCart(null, bin2hex(random_bytes(32)));
        $cartId = (int) $cart['id'];

        $this->carts->add($cartId, $listingA, 3);
        $this->carts->add($cartId, $listingB, 2);

        $contents = $this->carts->contents($cartId);

        $this->assertCount(2, $contents['groups'], 'السلة تُجمَّع حسب البائع.');
        $this->assertSame(2, $contents['count']);
        // 300 + 500 = 800 قبل الضريبة، الضريبة 42 على البند الأول فقط
        $this->assertStringContainsString('800.00', $contents['totals']['subtotal']);
        $this->assertStringContainsString('42.00', $contents['totals']['vat']);
        $this->assertStringContainsString('842.00', $contents['totals']['total']);
    }

    public function test_cart_flags_an_item_whose_listing_was_withdrawn(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $listingId      = $this->createListing($organizationId);

        $cart   = $this->carts->resolveCart(null, bin2hex(random_bytes(32)));
        $cartId = (int) $cart['id'];
        $this->carts->add($cartId, $listingId, 1);

        Database::statement("UPDATE listings SET status = 'archived' WHERE id = ?", [$listingId]);

        $this->assertNotSame([], $this->carts->blockingIssues($cartId));
    }

    // ═══════════════════ إتمام الشراء | Checkout ═══════════════════

    public function test_checkout_splits_the_cart_into_one_order_per_seller(): void
    {
        $sellerA = $this->createOrganization(['status' => 'verified']);
        $sellerB = $this->createOrganization(['status' => 'verified']);

        $listingA = $this->createListing($sellerA, ['price' => 100.00]);
        $listingB = $this->createListing($sellerB, ['price' => 200.00]);

        $cart   = $this->carts->resolveCart(null, bin2hex(random_bytes(32)));
        $cartId = (int) $cart['id'];
        $this->carts->add($cartId, $listingA, 1);
        $this->carts->add($cartId, $listingB, 1);

        $orderIds = $this->orders->checkout(
            $cartId,
            $this->customer(),
            null,
            $this->request('POST', '/checkout'),
        );

        $this->assertCount(2, $orderIds, 'كل بائع يحصل على طلب مستقل.');

        $organizations = Database::select(
            'SELECT organization_id FROM orders WHERE id IN (' . implode(',', $orderIds) . ')',
        );
        $ids = array_map(static fn (array $row): int => (int) $row['organization_id'], $organizations);

        sort($ids);
        $expected = [$sellerA, $sellerB];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_checkout_computes_totals_from_the_current_listing_price(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $listingId      = $this->createListing($organizationId, ['price' => 180.00, 'vat_rate' => 14.00]);

        $cart   = $this->carts->resolveCart(null, bin2hex(random_bytes(32)));
        $cartId = (int) $cart['id'];
        $this->carts->add($cartId, $listingId, 10);

        $orderIds = $this->orders->checkout(
            $cartId,
            $this->customer(),
            null,
            $this->request('POST', '/checkout'),
        );

        $order = Database::selectOne('SELECT * FROM orders WHERE id = ?', [$orderIds[0]]);

        $this->assertSame('1800.00', $order['subtotal']);
        $this->assertSame('252.00', $order['vat_amount']);
        $this->assertSame('2052.00', $order['total']);
        $this->assertSame('new', $order['status']);
        $this->assertSame('unpaid', $order['payment_status']);
        $this->assertSame(48, strlen((string) $order['tracking_token']));
    }

    public function test_checkout_decrements_tracked_stock_and_empties_the_cart(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $listingId      = $this->createListing($organizationId, ['available_quantity' => 50]);

        $cart   = $this->carts->resolveCart(null, bin2hex(random_bytes(32)));
        $cartId = (int) $cart['id'];
        $this->carts->add($cartId, $listingId, 12);

        $this->orders->checkout($cartId, $this->customer(), null, $this->request('POST', '/checkout'));

        $this->assertSame(
            '38.000',
            Database::scalar('SELECT available_quantity FROM listings WHERE id = ?', [$listingId]),
        );
        $this->assertSame(0, $this->carts->itemCount($cartId));
    }

    public function test_checkout_leaves_untracked_stock_untouched(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $listingId      = $this->createListing($organizationId, [
            'track_inventory' => 0, 'available_quantity' => null,
        ]);

        $cart   = $this->carts->resolveCart(null, bin2hex(random_bytes(32)));
        $cartId = (int) $cart['id'];
        $this->carts->add($cartId, $listingId, 99);

        $this->orders->checkout($cartId, $this->customer(), null, $this->request('POST', '/checkout'));

        $this->assertNull(
            Database::scalar('SELECT available_quantity FROM listings WHERE id = ?', [$listingId]),
        );
    }

    /**
     * لا طلب بلا مخزون | No order without stock.
     *
     * الإنشاء وخصم المخزون في معاملة واحدة. لو سُحب الصنف بين الإضافة والدفع،
     * يجب ألّا يبقى طلب معلّق ولا مخزون منقوص.
     */
    public function test_checkout_creates_nothing_when_an_item_became_unavailable(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $listingId      = $this->createListing($organizationId, ['available_quantity' => 10]);

        $cart   = $this->carts->resolveCart(null, bin2hex(random_bytes(32)));
        $cartId = (int) $cart['id'];
        $this->carts->add($cartId, $listingId, 10);

        // سُحب الصنف من النشر بعد إضافته للسلة
        Database::statement("UPDATE listings SET status = 'archived' WHERE id = ?", [$listingId]);

        $ordersBefore = $this->countRows('orders');

        try {
            $this->orders->checkout($cartId, $this->customer(), null, $this->request('POST', '/checkout'));
            $this->fail('كان يجب رفض إتمام الشراء لصنف لم يعد منشوراً.');
        } catch (HttpException) {
            // متوقّع
        }

        $this->assertSame($ordersBefore, $this->countRows('orders'), 'لم يُنشأ أي طلب.');
        $this->assertSame(
            '10.000',
            Database::scalar('SELECT available_quantity FROM listings WHERE id = ?', [$listingId]),
            'المخزون لم يُخصم.',
        );
    }

    public function test_checkout_refuses_an_empty_cart(): void
    {
        $cart = $this->carts->resolveCart(null, bin2hex(random_bytes(32)));

        $this->expectException(HttpException::class);
        $this->orders->checkout((int) $cart['id'], $this->customer(), null, $this->request('POST', '/checkout'));
    }

    /** @return array<string,mixed> */
    private function customer(): array
    {
        return [
            'name'    => 'عميل اختبار',
            'phone'   => '01000000000',
            'address' => 'عنوان اختبار',
        ];
    }
}
