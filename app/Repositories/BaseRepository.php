<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Support\TenantContext;
use RuntimeException;

/**
 * المستودع الأساسي | Base repository with fail-closed tenant scoping (§7).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *  مبدأ العزل | The isolation principle
 * ═══════════════════════════════════════════════════════════════════════════
 * أي مستودع يخصّ جدولاً مملوكاً لمنشأة يجب أن يعلن `$tenantScoped = true`.
 * عندها لا يُبنى أي استعلام إلا بعد قرار صريح بالنطاق:
 *   - scopedToTenant()  → يقيّد بالمنشأة النشطة المستمدة من الجلسة المُتحقَّق منها
 *   - globalScope('سبب') → تجاوز صريح موثّق (للإدارة فقط) يُسجَّل في السجل
 *
 * الاستعلام بدون قرار نطاق يرمي استثناءً. النسيان يعني فشلاً صاخباً، لا تسريباً
 * صامتاً — وهذا هو الفرق بين العزل كخاصية أمنية وبين مجرّد «مرشِّح».
 *
 * A repository over an organization-owned table must declare
 * `$tenantScoped = true`. No query is then built without an explicit scope
 * decision. Forgetting throws, rather than silently leaking: this is what makes
 * isolation a security property instead of a filter someone might forget.
 * ═══════════════════════════════════════════════════════════════════════════
 */
abstract class BaseRepository
{
    /** اسم الجدول | Table name. */
    protected string $table = '';

    /**
     * هل الجدول مملوك لمنشأة؟ | Is this table organization-owned?
     * true ⇒ كل استعلام يحتاج قرار نطاق صريح.
     */
    protected bool $tenantScoped = false;

    /** اسم عمود الملكية | Ownership column. */
    protected string $tenantColumn = 'organization_id';

    /** هل يدعم الجدول الحذف الناعم؟ | Does the table use soft deletes? */
    protected bool $softDeletes = false;

    /**
     * أعمدة يُسمح بالترتيب حسبها | Sortable column allow-list.
     * تمنع حقن أسماء الأعمدة، إذ لا يمكن ربط المُعرِّفات كمعاملات.
     */
    protected array $sortable = ['id', 'created_at', 'updated_at'];

    // ---- حالة النطاق للاستعلام الجاري | Scope state for the current query ----

    private ?int $scopedOrganizationId = null;

    private bool $scopeDecided = false;

    private ?string $globalScopeReason = null;

    private bool $includeTrashed = false;

    /**
     * تقييد بالمنشأة | Scope to an organization.
     *
     * بدون وسيط: يستخدم المنشأة النشطة من TenantContext، وهي مستمدة من عضوية
     * مُتحقَّق منها في قاعدة البيانات وليست من المتصفح إطلاقاً.
     */
    public function scopedToTenant(?int $organizationId = null): static
    {
        $clone = clone $this;

        $resolved = $organizationId ?? TenantContext::organizationId();

        if ($resolved === null) {
            throw new RuntimeException(
                'تعذّر تحديد المنشأة النشطة — لا يمكن تنفيذ استعلام مقيّد بالمنشأة.'
            );
        }

        $clone->scopedOrganizationId = $resolved;
        $clone->scopeDecided         = true;
        $clone->globalScopeReason    = null;

        return $clone;
    }

    /**
     * تجاوز صريح للنطاق | Explicit cross-tenant scope.
     *
     * للاستخدام الإداري فقط (مراجعة، تدقيق، تقارير المنصة). السبب إلزامي
     * ويُسجَّل، حتى تبقى كل قراءة عابرة للمنشآت قابلة للتفسير والمراجعة.
     * Admin use only. The reason is mandatory and logged so every cross-tenant
     * read remains explainable during review.
     */
    public function globalScope(string $reason): static
    {
        if (trim($reason) === '') {
            throw new RuntimeException('التجاوز العام للنطاق يتطلّب سبباً صريحاً.');
        }

        $clone = clone $this;
        $clone->scopedOrganizationId = null;
        $clone->scopeDecided         = true;
        $clone->globalScopeReason    = $reason;

        if (Config::get('app.env') !== 'testing') {
            Logger::info('Cross-tenant query', [
                'repository' => static::class,
                'reason'     => $reason,
                'actor'      => TenantContext::userId(),
            ]);
        }

        return $clone;
    }

    /** تضمين السجلات المحذوفة ناعماً | Include soft-deleted rows. */
    public function withTrashed(): static
    {
        $clone                 = clone $this;
        $clone->includeTrashed = true;

        return $clone;
    }

    // ---------------- بناء الشروط | Constraint building ----------------

