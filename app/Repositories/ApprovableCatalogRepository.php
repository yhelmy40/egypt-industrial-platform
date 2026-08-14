<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * أساس مستودعات الكتالوجات المعتمدة | Base for approval-gated catalogues (§4.5, §4.6).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * المنتجات التمويلية وباقات الخدمات لهما نفس ثلاثية الوجوه: تصفّح عام، إدارة
 * من المزوّد، وطابور مراجعة إداري. وأهمها **حدّ الرؤية العامة**، وهو هنا شرط
 * ثابت لا خيار تصفية:
 *
 *     status = 'published'  و  الجهة المالكة  status = 'verified'  و  غير محذوف
 *
 * كتابته مرة واحدة في `publicConstraints()` تعني أن أي استعلام عام في المشروع
 * يمرّ من نفس البوابة. نسخه في مستودعين كان يعني احتمال تشديده في أحدهما دون
 * الآخر — وهو تسريب لا يظهر في أي اختبار سطحي.
 *
 * One visibility boundary, written once: published item, verified owner, not
 * deleted. Duplicating it across two repositories would invite tightening one
 * and forgetting the other.
 * ═══════════════════════════════════════════════════════════════════════════
 */
abstract class ApprovableCatalogRepository
{
    /** اسم الجدول | Catalogue table name. */
    abstract protected function table(): string;

    /**
     * أعمدة البطاقة العامة | Columns selected for public cards.
     *
     * @return array<int,string> بأسماء مؤهَّلة بالبادئة `t.`
     */
    abstract protected function publicColumns(): array;

    /**
     * خيارات الترتيب المسموح بها | Allow-listed sort options.
     *
     * الترتيب يصل من شريط العنوان. القائمة البيضاء هي ما يمنع تحوّله إلى
     * جملة SQL حرة، ولذلك لا تُبنى القيمة من المُدخل إطلاقاً.
     *
     * @return array<string,string>
     */
    abstract protected function sorts(): array;

    /**
     * تصفية خاصة بالكتالوج | Catalogue-specific filters.
     *
     * @param  array<string,mixed> $filters
     * @param  array<int,string>   $where     تُضاف إليها الشروط
     * @param  array<int,mixed>    $bindings  تُضاف إليها القيم
     */
    abstract protected function applyFilters(array $filters, array &$where, array &$bindings): void;

    // ═══════════════════ التصفّح العام | Public browsing ═══════════════════

    /**
     * @param  array<string,mixed> $filters
     * @return array{data:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function searchPublic(array $filters, int $page = 1, int $perPage = 12): array
    {
        [$where, $bindings] = $this->publicConstraints();

        $term = trim((string) ($filters['q'] ?? ''));

        if ($term !== '') {
            $where[]    = '(MATCH(t.name_ar, t.short_description, t.description) AGAINST (? IN BOOLEAN MODE)
                            OR t.name_ar LIKE ? OR t.short_description LIKE ?)';
            $bindings[] = $this->booleanTerm($term);
            $bindings[] = '%' . $term . '%';
            $bindings[] = '%' . $term . '%';
        }

        $this->applyFilters($filters, $where, $bindings);

        $whereSql = implode(' AND ', $where);
        $table    = $this->table();

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM `{$table}` t
               JOIN organizations o ON o.id = t.organization_id
              WHERE {$whereSql}",
            $bindings,
        );

        $sorts   = $this->sorts();
        $sortKey = (string) ($filters['sort'] ?? array_key_first($sorts));
        $orderBy = $sorts[$sortKey] ?? $sorts[array_key_first($sorts)];

        $perPage = min(48, max(1, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $columns = implode(', ', $this->publicColumns());

        $rows = Database::select(
            "SELECT {$columns},
                    o.id AS provider_id, o.legal_name, o.trading_name, o.slug AS provider_slug,
                    o.logo_media_id AS provider_logo, o.is_demo AS provider_is_demo,
                    g.name_ar AS governorate_name,
                    c.name_ar AS category_name
               FROM `{$table}` t
               JOIN organizations o ON o.id = t.organization_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN categories c ON c.id = t.category_id
              WHERE {$whereSql}
              ORDER BY {$orderBy}
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

    /** عنصر عام بالمعرّف النصّي | A public item by slug. */
    public function findPublicBySlug(string $slug): ?array
    {
        [$where, $bindings] = $this->publicConstraints();

        $where[]    = 't.slug = ?';
        $bindings[] = $slug;

        $whereSql = implode(' AND ', $where);
        $table    = $this->table();

        return Database::selectOne(
            "SELECT t.*,
                    o.id AS provider_id, o.legal_name, o.trading_name, o.slug AS provider_slug,
                    o.logo_media_id AS provider_logo, o.is_demo AS provider_is_demo,
                    o.public_phone AS provider_phone,
                    g.name_ar AS governorate_name,
                    c.name_ar AS category_name
               FROM `{$table}` t
               JOIN organizations o ON o.id = t.organization_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN categories c ON c.id = t.category_id
              WHERE {$whereSql}
              LIMIT 1",
            $bindings,
        );
    }

    /**
     * عنصر عام بالمعرّف الرقمي | A public item by id.
     *
     * يُستخدم عند تقديم طلب على عنصر: التحقق من أنه ما زال معتمداً ومنشوراً
     * لحظة التقديم، لا لحظة عرض النموذج.
     */
    public function findPublicById(int $id): ?array
    {
        [$where, $bindings] = $this->publicConstraints();

        $where[]    = 't.id = ?';
        $bindings[] = $id;

        $whereSql = implode(' AND ', $where);
        $table    = $this->table();

        return Database::selectOne(
            "SELECT t.*, o.legal_name, o.trading_name, o.slug AS provider_slug
               FROM `{$table}` t
               JOIN organizations o ON o.id = t.organization_id
              WHERE {$whereSql}
              LIMIT 1",
            $bindings,
        );
    }

