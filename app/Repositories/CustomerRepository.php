<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع العملاء | Customer repository (§4.9).
 *
 * دفتر عملاء المشروع بيانات خاصة به وحده. لا وجه عام هنا، وكل قراءة مقيَّدة
 * بالمنشأة المالكة.
 */
final class CustomerRepository extends BaseRepository
{
    protected string $table = 'crm_customers';

    protected bool $tenantScoped = true;

    protected bool $softDeletes = true;

    protected array $sortable = ['id', 'code', 'name_ar', 'created_at'];

    /**
     * @param  array<string,mixed> $filters
     * @return array{data:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function search(int $organizationId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where    = ['c.organization_id = ?', 'c.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if (($filters['q'] ?? '') !== '') {
            $where[]    = '(c.name_ar LIKE ? OR c.code LIKE ? OR c.phone LIKE ?)';
            $term       = '%' . $filters['q'] . '%';
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (($filters['status'] ?? '') !== '') {
            $where[]    = 'c.status = ?';
            $bindings[] = $filters['status'];
        }

        if (($filters['customer_type'] ?? '') !== '') {
            $where[]    = 'c.customer_type = ?';
            $bindings[] = $filters['customer_type'];
        }

        if (($filters['governorate_id'] ?? '') !== '') {
            $where[]    = 'c.governorate_id = ?';
            $bindings[] = (int) $filters['governorate_id'];
        }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM crm_customers c WHERE {$clause}",
            $bindings,
        );

        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        // الرصيد المستحق يأتي من الفواتير مباشرةً: عمود محفوظ كان سيفترق عن
        // الواقع أول مرة تُلغى فيها فاتورة أو يُحذف إيصال.
        $rows = Database::select(
            "SELECT c.*, g.name_ar AS governorate_name,
                    COALESCE(due.balance, 0) AS outstanding_balance,
                    COALESCE(due.invoice_count, 0) AS open_invoice_count
               FROM crm_customers c
          LEFT JOIN governorates g ON g.id = c.governorate_id
          LEFT JOIN (
                SELECT customer_id,
                       SUM(total - amount_paid) AS balance,
                       COUNT(*) AS invoice_count
                  FROM erp_invoices
                 WHERE organization_id = ? AND deleted_at IS NULL
                   AND status IN ('issued','partially_paid')
              GROUP BY customer_id
             ) due ON due.customer_id = c.id
              WHERE {$clause}
           ORDER BY c.name_ar ASC
              LIMIT {$perPage} OFFSET {$offset}",
            array_merge([$organizationId], $bindings),
        );

        return [
            'data'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function findOwned(int $customerId, int $organizationId): ?array
    {
        return Database::selectOne(
            'SELECT c.*, g.name_ar AS governorate_name, ct.name_ar AS city_name
               FROM crm_customers c
          LEFT JOIN governorates g ON g.id = c.governorate_id
          LEFT JOIN cities ct ON ct.id = c.city_id
              WHERE c.id = ? AND c.organization_id = ? AND c.deleted_at IS NULL
              LIMIT 1',
            [$customerId, $organizationId],
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function contacts(int $customerId, int $organizationId): array
    {
        return Database::select(
            'SELECT * FROM crm_contacts
              WHERE customer_id = ? AND organization_id = ? AND deleted_at IS NULL
           ORDER BY is_primary DESC, name_ar ASC',
            [$customerId, $organizationId],
        );
    }

    /**
     * ملخّص تعامل العميل | The customer's trading summary.
     *
     * الأرقام محسوبة لحظة القراءة لا محفوظة: مجموع محفوظ يفترق عن الفواتير
     * عند أول تصحيح. والمسودات مستبعدة كالملغاة: مسودة ليست بيعاً بعد.
     */
    public function summary(int $customerId, int $organizationId): array
    {
        $row = Database::selectOne(
            "SELECT
                COUNT(*) AS invoice_count,
                COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','draft')
                                  THEN total ELSE 0 END), 0) AS invoiced_total,
                COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','draft')
                                  THEN amount_paid ELSE 0 END), 0) AS paid_total,
                COALESCE(SUM(CASE WHEN status IN ('issued','partially_paid')
                                  THEN total - amount_paid ELSE 0 END), 0) AS outstanding,
                MAX(CASE WHEN status NOT IN ('cancelled','draft')
                         THEN issue_date END) AS last_invoice_date
               FROM erp_invoices
              WHERE customer_id = ? AND organization_id = ? AND deleted_at IS NULL",
            [$customerId, $organizationId],
        );

        return $row ?? [
            'invoice_count'     => 0,
            'invoiced_total'    => 0,
            'paid_total'        => 0,
            'outstanding'       => 0,
            'last_invoice_date' => null,
        ];
    }

    /** عملاء للاختيار في القوائم | Customers for pickers. */
    public function selectable(int $organizationId): array
    {
        return Database::select(
            "SELECT id, code, name_ar, phone
               FROM crm_customers
              WHERE organization_id = ? AND deleted_at IS NULL AND status = 'active'
           ORDER BY name_ar ASC
              LIMIT 500",
            [$organizationId],
        );
    }

    public function codeExists(int $organizationId, string $code, ?int $exceptId = null): bool
    {
        $sql      = 'SELECT 1 FROM crm_customers WHERE organization_id = ? AND code = ? AND deleted_at IS NULL';
        $bindings = [$organizationId, $code];

        if ($exceptId !== null) {
            $sql       .= ' AND id <> ?';
            $bindings[] = $exceptId;
        }

        return Database::scalar($sql . ' LIMIT 1', $bindings) !== null;
    }

    /** أفضل العملاء بالإيراد | Top customers by invoiced value. */
    public function topByRevenue(int $organizationId, string $from, string $to, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        return Database::select(
            "SELECT c.id, c.name_ar, c.code,
                    COUNT(v.id) AS invoice_count,
                    COALESCE(SUM(v.total), 0) AS revenue
               FROM erp_invoices v
               JOIN crm_customers c ON c.id = v.customer_id
              WHERE v.organization_id = ? AND v.deleted_at IS NULL
                AND v.status NOT IN ('cancelled','draft')
                AND v.issue_date BETWEEN ? AND ?
           GROUP BY c.id, c.name_ar, c.code
           ORDER BY revenue DESC
              LIMIT {$limit}",
            [$organizationId, $from, $to],
        );
    }
}
