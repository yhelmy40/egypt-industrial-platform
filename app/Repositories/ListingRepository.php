<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع الإعلانات | Listing repository (§4.4, §11).
 *
 * يجمع بين وجهين: إدارة المنشأة لإعلاناتها (مقيَّدة بالمستأجر)، والتصفّح العام
 * للسوق (غير مقيَّد بالمستأجر لكنه مقيَّد بحالة النشر وحالة المنشأة).
 * Two faces: an organization managing its own listings (tenant-scoped), and
 * public marketplace browsing (not tenant-scoped, but strictly bounded by
 * publication status and seller verification).
 */
final class ListingRepository extends BaseRepository
{
    protected string $table = 'listings';

    protected bool $tenantScoped = true;

    protected bool $softDeletes = true;

    protected array $sortable = [
        'id', 'name_ar', 'price', 'status', 'created_at', 'updated_at',
        'view_count', 'order_count', 'rating_average',
    ];

    /** خيارات ترتيب السوق العام | Public sort options (allow-listed). */
    private const PUBLIC_SORTS = [
        'newest'      => 'l.published_at DESC, l.id DESC',
        'price_asc'   => 'l.price ASC',
        'price_desc'  => 'l.price DESC',
        'popular'     => 'l.order_count DESC, l.view_count DESC',
        'rating'      => 'l.rating_average DESC, l.rating_count DESC',
    ];

