<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع المنشآت | Organization repository.
 *
 * `organizations` هو جذر المستأجر نفسه، لذا لا يُقيَّد بـ organization_id
 * (لا معنى لتقييد الجذر بنفسه). الحماية هنا تأتي من السياسات: من يقرأ أي
 * منشأة يحتاج إمّا عضوية فيها أو صلاحية org.account.view_any.
 * `organizations` is the tenant root, so it is not scoped by organization_id
 * (scoping a root by itself is meaningless). Protection comes from policies:
 * reading an organization requires membership or the org.account.view_any permission.
 */
final class OrganizationRepository extends BaseRepository
{
    protected string $table = 'organizations';

    protected bool $tenantScoped = false;

    protected bool $softDeletes = true;

    protected array $sortable = [
        'id', 'legal_name', 'status', 'created_at', 'submitted_at',
        'verified_at', 'completion_score',
    ];

    public function findBySlug(string $slug): ?array
    {
        return Database::selectOne(
            'SELECT o.*, t.code AS type_code, t.name_ar AS type_name,
                    s.name_ar AS sector_name, g.name_ar AS governorate_name,
                    c.name_ar AS city_name
               FROM organizations o
               JOIN organization_types t ON t.id = o.organization_type_id
               LEFT JOIN sectors s ON s.id = o.sector_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN cities c ON c.id = o.city_id
              WHERE o.slug = ? AND o.deleted_at IS NULL
              LIMIT 1',
            [$slug],
        );
    }

    /** تفاصيل كاملة مع البيانات المرجعية | Full record with reference labels. */
    public function findWithDetails(int $id): ?array
    {
        return Database::selectOne(
            'SELECT o.*, t.code AS type_code, t.name_ar AS type_name,
                    s.name_ar AS sector_name, ss.name_ar AS sub_sector_name,
                    g.name_ar AS governorate_name, c.name_ar AS city_name,
                    owner.name AS owner_name, owner.email AS owner_email,
                    verifier.name AS verified_by_name
               FROM organizations o
               JOIN organization_types t ON t.id = o.organization_type_id
               LEFT JOIN sectors s ON s.id = o.sector_id
               LEFT JOIN sub_sectors ss ON ss.id = o.sub_sector_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN cities c ON c.id = o.city_id
               LEFT JOIN users owner ON owner.id = o.owner_user_id
               LEFT JOIN users verifier ON verifier.id = o.verified_by
              WHERE o.id = ? AND o.deleted_at IS NULL
              LIMIT 1',
            [$id],
        );
    }

    /**
     * توليد معرّف فريد للصفحة العامة | Generate a unique public slug.
     *
     * يدعم العربية عبر إبقاء الحروف العربية في المعرّف، مع بديل لاتيني إذا لم
     * يتبقَّ شيء صالح.
     * Arabic characters are preserved in the slug, with a Latin fallback when
     * nothing usable remains.
     */
    public function generateUniqueSlug(string $name, ?int $exceptId = null): string
    {
        $base = $this->slugify($name);

        if ($base === '') {
            $base = 'business';
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

    private function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value));
        // إبقاء العربية والإنجليزية والأرقام فقط
        $value = (string) preg_replace('/[^\p{Arabic}a-z0-9\s\-]/u', '', $value);
        $value = (string) preg_replace('/[\s\-]+/u', '-', $value);

