<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ListingRepository;
use App\Services\PublicPageService;

/**
 * السوق العام | Public marketplace (§4.4, §11).
 *
 * تصفّح وبحث بلا تسجيل دخول. كل ما يظهر هنا مرّ بشرطين: الإعلان منشور،
 * والمنشأة موثّقة — الشرطان مفروضان في المستودع لا في القالب.
 * Browsing and search without login. Everything shown has passed both gates —
 * listing published and seller verified — enforced in the repository, not the
 * template.
 */
final class MarketplaceController extends Controller
{
    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly PublicPageService $pages = new PublicPageService(),
    ) {
    }

    /** تصفّح السوق | Browse and search. */
    public function index(Request $request): Response
    {
        $filters = [
            'q'              => (string) ($request->input('q') ?? ''),
            'type'           => (string) ($request->input('type') ?? ''),
            'category_id'    => $request->integer('category_id'),
            'sector_id'      => $request->integer('sector_id'),
            'governorate_id' => $request->integer('governorate_id'),
            'price_min'      => $request->input('price_min'),
            'price_max'      => $request->input('price_max'),
            'pricing_mode'   => (string) ($request->input('pricing_mode') ?? ''),
            'sort'           => (string) ($request->input('sort') ?? 'newest'),
        ];

        $perPage = (int) \App\Services\SettingsService::int('marketplace', 'listings_per_page', 12);
        $results = $this->listings->searchPublic($filters, $this->page($request), $perPage);

        return $this->view('public/marketplace/index', [
            'pageTitle'       => trim($filters['q']) !== ''
                ? 'نتائج البحث عن: ' . $filters['q']
                : 'سوق المشروعات',
            'metaDescription' => 'تصفّح منتجات وخدمات المشروعات الصغيرة والمتوسطة الموثّقة في مصر.',
            'results'         => $results,
            'filters'         => $filters,
            'categories'      => $this->categories(),
            'sectors'         => $this->sectors(),
            'governorates'    => $this->governorates(),
        ], 'public');
    }

    /** صفحة الصنف | Listing detail page. */
    public function show(Request $request): Response
    {
        $slug    = (string) $request->route('slug');
        $listing = $this->listings->findPublicBySlug($slug);

        if ($listing === null) {
            throw new HttpException(404, 'الصنف المطلوب غير موجود أو لم يعد متاحاً.');
        }

        $this->listings->incrementViews((int) $listing['id']);

        return $this->view('public/marketplace/show', [
            'pageTitle'       => (string) $listing['name_ar'],
            'metaDescription' => str_excerpt((string) ($listing['short_description'] ?? ''), 155),
            'listing'         => $listing,
            'images'          => $this->listings->images((int) $listing['id']),
            'related'         => $this->listings->publishedForOrganization((int) $listing['organization_id'], 4),
            'reviews'         => $this->reviewsFor((int) $listing['id']),
        ], 'public');
    }

    /**
     * الصفحة التعريفية للمنشأة | The SME storefront (§4.3).
     * المسار: /business/{slug}
     */
    public function businessPage(Request $request): Response
    {
        $slug = (string) $request->route('slug');
        $data = $this->pages->publicPageBySlug($slug);

        if ($data === null) {
            throw new HttpException(404, 'الصفحة المطلوبة غير موجودة أو لم تُنشر بعد.');
        }

        $organizationId = (int) $data['organization']['id'];
        $this->pages->incrementViews($organizationId);

        $page = $data['page'];

        return $this->view('public/business/show', [
            'pageTitle'       => (string) ($page['meta_title'] ?: ($data['organization']['trading_name']
                ?: $data['organization']['legal_name'])),
            'metaDescription' => (string) ($page['meta_description']
                ?: str_excerpt((string) $data['organization']['short_description'], 155)),
            'organization'    => $data['organization'],
            'page'            => $page,
            'sections'        => $data['sections'],
            'gallery'         => $data['gallery'],
            'certificates'    => $data['certificates'],
            'products'        => $this->listingsOfType($organizationId, 'product'),
            'services'        => $this->listingsOfType($organizationId, 'service'),
            'reviews'         => $this->reviewsForOrganization($organizationId),
        ], 'public');
    }

    /** دليل الأعمال | Verified business directory (§4.1). */
    public function directory(Request $request): Response
    {
        $term          = trim((string) ($request->input('q') ?? ''));
        $sectorId      = $request->integer('sector_id');
        $governorateId = $request->integer('governorate_id');

        $where    = ["o.status = 'verified'", 'o.deleted_at IS NULL', "t.code = 'sme'",
                     "pp.status = 'published'"];
        $bindings = [];

        if ($term !== '') {
            $where[]    = '(o.legal_name LIKE ? OR o.trading_name LIKE ? OR o.short_description LIKE ?)';
            $like       = '%' . $term . '%';
            $bindings   = array_merge($bindings, [$like, $like, $like]);
        }

        if ($sectorId !== null) {
            $where[]    = 'o.sector_id = ?';
            $bindings[] = $sectorId;
        }

        if ($governorateId !== null) {
            $where[]    = 'o.governorate_id = ?';
            $bindings[] = $governorateId;
        }

        $whereSql = implode(' AND ', $where);

        $page    = $this->page($request);
        $perPage = 12;
        $offset  = ($page - 1) * $perPage;

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM organizations o
               JOIN organization_types t ON t.id = o.organization_type_id
               JOIN public_pages pp ON pp.organization_id = o.id
              WHERE {$whereSql}",
            $bindings,
        );

        $rows = Database::select(
            "SELECT o.id, o.legal_name, o.trading_name, o.slug, o.short_description,
                    o.logo_media_id, s.name_ar AS sector_name, g.name_ar AS governorate_name,
                    (SELECT COUNT(*) FROM listings l
                      WHERE l.organization_id = o.id AND l.status = 'published'
                        AND l.deleted_at IS NULL) AS listing_count
               FROM organizations o
               JOIN organization_types t ON t.id = o.organization_type_id
               JOIN public_pages pp ON pp.organization_id = o.id
               LEFT JOIN sectors s ON s.id = o.sector_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
              WHERE {$whereSql}
              ORDER BY o.completion_score DESC, o.verified_at DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return $this->view('public/directory', [
            'pageTitle'    => 'دليل الأعمال',
            'metaDescription' => 'دليل المشروعات الصغيرة والمتوسطة الموثّقة على منصة رواد النيل.',
            'results'      => [
                'data'      => $rows,
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'filters'      => ['q' => $term, 'sector_id' => $sectorId, 'governorate_id' => $governorateId],
            'sectors'      => $this->sectors(),
            'governorates' => $this->governorates(),
        ], 'public');
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function listingsOfType(int $organizationId, string $type): array
    {
        return Database::select(
            "SELECT l.id, l.name_ar, l.slug, l.short_description, l.pricing_mode,
                    l.price, l.currency_code, l.unit_of_measure, l.primary_media_id,
                    l.rating_average, l.rating_count, l.min_order_quantity
               FROM listings l
              WHERE l.organization_id = ? AND l.listing_type = ?
                AND l.status = 'published' AND l.deleted_at IS NULL
              ORDER BY l.is_featured DESC, l.published_at DESC
              LIMIT 24",
            [$organizationId, $type],
        );
    }

    private function reviewsFor(int $listingId): array
    {
        return Database::select(
            "SELECT r.rating, r.comment, r.customer_name, r.created_at, r.seller_reply
               FROM reviews r
              WHERE r.listing_id = ? AND r.status = 'published' AND r.deleted_at IS NULL
              ORDER BY r.created_at DESC
              LIMIT 10",
            [$listingId],
        );
    }

    private function reviewsForOrganization(int $organizationId): array
    {
        return Database::select(
            "SELECT r.rating, r.comment, r.customer_name, r.created_at, r.seller_reply
               FROM reviews r
              WHERE r.organization_id = ? AND r.status = 'published' AND r.deleted_at IS NULL
              ORDER BY r.created_at DESC
              LIMIT 10",
            [$organizationId],
        );
    }

    private function categories(): array
    {
        return Database::select(
            "SELECT id, name_ar FROM categories
              WHERE type = 'listing' AND is_active = 1 ORDER BY sort_order ASC",
        );
    }

    private function sectors(): array
    {
        return Database::select('SELECT id, name_ar FROM sectors WHERE is_active = 1 ORDER BY sort_order ASC');
    }

    private function governorates(): array
    {
        return Database::select('SELECT id, name_ar FROM governorates WHERE is_active = 1 ORDER BY sort_order ASC');
    }
}
