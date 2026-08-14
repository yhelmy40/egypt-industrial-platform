<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\ListingRepository;
use Tests\TestCase;

/**
 * بحث السوق العام | Public marketplace search (§4.4).
 *
 * الاختبارات هنا تحرس «حدّ الرؤية العامة»: لا يظهر في السوق إلا صنف منشور
 * تملكه منشأة موثّقة. أي ثغرة هنا تعني عرض بضاعة منشأة لم تُراجَع بعد.
 */
final class MarketplaceSearchTest extends TestCase
{
    private ListingRepository $listings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings = new ListingRepository();
    }

    public function test_published_listing_of_a_verified_organization_appears(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $this->createListing($organizationId, ['name_ar' => 'عسل نحل جبلي معبأ']);

        $results = $this->listings->searchPublic(['q' => 'عسل']);

        $this->assertSame(1, $results['total']);
        $this->assertSame('عسل نحل جبلي معبأ', $results['data'][0]['name_ar']);
    }

    public function test_unpublished_listing_is_never_returned(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);

        foreach (['draft', 'pending_review', 'rejected', 'archived'] as $status) {
            $this->createListing($organizationId, [
                'name_ar'      => 'صنف مخفي ' . $status,
                'status'       => $status,
                'published_at' => null,
            ]);
        }

        $this->assertSame(0, $this->listings->searchPublic(['q' => 'مخفي'])['total']);
    }

    public function test_listing_of_an_unverified_organization_is_never_returned(): void
    {
        foreach (['draft', 'submitted', 'under_review', 'rejected', 'suspended'] as $status) {
            $organizationId = $this->createOrganization(['status' => $status]);
            $this->createListing($organizationId, ['name_ar' => 'صنف منشأة ' . $status]);
        }

        $this->assertSame(0, $this->listings->searchPublic(['q' => 'صنف منشأة'])['total']);
    }

    public function test_soft_deleted_listing_disappears_from_search(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $listingId      = $this->createListing($organizationId, ['name_ar' => 'صنف سيُحذف']);

        $this->assertSame(1, $this->listings->searchPublic(['q' => 'سيُحذف'])['total']);

        \App\Core\Database::statement('UPDATE listings SET deleted_at = NOW() WHERE id = ?', [$listingId]);

        $this->assertSame(0, $this->listings->searchPublic(['q' => 'سيُحذف'])['total']);
    }

    public function test_filters_narrow_the_result_set(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);

        $this->createListing($organizationId, [
            'name_ar' => 'منتج رخيص للتصفية', 'price' => 50.00, 'listing_type' => 'product',
        ]);
        $this->createListing($organizationId, [
            'name_ar' => 'خدمة غالية للتصفية', 'price' => 5000.00, 'listing_type' => 'service',
        ]);

        $this->assertSame(2, $this->listings->searchPublic(['q' => 'للتصفية'])['total']);
        $this->assertSame(1, $this->listings->searchPublic(['q' => 'للتصفية', 'type' => 'service'])['total']);
        $this->assertSame(1, $this->listings->searchPublic(['q' => 'للتصفية', 'price_max' => 100])['total']);
        $this->assertSame(0, $this->listings->searchPublic(['q' => 'للتصفية', 'price_min' => 10000])['total']);
    }

    /**
     * الترتيب يقبل قيماً من قائمة بيضاء فقط | Sorting accepts an allow-list only.
     *
     * قيمة الترتيب تصل من شريط العنوان. لو دخلت مباشرة في جملة ORDER BY لصارت
     * ثغرة حقن؛ الاختبار يثبت أن القيمة المجهولة تسقط إلى ترتيب آمن.
     */
    public function test_unknown_sort_value_falls_back_and_does_not_break_the_query(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $this->createListing($organizationId, ['name_ar' => 'صنف الترتيب']);

        $results = $this->listings->searchPublic([
            'q'    => 'الترتيب',
            'sort' => 'id; DROP TABLE listings; --',
        ]);

        $this->assertSame(1, $results['total']);
        $this->assertNotNull(
            \App\Core\Database::scalar("SHOW TABLES LIKE 'listings'"),
            'جدول الإعلانات يجب أن يبقى قائماً.',
        );
    }

    public function test_boolean_search_operators_in_the_term_do_not_break_the_query(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $this->createListing($organizationId, ['name_ar' => 'صنف بحث خاص']);

        foreach (['+++', '*', '"', '-صنف', '( )', '@@'] as $term) {
            $results = $this->listings->searchPublic(['q' => $term]);
            $this->assertIsArray($results['data'], "تعذّر تنفيذ البحث للمصطلح: {$term}");
        }
    }

    public function test_public_lookup_by_slug_respects_the_same_visibility_rules(): void
    {
        $verified   = $this->createOrganization(['status' => 'verified']);
        $unverified = $this->createOrganization(['status' => 'submitted']);

        $this->createListing($verified, ['slug' => 'visible-listing']);
        $this->createListing($unverified, ['slug' => 'hidden-listing']);
        $this->createListing($verified, ['slug' => 'draft-listing', 'status' => 'draft']);

        $this->assertNotNull($this->listings->findPublicBySlug('visible-listing'));
        $this->assertNull($this->listings->findPublicBySlug('hidden-listing'));
        $this->assertNull($this->listings->findPublicBySlug('draft-listing'));
    }
}