    /**
     * شرط النطاق | The scope predicate for the current query.
     *
     * @return array{0:string,1:array<int,mixed>} [sql, bindings]
     */
    protected function scopeConstraint(string $alias = ''): array
    {
        if ($this->tenantScoped && !$this->scopeDecided) {
            // فشل صاخب متعمَّد | Deliberate loud failure.
            throw new RuntimeException(sprintf(
                'استعلام غير مُقيَّد على جدول مملوك لمنشأة (%s). '
                . 'استخدم scopedToTenant() أو globalScope("سبب").',
                static::class,
            ));
        }

        $prefix     = $alias !== '' ? $alias . '.' : '';
        $conditions = [];
        $bindings   = [];

        if ($this->tenantScoped && $this->scopedOrganizationId !== null) {
            $conditions[] = $prefix . $this->tenantColumn . ' = ?';
            $bindings[]   = $this->scopedOrganizationId;
        }

        if ($this->softDeletes && !$this->includeTrashed) {
            $conditions[] = $prefix . 'deleted_at IS NULL';
        }

        return [
            $conditions === [] ? '1=1' : implode(' AND ', $conditions),
            $bindings,
        ];
    }

    // ---------------- عمليات القراءة | Read operations ----------------

    public function find(int $id): ?array
    {
        [$scope, $bindings] = $this->scopeConstraint();

        return Database::selectOne(
            "SELECT * FROM `{$this->table}` WHERE id = ? AND {$scope} LIMIT 1",
            array_merge([$id], $bindings),
        );
    }

    /**
     * جلب سجل أو رمي استثناء | Find or throw.
     *
     * يرمي 404 وليس 403 عند عدم الملكية: عدم كشف وجود سجلات المنشآت الأخرى
     * يمنع استنتاج معلومات عنها (§9 — مقاومة IDOR).
     * Throws 404, not 403, when the row belongs to another tenant: not
     * revealing existence prevents enumeration.
     */
    public function findOrFail(int $id): array
    {
        $row = $this->find($id);

        if ($row === null) {
            throw new \App\Core\Exceptions\HttpException(404, 'السجل المطلوب غير موجود.');
        }

        return $row;
    }

    public function findBy(string $column, mixed $value): ?array
    {
        $this->assertColumn($column);
        [$scope, $bindings] = $this->scopeConstraint();

        return Database::selectOne(
            "SELECT * FROM `{$this->table}` WHERE `{$column}` = ? AND {$scope} LIMIT 1",
            array_merge([$value], $bindings),
        );
    }

    /**
     * قائمة مقسّمة لصفحات | Paginated list.
     *
     * @param array<string,mixed> $filters
     * @return array{data:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function paginate(
        int $page = 1,
        int $perPage = 20,
        array $filters = [],
        string $orderBy = 'created_at',
        string $direction = 'DESC',
    ): array {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        [$scope, $bindings] = $this->scopeConstraint();
        [$where, $filterBindings] = $this->buildFilters($filters);

        $sql      = "FROM `{$this->table}` WHERE {$scope}" . $where;
        $allBinds = array_merge($bindings, $filterBindings);

        $total = (int) Database::scalar("SELECT COUNT(*) {$sql}", $allBinds);

        $orderBy   = $this->sanitizeSort($orderBy);
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $offset    = ($page - 1) * $perPage;

        // LIMIT/OFFSET مُدرجان كأعداد صحيحة مُتحقَّق منها، لا كمعاملات،
        // لأن MySQL لا يقبل ربطها في وضع التجهيز الحقيقي.
        $rows = Database::select(
            "SELECT * {$sql} ORDER BY `{$orderBy}` {$direction} LIMIT {$perPage} OFFSET {$offset}",
            $allBinds,
        );

        return [
            'data'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** @param array<string,mixed> $filters */
    public function count(array $filters = []): int
    {
        [$scope, $bindings]       = $this->scopeConstraint();
        [$where, $filterBindings] = $this->buildFilters($filters);

        return (int) Database::scalar(
            "SELECT COUNT(*) FROM `{$this->table}` WHERE {$scope}{$where}",
            array_merge($bindings, $filterBindings),
        );
    }