    /**
     * بحث السوق العام | Public marketplace search (§11).
     *
     * البحث بقاعدة البيانات فقط في هذه النسخة: FULLTEXT عند وجود كلمة بحث،
     * وإلا فتصفية مفهرسة. اللغة العربية مفصولة بمسافات فالمحلّل الافتراضي
     * يتعامل معها، مع بديل LIKE للكلمات الأقصر من الحد الأدنى للفهرس.
     * Database-only search: FULLTEXT when a term is present, indexed filtering
     * otherwise, with a LIKE fallback for terms shorter than the index minimum.
     *
     * @param  array<string,mixed> $filters
     * @return array{data:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function searchPublic(array $filters, int $page = 1, int $perPage = 12): array
    {
        // الشروط الأساسية: منشور + منشأة موثّقة + غير محذوف.
        // هذه ليست «تصفية» بل حدّ الرؤية العامة، ولذلك ليست اختيارية.
        $where = [
            "l.status = 'published'",
            'l.deleted_at IS NULL',
            "o.status = 'verified'",
            'o.deleted_at IS NULL',
        ];
        $bindings = [];

        $term = trim((string) ($filters['q'] ?? ''));

        if ($term !== '') {
            // مقارنة LIKE تكمّل الفهرس النصي للكلمات القصيرة والجزئية
            $where[]    = '(MATCH(l.name_ar, l.short_description, l.description) AGAINST (? IN BOOLEAN MODE)
                            OR l.name_ar LIKE ? OR l.short_description LIKE ?)';
            $bindings[] = $this->booleanTerm($term);
            $bindings[] = '%' . $term . '%';
            $bindings[] = '%' . $term . '%';
        }

        if (!empty($filters['type']) && in_array($filters['type'], ['product', 'service'], true)) {
            $where[]    = 'l.listing_type = ?';
            $bindings[] = $filters['type'];
        }

        if (!empty($filters['category_id'])) {
            $where[]    = 'l.category_id = ?';
            $bindings[] = (int) $filters['category_id'];
        }

        if (!empty($filters['sector_id'])) {
            $where[]    = 'o.sector_id = ?';
            $bindings[] = (int) $filters['sector_id'];
        }

        if (!empty($filters['governorate_id'])) {
            $where[]    = 'o.governorate_id = ?';
            $bindings[] = (int) $filters['governorate_id'];
        }

        if (isset($filters['price_min']) && is_numeric($filters['price_min'])) {
            $where[]    = 'l.price >= ?';
            $bindings[] = (float) $filters['price_min'];
        }

        if (isset($filters['price_max']) && is_numeric($filters['price_max'])) {
            $where[]    = 'l.price <= ?';
            $bindings[] = (float) $filters['price_max'];
        }

        if (!empty($filters['pricing_mode']) && in_array($filters['pricing_mode'], ['fixed', 'quote'], true)) {
            $where[]    = 'l.pricing_mode = ?';
            $bindings[] = $filters['pricing_mode'];
        }

        if (!empty($filters['organization_id'])) {
            $where[]    = 'l.organization_id = ?';
            $bindings[] = (int) $filters['organization_id'];
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM listings l JOIN organizations o ON o.id = l.organization_id WHERE {$whereSql}",
            $bindings,
        );

        $sortKey = (string) ($filters['sort'] ?? 'newest');
        $orderBy = self::PUBLIC_SORTS[$sortKey] ?? self::PUBLIC_SORTS['newest'];

        $perPage = min(48, max(1, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            "SELECT l.id, l.name_ar, l.slug, l.short_description, l.listing_type,
                    l.pricing_mode, l.price, l.currency_code, l.vat_rate,
                    l.unit_of_measure, l.min_order_quantity, l.lead_time_days,
                    l.primary_media_id, l.rating_average, l.rating_count, l.is_featured,
                    l.available_quantity, l.track_inventory,
                    o.id AS seller_id, o.legal_name, o.trading_name, o.slug AS seller_slug,
                    g.name_ar AS governorate_name, s.name_ar AS sector_name,
                    c.name_ar AS category_name
               FROM listings l
               JOIN organizations o ON o.id = l.organization_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN sectors s ON s.id = o.sector_id
               LEFT JOIN categories c ON c.id = l.category_id
              WHERE {$whereSql}
              ORDER BY l.is_featured DESC, {$orderBy}
              LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return [
            'data'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * تجهيز كلمة البحث لوضع BOOLEAN | Prepare a boolean-mode search term.
     * تُزال الرموز التي تحمل معنى خاصاً في محرّك البحث حتى لا يفسد الاستعلام.
     */
    private function booleanTerm(string $term): string
    {
        $clean = preg_replace('/[+\-><\(\)~*\"@]+/u', ' ', $term) ?? $term;
        $words = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(static fn (string $w): string => $w . '*', $words));
    }

    /** إعلان عام بالمعرّف النصّي | A public listing by slug. */
    public function findPublicBySlug(string $slug): ?array
    {
        return Database::selectOne(
            "SELECT l.*, o.legal_name, o.trading_name, o.slug AS seller_slug,
                    o.status AS seller_status, o.logo_media_id AS seller_logo,
                    g.name_ar AS governorate_name, s.name_ar AS sector_name,
                    c.name_ar AS category_name
               FROM listings l
               JOIN organizations o ON o.id = l.organization_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN sectors s ON s.id = o.sector_id
               LEFT JOIN categories c ON c.id = l.category_id
              WHERE l.slug = ?
                AND l.status = 'published'
                AND l.deleted_at IS NULL
                AND o.status = 'verified'
                AND o.deleted_at IS NULL
              LIMIT 1",
            [$slug],
        );
    }

    /** إعلانات منشأة للعرض العام | A seller's published listings. */
    public function publishedForOrganization(int $organizationId, int $limit = 24): array
    {
        $limit = min(100, max(1, $limit));

        return Database::select(
            "SELECT l.id, l.name_ar, l.slug, l.short_description, l.listing_type,
                    l.pricing_mode, l.price, l.currency_code, l.unit_of_measure,
                    l.primary_media_id, l.rating_average, l.rating_count
               FROM listings l
              WHERE l.organization_id = ?
                AND l.status = 'published'
                AND l.deleted_at IS NULL
              ORDER BY l.is_featured DESC, l.published_at DESC
              LIMIT {$limit}",
            [$organizationId],
        );
    }

    /** صور إعلان | A listing's images. */
    public function images(int $listingId): array
    {
        return Database::select(
            'SELECT li.*, m.original_name
               FROM listing_images li
               JOIN media m ON m.id = li.media_id
              WHERE li.listing_id = ?
              ORDER BY li.sort_order ASC, li.id ASC',
            [$listingId],
        );
    }

    /** قائمة إدارة المنشأة لإعلاناتها | The seller's own management list. */
    public function forOrganization(int $organizationId, ?string $status, string $term, int $page, int $perPage = 20): array
    {
        $where    = ['l.organization_id = ?', 'l.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if ($status !== null && $status !== '') {
            $where[]    = 'l.status = ?';
            $bindings[] = $status;
        }

        if (trim($term) !== '') {
            $where[]    = '(l.name_ar LIKE ? OR l.sku LIKE ?)';
            $bindings[] = '%' . trim($term) . '%';
            $bindings[] = '%' . trim($term) . '%';
        }

        $whereSql = implode(' AND ', $where);
        $total    = (int) Database::scalar("SELECT COUNT(*) FROM listings l WHERE {$whereSql}", $bindings);

        $perPage = min(100, max(1, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            "SELECT l.*, c.name_ar AS category_name
               FROM listings l
               LEFT JOIN categories c ON c.id = l.category_id
              WHERE {$whereSql}
              ORDER BY l.updated_at DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return [
            'data'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** @return array<string,int> */
    public function countsByStatus(int $organizationId): array
    {
        $rows = Database::select(
            'SELECT status, COUNT(*) AS total FROM listings
              WHERE organization_id = ? AND deleted_at IS NULL GROUP BY status',
            [$organizationId],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /** طابور مراجعة الإعلانات للإدارة | Admin moderation queue. */
    public function moderationQueue(string $status, int $page, int $perPage = 20): array
    {
        $bindings = [$status];
        $total    = (int) Database::scalar(
            'SELECT COUNT(*) FROM listings l WHERE l.status = ? AND l.deleted_at IS NULL',
            $bindings,
        );

        $perPage = min(100, max(1, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            "SELECT l.*, o.legal_name, o.trading_name, o.slug AS seller_slug,
                    c.name_ar AS category_name
               FROM listings l
               JOIN organizations o ON o.id = l.organization_id
               LEFT JOIN categories c ON c.id = l.category_id
              WHERE l.status = ? AND l.deleted_at IS NULL
              ORDER BY l.updated_at ASC
              LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return [
            'data'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function incrementViews(int $listingId): void
    {
        Database::statement('UPDATE listings SET view_count = view_count + 1 WHERE id = ?', [$listingId]);
    }

    /** إعادة حساب متوسط التقييم | Recompute the rating aggregate. */
    public function refreshRating(int $listingId): void
    {
        Database::statement(
            "UPDATE listings l
                SET l.rating_average = (
                        SELECT AVG(r.rating) FROM reviews r
                         WHERE r.listing_id = l.id AND r.status = 'published' AND r.deleted_at IS NULL
                    ),
                    l.rating_count = (
                        SELECT COUNT(*) FROM reviews r
                         WHERE r.listing_id = l.id AND r.status = 'published' AND r.deleted_at IS NULL
                    )
              WHERE l.id = ?",
            [$listingId],
        );
    }

    public function generateUniqueSlug(string $name, ?int $exceptId = null): string
    {
        $base = trim(mb_strtolower($name));
        $base = (string) preg_replace('/[^\p{Arabic}a-z0-9\s\-]/u', '', $base);
        $base = (string) preg_replace('/[\s\-]+/u', '-', $base);
        $base = trim(mb_substr($base, 0, 180), '-');

        if ($base === '') {
            $base = 'listing';
        }

        $slug    = $base;
        $counter = 1;

        while ($this->slugExists($slug, $exceptId)) {
            $counter++;
            $slug = $base . '-' . $counter;

            if ($counter > 200) {
                $slug = $base . '-' . bin2hex(random_bytes(3));
                break;
            }
        }

        return $slug;
    }

    private function slugExists(string $slug, ?int $exceptId): bool
    {
        $sql      = 'SELECT 1 FROM listings WHERE slug = ?';
        $bindings = [$slug];

        if ($exceptId !== null) {
            $sql       .= ' AND id != ?';
            $bindings[] = $exceptId;
        }

        return Database::scalar($sql . ' LIMIT 1', $bindings) !== null;
    }
}
