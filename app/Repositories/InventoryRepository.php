<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع الأصناف والمخزون | Item and stock repository (§4.10).
 *
 * القراءة هنا مقيَّدة بالمستأجر دائماً: لا يوجد وجه عام لبيانات المخزون.
 * أرصدة المشروع وتكلفته وموردوه بيانات تشغيلية خاصة لا تُعرض لأحد خارجه.
 */
final class InventoryRepository extends BaseRepository
{
    protected string $table = 'erp_items';

    protected bool $tenantScoped = true;

    protected bool $softDeletes = true;

    protected array $sortable = [
        'id', 'sku', 'name_ar', 'quantity_on_hand', 'sale_price', 'cost_price', 'created_at',
    ];

    /**
     * قائمة الأصناف مع التصفية | Item listing with filters.
     *
     * @param  array<string,mixed> $filters
     * @return array{data:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function search(int $organizationId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where    = ['i.organization_id = ?', 'i.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if (($filters['q'] ?? '') !== '') {
            $where[]    = '(i.name_ar LIKE ? OR i.sku LIKE ?)';
            $term       = '%' . $filters['q'] . '%';
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (($filters['item_type'] ?? '') !== '') {
            $where[]    = 'i.item_type = ?';
            $bindings[] = $filters['item_type'];
        }

        if (($filters['status'] ?? '') === 'inactive') {
            $where[] = 'i.is_active = 0';
        } elseif (($filters['status'] ?? '') === 'active') {
            $where[] = 'i.is_active = 1';
        }

        // تحت حدّ إعادة الطلب: الأصناف التي تستوجب تصرّفاً الآن
        if (!empty($filters['low_stock'])) {
            $where[] = 'i.track_stock = 1 AND i.reorder_level IS NOT NULL
                        AND i.quantity_on_hand <= i.reorder_level';
        }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM erp_items i WHERE {$clause}",
            $bindings,
        );

        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            "SELECT i.*, l.slug AS listing_slug, l.status AS listing_status
               FROM erp_items i
          LEFT JOIN listings l ON l.id = i.listing_id
              WHERE {$clause}
           ORDER BY i.name_ar ASC
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

    /** صنف مملوك للمنشأة | An item owned by the organization. */
    public function findOwned(int $itemId, int $organizationId): ?array
    {
        return Database::selectOne(
            'SELECT i.*, l.slug AS listing_slug, l.name_ar AS listing_name
               FROM erp_items i
          LEFT JOIN listings l ON l.id = i.listing_id
              WHERE i.id = ? AND i.organization_id = ? AND i.deleted_at IS NULL
              LIMIT 1',
            [$itemId, $organizationId],
        );
    }

    /**
     * دفتر حركات صنف | An item's movement ledger.
     *
     * الترتيب تنازلي بالتاريخ ثم بالمعرّف: حركتان في اللحظة نفسها تبقيان
     * مرتّبتين بترتيب تسجيلهما، فلا يظهر رصيد لاحق قبل سابقه.
     *
     * @return array<int,array<string,mixed>>
     */
    public function movementsFor(int $itemId, int $organizationId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return Database::select(
            "SELECT m.*, u.name AS actor_name
               FROM erp_stock_movements m
          LEFT JOIN users u ON u.id = m.created_by
              WHERE m.item_id = ? AND m.organization_id = ?
           ORDER BY m.moved_at DESC, m.id DESC
              LIMIT {$limit}",
            [$itemId, $organizationId],
        );
    }

    /** أصناف تحت حدّ إعادة الطلب | Items at or below their reorder level. */
    public function lowStock(int $organizationId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return Database::select(
            "SELECT i.id, i.sku, i.name_ar, i.unit_of_measure,
                    i.quantity_on_hand, i.reorder_level
               FROM erp_items i
              WHERE i.organization_id = ? AND i.deleted_at IS NULL
                AND i.is_active = 1 AND i.track_stock = 1
                AND i.reorder_level IS NOT NULL
                AND i.quantity_on_hand <= i.reorder_level
           ORDER BY (i.quantity_on_hand - i.reorder_level) ASC
              LIMIT {$limit}",
            [$organizationId],
        );
    }

    /**
     * قيمة المخزون بالتكلفة | Stock valuation at cost.
     *
     * بآخر تكلفة شراء معروفة لا بمتوسط مرجّح: النسخة لا تمسك طبقات تكلفة،
     * والتقدير يُعرض موسوماً بأنه تقدير إداري لا تقييم محاسبي.
     */
    public function valuation(int $organizationId): array
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS item_count,
                    COALESCE(SUM(i.quantity_on_hand), 0) AS total_units,
                    COALESCE(SUM(i.quantity_on_hand * COALESCE(i.cost_price, 0)), 0) AS total_cost,
                    SUM(CASE WHEN i.cost_price IS NULL THEN 1 ELSE 0 END) AS items_without_cost
               FROM erp_items i
              WHERE i.organization_id = ? AND i.deleted_at IS NULL
                AND i.track_stock = 1 AND i.is_active = 1',
            [$organizationId],
        );

        return $row ?? [
            'item_count'         => 0,
            'total_units'        => 0,
            'total_cost'         => 0,
            'items_without_cost' => 0,
        ];
    }

    /** أصناف نشطة للاختيار في الفواتير وأوامر الشراء | Active items for pickers. */
    public function selectable(int $organizationId): array
    {
        return Database::select(
            'SELECT id, sku, name_ar, unit_of_measure, sale_price, cost_price,
                    vat_rate, track_stock, quantity_on_hand
               FROM erp_items
              WHERE organization_id = ? AND deleted_at IS NULL AND is_active = 1
           ORDER BY name_ar ASC
              LIMIT 500',
            [$organizationId],
        );
    }

    /** هل الكود مستخدم؟ | Is the SKU already taken within the organization? */
    public function skuExists(int $organizationId, string $sku, ?int $exceptId = null): bool
    {
        $sql      = 'SELECT 1 FROM erp_items WHERE organization_id = ? AND sku = ? AND deleted_at IS NULL';
        $bindings = [$organizationId, $sku];

        if ($exceptId !== null) {
            $sql       .= ' AND id <> ?';
            $bindings[] = $exceptId;
        }

        return Database::scalar($sql . ' LIMIT 1', $bindings) !== null;
    }
}
