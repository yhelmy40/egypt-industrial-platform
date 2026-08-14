<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Repositories\ListingRepository;
use App\Repositories\OrderRepository;
use App\Services\CartService;
use App\Services\OrderService;
use Tests\TestCase;

/**
 * التقييمات بعد الطلب | Reviews after a completed order (§4.4).
 *
 * لا تقييم بلا معاملة: التقييم مربوط بطلب مكتمل، وواحد لكل طلب. هذا يحمي
 * المنشآت من حملات تقييم مفتعلة ويحمي العملاء من تقييمات مشتراة.
 */
final class CustomerReviewTest extends TestCase
{
    public function test_review_is_only_reachable_through_a_completed_order(): void
    {
        $repository = new OrderRepository();
        [, $orderId, ] = $this->placeOrder();

        $order = Database::selectOne('SELECT tracking_token FROM orders WHERE id = ?', [$orderId]);
        $token = (string) $order['tracking_token'];

        $this->assertNull(
            $repository->hasCompletedOrder($orderId, null, $token),
            'الطلب الجديد لا يمنح حق التقييم.',
        );

        Database::statement("UPDATE orders SET status = 'completed' WHERE id = ?", [$orderId]);

        $this->assertNotNull(
            $repository->hasCompletedOrder($orderId, null, $token),
            'الطلب المكتمل يمنح حق التقييم.',
        );
    }

    public function test_a_wrong_tracking_token_grants_no_review_right(): void
    {
        $repository = new OrderRepository();
        [, $orderId, ] = $this->placeOrder();

        Database::statement("UPDATE orders SET status = 'completed' WHERE id = ?", [$orderId]);

        $this->assertNull($repository->hasCompletedOrder($orderId, null, str_repeat('b', 48)));
        $this->assertNull($repository->hasCompletedOrder($orderId, null, null));
    }

    /** تقييم واحد لكل طلب | One review per order — enforced by the schema. */
    public function test_the_schema_refuses_a_second_review_for_the_same_order(): void
    {
        [$organizationId, $orderId, $listingId] = $this->placeOrder();
        Database::statement("UPDATE orders SET status = 'completed' WHERE id = ?", [$orderId]);

        $this->insertReview($organizationId, $listingId, $orderId, 5);

        $this->expectException(\PDOException::class);
        $this->insertReview($organizationId, $listingId, $orderId, 1);
    }

    public function test_published_reviews_update_the_listing_rating(): void
    {
        [$organizationId, $orderA, $listingId] = $this->placeOrder();
        [, $orderB] = $this->placeOrderFor($organizationId, $listingId);

        $this->insertReview($organizationId, $listingId, $orderA, 5);
        $this->insertReview($organizationId, $listingId, $orderB, 3);

        (new ListingRepository())->refreshRating($listingId);

        $listing = Database::selectOne(
            'SELECT rating_average, rating_count FROM listings WHERE id = ?',
            [$listingId],
        );

        $this->assertSame('4.00', $listing['rating_average']);
        $this->assertSame(2, (int) $listing['rating_count']);
    }

    /** التقييم المحجوب لا يدخل في المتوسط | A withheld review is excluded from the average. */
    public function test_a_non_published_review_does_not_affect_the_rating(): void
    {
        [$organizationId, $orderA, $listingId] = $this->placeOrder();
        [, $orderB] = $this->placeOrderFor($organizationId, $listingId);

        $this->insertReview($organizationId, $listingId, $orderA, 5);
        $this->insertReview($organizationId, $listingId, $orderB, 1, 'rejected');

        (new ListingRepository())->refreshRating($listingId);

        $listing = Database::selectOne(
            'SELECT rating_average, rating_count FROM listings WHERE id = ?',
            [$listingId],
        );

        $this->assertSame('5.00', $listing['rating_average']);
        $this->assertSame(1, (int) $listing['rating_count']);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @return array{0:int,1:int,2:int} */
    private function placeOrder(): array
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $listingId      = $this->createListing($organizationId, ['available_quantity' => 100]);

        return $this->placeOrderFor($organizationId, $listingId);
    }

    /** @return array{0:int,1:int,2:int} */
    private function placeOrderFor(int $organizationId, int $listingId): array
    {
        $carts  = new CartService();
        $cart   = $carts->resolveCart(null, bin2hex(random_bytes(32)));
        $cartId = (int) $cart['id'];
        $carts->add($cartId, $listingId, 1);

        $orderIds = (new OrderService())->checkout(
            $cartId,
            ['name' => 'عميل اختبار', 'phone' => '01000000000', 'address' => 'عنوان اختبار'],
            null,
            $this->request('POST', '/checkout'),
        );

        return [$organizationId, $orderIds[0], $listingId];
    }

    private function insertReview(
        int $organizationId,
        int $listingId,
        int $orderId,
        int $rating,
        string $status = 'published',
    ): void {
        Database::statement(
            'INSERT INTO reviews
                (organization_id, listing_id, order_id, customer_name, rating, comment, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$organizationId, $listingId, $orderId, 'عميل اختبار', $rating, 'تعليق اختبار', $status],
        );
    }
}