    // ═══════════════════ جانب المزوّد | Provider side ═══════════════════

    /**
     * عناصر المزوّد | The provider's own items, in every status.
     *
     * `organization_id` وسيط إلزامي لا افتراضي: لا يوجد في هذا المستودع مسار
     * واحد يقرأ عناصر مزوّد دون تمرير هويته صراحةً.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forOrganization(int $organizationId, ?string $status = null, int $limit = 100): array
    {
        $where    = ['t.organization_id = ?', 't.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if ($status !== null && $status !== '') {
            $where[]    = 't.status = ?';
            $bindings[] = $status;
        }

        $whereSql = implode(' AND ', $where);
        $table    = $this->table();
        $limit    = min(200, max(1, $limit));

        return Database::select(
            "SELECT t.*, c.name_ar AS category_name
               FROM `{$table}` t
               LEFT JOIN categories c ON c.id = t.category_id
              WHERE {$whereSql}
              ORDER BY FIELD(t.status,'rejected','draft','pending_review','published','archived'),
                       t.updated_at DESC
              LIMIT {$limit}",
            $bindings,
        );
    }

    /** عنصر مملوك للمزوّد | One item owned by the provider (404 semantics: null). */
    public function findOwned(int $id, int $organizationId): ?array
    {
        return Database::selectOne(
            "SELECT * FROM `{$this->table()}`
              WHERE id = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1",
            [$id, $organizationId],
        );
    }

    /** @return array<string,int> */
    public function countsByStatus(int $organizationId): array
    {
        $rows = Database::select(
            "SELECT status, COUNT(*) AS total FROM `{$this->table()}`
              WHERE organization_id = ? AND deleted_at IS NULL GROUP BY status",
            [$organizationId],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    // ═══════════════════ طابور المراجعة | Moderation queue ═══════════════════

    /**
     * طابور المراجعة الإداري | The admin queue — deliberately cross-organization.
     *
     * @return array{data:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function moderationQueue(string $status, int $page = 1, int $perPage = 20): array
    {
        $table    = $this->table();
        $bindings = [$status];

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM `{$table}` t WHERE t.status = ? AND t.deleted_at IS NULL",
            $bindings,
        );

        $perPage = min(100, max(1, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            "SELECT t.*, o.legal_name, o.trading_name, o.status AS provider_status,
                    o.slug AS provider_slug, c.name_ar AS category_name
               FROM `{$table}` t
               JOIN organizations o ON o.id = t.organization_id
               LEFT JOIN categories c ON c.id = t.category_id
              WHERE t.status = ? AND t.deleted_at IS NULL
              ORDER BY t.updated_at ASC
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
    public function platformCountsByStatus(): array
    {
        $rows = Database::select(
            "SELECT status, COUNT(*) AS total FROM `{$this->table()}`
              WHERE deleted_at IS NULL GROUP BY status",
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /** عنصر بلا قيد منشأة، للمراجعة الإدارية | Cross-organization fetch for moderation. */
    public function findForModeration(int $id): ?array
    {
        return Database::selectOne(
            "SELECT t.*, o.legal_name, o.trading_name, o.status AS provider_status,
                    o.slug AS provider_slug, o.id AS provider_id,
                    g.name_ar AS governorate_name, c.name_ar AS category_name
               FROM `{$this->table()}` t
               JOIN organizations o ON o.id = t.organization_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN categories c ON c.id = t.category_id
              WHERE t.id = ? AND t.deleted_at IS NULL
              LIMIT 1",
            [$id],
        );
    }

    public function incrementViews(int $id): void
    {
        Database::statement(
            "UPDATE `{$this->table()}` SET view_count = view_count + 1 WHERE id = ?",
            [$id],
        );
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /**
     * حدّ الرؤية العامة | The public visibility boundary.
     *
     * @return array{0:array<int,string>,1:array<int,mixed>}
     */
    protected function publicConstraints(): array
    {
        return [
            [
                "t.status = 'published'",
                't.deleted_at IS NULL',
                "o.status = 'verified'",
                'o.deleted_at IS NULL',
            ],
            [],
        ];
    }

    /** تجهيز كلمة البحث لوضع BOOLEAN | Prepare a boolean-mode search term. */
    protected function booleanTerm(string $term): string
    {
        $clean = preg_replace('/[+\-><\(\)~*\"@]+/u', ' ', $term) ?? $term;
        $words = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(static fn (string $w): string => $w . '*', $words));
    }

    /**
     * مطابقة قائمة معرّفات مخزَّنة كنص | Match against a stored comma-separated id list.
     *
     * الفارغ يعني «الكل»، فالشرط يقبل الصف إذا كانت القائمة فارغة أو تحوي المعرّف.
     * FIND_IN_SET أدقّ من LIKE هنا: LIKE '%1%' يطابق 11 و21 أيضاً.
     *
     * @param array<int,string> $where
     * @param array<int,mixed>  $bindings
     */
    protected function matchIdList(string $column, ?int $id, array &$where, array &$bindings): void
    {
        if ($id === null || $id <= 0) {
            return;
        }

        $where[]    = "({$column} IS NULL OR {$column} = '' OR FIND_IN_SET(?, {$column}))";
        $bindings[] = $id;
    }
}
