<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع المحتوى | Content repository (§4.11).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **حدّ الرؤية العامة مكتوب مرة واحدة** في `publicConstraint()`: منشور وغير
 * محذوف. كل استعلام عام يمرّ منه، فلا يمكن أن يُضاف عرض جديد ينسى الشرط
 * ويكشف مسوّدة أو مادة مرفوضة.
 *
 * المحتوى هنا **ملك المنصة لا منشأة**، فلا تقييد بالمستأجر: قاعدة العزل بين
 * المنشآت لا تنطبق على مادة تُنشر للعامة باسم المبادرة.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class ContentRepository extends BaseRepository
{
    protected string $table = 'articles';

    /** محتوى المنصة ليس مملوكاً لمنشأة | Platform content has no tenant. */
    protected bool $tenantScoped = false;

    protected bool $softDeletes = true;

    protected array $sortable = ['id', 'title_ar', 'status', 'published_at', 'view_count'];

    // ═══════════════════ حدّ الرؤية العامة | The public visibility bound ═══════════════════

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function publicConstraint(string $alias = 'a'): array
    {
        return ["{$alias}.status = 'published' AND {$alias}.deleted_at IS NULL", []];
    }

    // ═══════════════════ المقالات | Articles ═══════════════════

    /**
     * تصفّح مركز المعرفة | Public knowledge-centre browsing.
     *
     * @param  array<string,mixed> $filters
     * @return array{data:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function publicArticles(array $filters = [], int $page = 1, int $perPage = 9): array
    {
        [$constraint, $bindings] = $this->publicConstraint();
        $where                   = [$constraint];

        if (($filters['category'] ?? '') !== '') {
            $where[]    = 'c.code = ?';
            $bindings[] = $filters['category'];
        }

        if (($filters['q'] ?? '') !== '') {
            $where[]    = '(a.title_ar LIKE ? OR a.excerpt_ar LIKE ?)';
            $term       = '%' . $filters['q'] . '%';
            $bindings[] = $term;
            $bindings[] = $term;
        }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM articles a
          LEFT JOIN categories c ON c.id = a.category_id
              WHERE {$clause}",
            $bindings,
        );

        $perPage = max(1, min(48, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            "SELECT a.id, a.title_ar, a.slug, a.excerpt_ar, a.published_at, a.reading_minutes,
                    a.is_featured, a.view_count, a.is_demo, a.author_name_ar,
                    c.name_ar AS category_name, c.code AS category_code,
                    m.disk_path AS cover_path
               FROM articles a
          LEFT JOIN categories c ON c.id = a.category_id
          LEFT JOIN media m ON m.id = a.cover_media_id
              WHERE {$clause}
           ORDER BY a.is_featured DESC, a.published_at DESC, a.id DESC
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

    /** مقال منشور بمُعرّفه النصّي | A published article by slug. */
    public function publicArticle(string $slug): ?array
    {
        [$constraint] = $this->publicConstraint();

        return Database::selectOne(
            "SELECT a.*, c.name_ar AS category_name, c.code AS category_code,
                    m.disk_path AS cover_path
               FROM articles a
          LEFT JOIN categories c ON c.id = a.category_id
          LEFT JOIN media m ON m.id = a.cover_media_id
              WHERE a.slug = ? AND {$constraint}
              LIMIT 1",
            [$slug],
        );
    }

    /** مقالات ذات صلة | Related articles from the same category. */
    public function relatedArticles(int $articleId, ?int $categoryId, int $limit = 3): array
    {
        [$constraint] = $this->publicConstraint();
        $limit        = max(1, min(12, $limit));

        return Database::select(
            "SELECT a.id, a.title_ar, a.slug, a.excerpt_ar, a.published_at
               FROM articles a
              WHERE {$constraint} AND a.id <> ?
                AND (? IS NULL OR a.category_id = ?)
           ORDER BY a.published_at DESC
              LIMIT {$limit}",
            [$articleId, $categoryId, $categoryId],
        );
    }

    /**
     * قائمة الإدارة | The administration listing — every status.
     *
     * @param  array<string,mixed> $filters
     * @return array{data:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function adminArticles(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where    = ['a.deleted_at IS NULL'];
        $bindings = [];

        if (($filters['status'] ?? '') !== '') {
            $where[]    = 'a.status = ?';
            $bindings[] = $filters['status'];
        }

        if (($filters['q'] ?? '') !== '') {
            $where[]    = 'a.title_ar LIKE ?';
            $bindings[] = '%' . $filters['q'] . '%';
        }

        $clause  = implode(' AND ', $where);
        $total   = (int) Database::scalar("SELECT COUNT(*) FROM articles a WHERE {$clause}", $bindings);
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            "SELECT a.*, c.name_ar AS category_name,
                    au.name AS author_account_name, pu.name AS publisher_name
               FROM articles a
          LEFT JOIN categories c ON c.id = a.category_id
          LEFT JOIN users au ON au.id = a.author_id
          LEFT JOIN users pu ON pu.id = a.published_by
              WHERE {$clause}
           ORDER BY FIELD(a.status,'pending_review','draft','rejected','published','archived'),
                    a.updated_at DESC
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

    public function findArticle(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$id],
        );
    }

    /** @return array<string,int> */
    public function articleCountsByStatus(): array
    {
        $rows = Database::select(
            'SELECT status, COUNT(*) AS total FROM articles WHERE deleted_at IS NULL GROUP BY status',
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /** تسجيل مشاهدة | Record a view (best effort, never blocks the page). */
    public function recordArticleView(int $articleId): void
    {
        Database::statement('UPDATE articles SET view_count = view_count + 1 WHERE id = ?', [$articleId]);
    }

    // ═══════════════════ الأسئلة الشائعة | FAQs ═══════════════════

    /** @return array<string,array<int,array<string,mixed>>> */
    public function publicFaqs(): array
    {
        $rows = Database::select(
            "SELECT id, section, question_ar, answer_ar, is_demo
               FROM faqs
              WHERE status = 'published' AND deleted_at IS NULL
           ORDER BY FIELD(section,'general','account','marketplace','financing',
                          'services','bds','erp','privacy'), sort_order ASC, id ASC",
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row['section']][] = $row;
        }

        return $grouped;
    }

    /** @return array<int,array<string,mixed>> */
    public function adminFaqs(string $status = ''): array
    {
        $where    = ['f.deleted_at IS NULL'];
        $bindings = [];

        if ($status !== '') {
            $where[]    = 'f.status = ?';
            $bindings[] = $status;
        }

        $clause = implode(' AND ', $where);

        return Database::select(
            "SELECT f.*, u.name AS publisher_name
               FROM faqs f
          LEFT JOIN users u ON u.id = f.published_by
              WHERE {$clause}
           ORDER BY FIELD(f.status,'pending_review','draft','rejected','published','archived'),
                    f.section ASC, f.sort_order ASC, f.id ASC
              LIMIT 500",
            $bindings,
        );
    }

    public function findFaq(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM faqs WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$id],
        );
    }

    // ═══════════════════ الصفحات الثابتة | Static pages ═══════════════════

    /** صفحة منشورة | A published static page. */
    public function publicPage(string $slug): ?array
    {
        return Database::selectOne(
            "SELECT * FROM static_pages WHERE slug = ? AND status = 'published' LIMIT 1",
            [$slug],
        );
    }

    public function findPageBySlug(string $slug): ?array
    {
        return Database::selectOne('SELECT * FROM static_pages WHERE slug = ? LIMIT 1', [$slug]);
    }

    public function findPage(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM static_pages WHERE id = ? LIMIT 1', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function allPages(): array
    {
        return Database::select(
            'SELECT p.*, u.name AS editor_name
               FROM static_pages p
          LEFT JOIN users u ON u.id = p.updated_by
           ORDER BY p.slug ASC',
        );
    }

    /** تصنيفات المقالات | Article categories. */
    public function articleCategories(): array
    {
        return Database::select(
            "SELECT id, code, name_ar FROM categories
              WHERE type = 'article' AND is_active = 1
           ORDER BY sort_order ASC, name_ar ASC",
        );
    }
}
