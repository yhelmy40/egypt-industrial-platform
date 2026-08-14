<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\ListingRepository;
use App\Repositories\OrderRepository;
use App\Services\CartService;
use App\Services\ListingService;
use App\Services\OrderService;
use Tests\TestCase;

/**
 * عزل بيانات السوق بين المنشآت | Marketplace tenant isolation (§7, §17).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * الاختبارات السابقة أثبتت العزل على مستوى المستودع الأساسي. هذه تثبته على
 * جداول المرحلة الثالثة نفسها: الأصناف والطلبات والاستفسارات وعروض الأسعار.
 * كل واحد من هذه الجداول يحمل بيانات تجارية حسّاسة — أسعار العملاء وأرقام
 * هواتفهم وحجم المبيعات — وتسريب أيٍّ منها لمنافس ضرر مباشر على المنشأة.
 *
 * Earlier tests proved isolation at the base-repository level. These prove it
 * on the Phase 3 tables themselves, each of which holds commercially sensitive
 * data whose leak to a competitor is direct harm.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class MarketplaceIsolationTest extends TestCase
{
    // ═══════════════════ الأصناف | Listings ═══════════════════

    public function test_listing_repository_never_returns_another_organizations_listing(): void
    {
        [$ownerOrg, $ownerUser]     = $this->organizationWithOwner();
        [$otherOrg, $otherUser]     = $this->organizationWithOwner();

        $listingId = $this->createListing($ownerOrg, ['name_ar' => 'صنف خاص بالمالك']);

        $this->actingAs($otherUser, $otherOrg);
        $repository = new ListingRepository();

        $this->assertNull(
            $repository->scopedToTenant()->find($listingId),
            'منشأة أخرى لا ترى الصنف حتى لو عرفت معرّفه.',
        );

        $this->actingAs($ownerUser, $ownerOrg);
        $this->assertNotNull((new ListingRepository())->scopedToTenant()->find($listingId));
    }

    public function test_listing_update_across_tenants_changes_nothing(): void
    {
        [$ownerOrg]             = $this->organizationWithOwner();
        [$otherOrg, $otherUser] = $this->organizationWithOwner();

        $listingId = $this->createListing($ownerOrg, ['price' => 100.00]);

        $this->actingAs($otherUser, $otherOrg);

        $affected = (new ListingRepository())->scopedToTenant()->update($listingId, ['price' => 1.00]);

        $this->assertSame(0, $affected);
        $this->assertSame(
            '100.00',
            Database::scalar('SELECT price FROM listings WHERE id = ?', [$listingId]),
        );
    }

    /** لا نشر لصنف منشأة أخرى | No publishing another organization's listing. */
    public function test_another_organization_cannot_submit_a_listing_for_review(): void
    {
        [$ownerOrg]             = $this->organizationWithOwner();
        [$otherOrg, $otherUser] = $this->organizationWithOwner();

        $listingId = $this->createListing($ownerOrg, ['status' => 'draft', 'published_at' => null]);

        $this->actingAs($otherUser, $otherOrg);

        $this->expectException(HttpException::class);

        (new ListingService())->submitForPublication(
            $listingId,
            $otherOrg,
            $otherUser,
            $this->request('POST', '/app/listings/' . $listingId . '/submit'),
        );
    }

    public function test_moderation_decision_is_recorded_against_the_moderator(): void
    {
        [$ownerOrg]  = $this->organizationWithOwner();
        $moderatorId = $this->createUser();
        $listingId   = $this->createListing($ownerOrg, [
            'status' => 'pending_review', 'published_at' => null,
        ]);

        (new ListingService())->moderate(
            listingId: $listingId,
            decision: 'approve',
            note: null,
            moderatorId: $moderatorId,
            request: $this->request('POST', '/admin/moderation/' . $listingId . '/decide'),
        );

        $listing = Database::selectOne('SELECT * FROM listings WHERE id = ?', [$listingId]);

        $this->assertSame('published', $listing['status']);
        $this->assertSame($moderatorId, (int) $listing['moderated_by']);
        $this->assertNotNull($listing['moderated_at']);
        $this->assertNotNull($listing['published_at']);
    }

    // ═══════════════════ الطلبات | Orders ═══════════════════

    public function test_order_repository_never_returns_another_organizations_order(): void
    {
        [$sellerOrg] = $this->organizationWithOwner();
        [$otherOrg]  = $this->organizationWithOwner();

        $orderId = $this->placeOrder($sellerOrg);

        $repository = new OrderRepository();

        $this->assertNotNull($repository->findWithDetails($orderId, $sellerOrg));
        $this->assertNull(
            $repository->findWithDetails($orderId, $otherOrg),
            'منشأة أخرى لا تصل إلى الطلب ولو عرفت معرّفه.',
        );
    }

    public function test_order_counters_and_summaries_never_mix_organizations(): void
    {
        [$sellerA] = $this->organizationWithOwner();
        [$sellerB] = $this->organizationWithOwner();

        $this->placeOrder($sellerA);
        $this->placeOrder($sellerA);
        $this->placeOrder($sellerB);

        $repository = new OrderRepository();

        $this->assertSame(2, array_sum($repository->countsByStatus($sellerA)));
        $this->assertSame(1, array_sum($repository->countsByStatus($sellerB)));

        $this->assertSame(2, (int) $repository->salesSummary($sellerA)['order_count']);
        $this->assertSame(1, (int) $repository->salesSummary($sellerB)['order_count']);

        $this->assertSame(2, $repository->forOrganization($sellerA, null, '', 1)['total']);
        $this->assertSame(1, $repository->forOrganization($sellerB, null, '', 1)['total']);
    }

    /**
     * رمز التتبّع لا يُستبدل بالمعرّف الرقمي | The numeric id is not a key.
     *
     * لو قَبِل البحث معرّفاً رقمياً لصار تعداد الطلبات ممكناً بالعدّ من ١.
     */
    public function test_order_tracking_requires_the_full_random_token(): void
    {
        [$sellerOrg] = $this->organizationWithOwner();
        $orderId     = $this->placeOrder($sellerOrg);

        $token      = (string) Database::scalar('SELECT tracking_token FROM orders WHERE id = ?', [$orderId]);
        $repository = new OrderRepository();

        $this->assertNotNull($repository->findByTrackingToken($token));
        $this->assertNull($repository->findByTrackingToken((string) $orderId));
        $this->assertNull($repository->findByTrackingToken(substr($token, 0, 47)));
        $this->assertNull($repository->findByTrackingToken(strtoupper($token)));
    }

    public function test_customer_order_list_only_shows_their_own_orders(): void
    {
        [$sellerOrg]  = $this->organizationWithOwner();
        $customerA    = $this->createUser();
        $customerB    = $this->createUser();

        $orderA = $this->placeOrder($sellerOrg, $customerA);
        $this->placeOrder($sellerOrg, $customerB);

        $repository = new OrderRepository();
        $rows       = $repository->forCustomer($customerA);

        $this->assertCount(1, $rows);
        $this->assertSame($orderA, (int) $rows[0]['id']);
    }

    // ═══════════════════ الاستفسارات وعروض الأسعار | Enquiries and quotations ═══════════════════

    public function test_enquiries_and_quotations_carry_the_owning_organization(): void
    {
        [$sellerA] = $this->organizationWithOwner();
        [$sellerB] = $this->organizationWithOwner();

        $this->insertEnquiry($sellerA);
        $this->insertEnquiry($sellerB);

        $this->assertSame(
            1,
            $this->countRows('customer_enquiries', 'organization_id = ?', [$sellerA]),
        );
        $this->assertSame(
            1,
            $this->countRows('customer_enquiries', 'organization_id = ?', [$sellerB]),
        );
    }

    /**
     * أصناف منشأة غير موثّقة لا تُسرَّب عبر الواجهة العامة | No leak via public API.
     *
     * الفحص هنا على مستوى المستودع العام لا على مستوى المستأجر: صفحة السوق لا
     * تعرف منشأة نشطة، فحمايتها الوحيدة هي شروط الرؤية في الاستعلام نفسه.
     */
    public function test_public_search_ignores_the_active_tenant_and_applies_visibility_only(): void
    {
        [$ownerOrg, $ownerUser] = $this->organizationWithOwner();
        $draftId = $this->createListing($ownerOrg, [
            'name_ar' => 'مسودة داخلية', 'status' => 'draft', 'published_at' => null,
        ]);

        // حتى بينما المالك نفسه هو المستخدم النشط، البحث العام لا يُظهر المسودة
        $this->actingAs($ownerUser, $ownerOrg);

        $results = (new ListingRepository())->searchPublic(['q' => 'مسودة داخلية']);

        $this->assertSame(0, $results['total']);
        $this->assertNotNull(
            (new ListingRepository())->scopedToTenant()->find($draftId),
            'المالك يرى مسودته في مساحة عمله.',
        );
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @return array{0:int,1:int} [organizationId, ownerUserId] */
    private function organizationWithOwner(): array
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $userId         = $this->createUser();
        $this->addMember($organizationId, $userId, 'sme_owner');

        return [$organizationId, $userId];
    }

    private function placeOrder(int $organizationId, ?int $customerUserId = null): int
    {
        $listingId = $this->createListing($organizationId, ['available_quantity' => 100]);

        $carts  = new CartService();
        $cart   = $carts->resolveCart(null, bin2hex(random_bytes(32)));
        $cartId = (int) $cart['id'];
        $carts->add($cartId, $listingId, 1);

        $orderIds = (new OrderService())->checkout(
            $cartId,
            [
                'user_id' => $customerUserId,
                'name'    => 'عميل اختبار',
                'phone'   => '01000000000',
                'address' => 'عنوان اختبار',
            ],
            null,
            $this->request('POST', '/checkout'),
        );

        return $orderIds[0];
    }

    private function insertEnquiry(int $organizationId): void
    {
        Database::statement(
            'INSERT INTO customer_enquiries
                (organization_id, customer_name, customer_phone, subject, message)
             VALUES (?, ?, ?, ?, ?)',
            [$organizationId, 'مستفسر', '01000000000', 'استفسار', 'نص الاستفسار.'],
        );
    }
}