    /**
     * @param  array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function all(array $filters = [], string $orderBy = 'id', string $direction = 'ASC', int $limit = 500): array
    {
        [$scope, $bindings]       = $this->scopeConstraint();
        [$where, $filterBindings] = $this->buildFilters($filters);

        $orderBy   = $this->sanitizeSort($orderBy);
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $limit     = min(2000, max(1, $limit));

        return Database::select(
            "SELECT * FROM `{$this->table}` WHERE {$scope}{$where} ORDER BY `{$orderBy}` {$direction} LIMIT {$limit}",
            array_merge($bindings, $filterBindings),
        );
    }

    public function exists(int $id): bool
    {
        [$scope, $bindings] = $this->scopeConstraint();

        return Database::scalar(
            "SELECT 1 FROM `{$this->table}` WHERE id = ? AND {$scope} LIMIT 1",
            array_merge([$id], $bindings),
        ) !== null;
    }

    // ---------------- عمليات الكتابة | Write operations ----------------

    /**
     * إنشاء سجل | Insert a row.
     *
     * في الجداول المملوكة للمنشآت يُفرض organization_id من نطاق المستودع،
     * ويُتجاهل أي قيمة مُمرَّرة — حتى لا يمكن الكتابة في منشأة أخرى بالخطأ.
     * On tenant tables the organization_id is forced from the repository scope
     * and any passed value is overwritten — writing into another tenant is
     * structurally impossible.
     */
    public function create(array $data): int
    {
        if ($this->tenantScoped) {
            if (!$this->scopeDecided) {
                throw new RuntimeException(
                    sprintf('محاولة إنشاء سجل بدون نطاق منشأة (%s).', static::class)
                );
            }

            if ($this->scopedOrganizationId !== null) {
                $data[$this->tenantColumn] = $this->scopedOrganizationId;
            }
        }

        $columns      = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        return Database::insert(
            "INSERT INTO `{$this->table}` (`" . implode('`, `', $columns) . "`) VALUES ({$placeholders})",
            array_values($data),
        );
    }

    /**
     * تحديث سجل | Update a row within scope.
     * تُعيد عدد الصفوف المتأثرة: صفر يعني أن السجل خارج نطاق المنشأة.
     */
    public function update(int $id, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        // منع تغيير المالك عبر التحديث | Ownership can never be reassigned here.
        unset($data[$this->tenantColumn], $data['id'], $data['created_at']);

        [$scope, $scopeBindings] = $this->scopeConstraint();

        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = "`{$column}` = ?";
        }

        return Database::affectingStatement(
            "UPDATE `{$this->table}` SET " . implode(', ', $sets) . " WHERE id = ? AND {$scope}",
            array_merge(array_values($data), [$id], $scopeBindings),
        );
    }

    /** حذف ناعم أو نهائي حسب إعداد الجدول | Soft or hard delete. */
    public function delete(int $id): int
    {
        [$scope, $bindings] = $this->scopeConstraint();

        if ($this->softDeletes) {
            return Database::affectingStatement(
                "UPDATE `{$this->table}` SET deleted_at = NOW() WHERE id = ? AND {$scope}",
                array_merge([$id], $bindings),
            );
        }

        return Database::affectingStatement(
            "DELETE FROM `{$this->table}` WHERE id = ? AND {$scope}",
            array_merge([$id], $bindings),
        );
    }

    public function restore(int $id): int
    {
        if (!$this->softDeletes) {
            return 0;
        }

        [$scope, $bindings] = $this->withTrashed()->scopeConstraint();

        return Database::affectingStatement(
            "UPDATE `{$this->table}` SET deleted_at = NULL WHERE id = ? AND {$scope}",
            array_merge([$id], $bindings),
        );
    }

    // ---------------- أدوات داخلية | Internals ----------------

    /**
     * بناء شروط التصفية | Build filter predicates.
     *
     * يقبل: ['column' => value] أو ['column' => ['op' => '>=', 'value' => 5]]
     * كل اسم عمود يمرّ عبر قائمة السماح.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    protected function buildFilters(array $filters): array
    {
        $sql      = '';
        $bindings = [];

        foreach ($filters as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $this->assertColumn($column);

            if (is_array($value) && isset($value['op'])) {
                $operator = $this->sanitizeOperator((string) $value['op']);
                $sql     .= " AND `{$column}` {$operator} ?";
                $bindings[] = $value['value'] ?? null;
                continue;
            }

            if (is_array($value)) {
                if ($value === []) {
                    continue;
                }
                $placeholders = implode(', ', array_fill(0, count($value), '?'));
                $sql         .= " AND `{$column}` IN ({$placeholders})";
                $bindings     = array_merge($bindings, array_values($value));
                continue;
            }

            $sql       .= " AND `{$column}` = ?";
            $bindings[] = $value;
        }

        return [$sql, $bindings];
    }

    private function sanitizeOperator(string $operator): string
    {
        return in_array($operator, ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE'], true)
            ? $operator
            : '=';
    }

    protected function sanitizeSort(string $column): string
    {
        return in_array($column, $this->sortable, true) ? $column : 'id';
    }

    /**
     * التحقق من اسم العمود | Validate an identifier.
     * المُعرِّفات لا يمكن ربطها كمعاملات، لذا تُفحص صيغتها قبل الإدراج.
     */
    protected function assertColumn(string $column): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $column) !== 1) {
            throw new RuntimeException('اسم عمود غير صالح: ' . $column);
        }
    }

    public function table(): string
    {
        return $this->table;
    }

    public function isTenantScoped(): bool
    {
        return $this->tenantScoped;
    }
}