        return trim(mb_substr($value, 0, 120), '-');
    }

    private function slugExists(string $slug, ?int $exceptId): bool
    {
        $sql      = 'SELECT 1 FROM organizations WHERE slug = ?';
        $bindings = [$slug];

        if ($exceptId !== null) {
            $sql       .= ' AND id != ?';
            $bindings[] = $exceptId;
        }

        return Database::scalar($sql . ' LIMIT 1', $bindings) !== null;
    }

    /**
     * طابور المراجعة الإداري | Admin verification queue.
     *
     * @return array{data:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function adminList(
        ?string $status = null,
        ?string $typeCode = null,
        ?int $governorateId = null,
        string $term = '',
        int $page = 1,
        int $perPage = 20,
    ): array {
        $where    = ['o.deleted_at IS NULL'];
        $bindings = [];

        if ($status !== null && $status !== '') {
            $where[]    = 'o.status = ?';
            $bindings[] = $status;
        }

        if ($typeCode !== null && $typeCode !== '') {
            $where[]    = 't.code = ?';
            $bindings[] = $typeCode;
        }

        if ($governorateId !== null) {
            $where[]    = 'o.governorate_id = ?';
            $bindings[] = $governorateId;
        }

        if (trim($term) !== '') {
            $where[]    = '(o.legal_name LIKE ? OR o.trading_name LIKE ? OR o.slug LIKE ?)';
            $like       = '%' . trim($term) . '%';
            $bindings   = array_merge($bindings, [$like, $like, $like]);
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM organizations o
               JOIN organization_types t ON t.id = o.organization_type_id
              WHERE {$whereSql}",
            $bindings,
        );

        $perPage = min(100, max(1, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            "SELECT o.id, o.legal_name, o.trading_name, o.slug, o.status,
                    o.completion_score, o.created_at, o.submitted_at, o.is_demo,
                    t.name_ar AS type_name, t.code AS type_code,
                    s.name_ar AS sector_name, g.name_ar AS governorate_name,
                    u.name AS owner_name
               FROM organizations o
               JOIN organization_types t ON t.id = o.organization_type_id
               LEFT JOIN sectors s ON s.id = o.sector_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN users u ON u.id = o.owner_user_id
              WHERE {$whereSql}
              ORDER BY FIELD(o.status,'submitted','under_review','more_info_required','verified','draft','rejected','suspended'),
                       o.submitted_at DESC, o.created_at DESC
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

    /** @return array<string,int> إحصاء المنشآت حسب الحالة */
    public function countsByStatus(): array
    {
        $rows = Database::select(
            'SELECT status, COUNT(*) AS total
               FROM organizations
              WHERE deleted_at IS NULL
              GROUP BY status',
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return array<int,array<string,mixed>> */
    public function countsByGovernorate(int $limit = 10): array
    {
        $limit = min(30, max(1, $limit));

        return Database::select(
            "SELECT g.name_ar AS governorate, COUNT(o.id) AS total
               FROM organizations o
               JOIN governorates g ON g.id = o.governorate_id
              WHERE o.deleted_at IS NULL AND o.status = 'verified'
              GROUP BY g.id, g.name_ar
              ORDER BY total DESC
              LIMIT {$limit}",
        );
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null, ?string $reason = null): int
    {
        $extra    = '';
        $bindings = [$status];

        if ($status === 'verified') {
            $extra    = ', verified_at = NOW(), verified_by = ?, rejection_reason = NULL';
            $bindings[] = $actorId;
        } elseif ($status === 'rejected' || $status === 'more_info_required') {
            $extra      = ', rejection_reason = ?';
            $bindings[] = $reason === null ? null : mb_substr($reason, 0, 1000);
        } elseif ($status === 'suspended') {
            $extra      = ', suspended_at = NOW(), suspension_reason = ?';
            $bindings[] = $reason === null ? null : mb_substr($reason, 0, 1000);
        } elseif ($status === 'submitted') {
            $extra = ', submitted_at = NOW()';
        }

        $bindings[] = $id;

        return Database::affectingStatement(
            "UPDATE organizations SET status = ?{$extra} WHERE id = ? AND deleted_at IS NULL",
            $bindings,
        );
    }

    public function updateCompletionScore(int $id, int $score): void
    {
        Database::statement(
            'UPDATE organizations SET completion_score = ? WHERE id = ?',
            [max(0, min(100, $score)), $id],
        );
    }
}
