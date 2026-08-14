<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع الفواتير | Invoice repository (§4.10).
 *
 * كل قراءة مقيَّدة بالمستأجر: الفواتير بيانات مالية خاصة بالمشروع، ولا وجه
 * عام لها في هذه النسخة.
 */
final class InvoiceRepository extends BaseRepository
{
    protected string $table = 'erp_invoices';

    protected bool $tenantScoped = true;

    protected bool $softDeletes = true;

    protected array $sortable = ['id', 'invoice_number', 'issue_date', 'due_date', 'total', 'status'];

    /**
     * @param  array<string,mixed> $filters
     * @return array{data:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function search(int $organizationId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where    = ['v.organization_id = ?', 'v.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if (($filters['q'] ?? '') !== '') {
            $where[]    = '(v.invoice_number LIKE ? OR v.customer_name_ar LIKE ?)';
            $term       = '%' . $filters['q'] . '%';
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (($filters['status'] ?? '') !== '') {
            $where[]    = 'v.status = ?';
            $bindings[] = $filters['status'];
        }

        if (($filters['customer_id'] ?? '') !== '') {
            $where[]    = 'v.customer_id = ?';
            $bindings[] = (int) $filters['customer_id'];
        }

        if (($filters['from'] ?? '') !== '') {
            $where[]    = 'v.issue_date >= ?';
            $bindings[] = $filters['from'];
        }

        if (($filters['to'] ?? '') !== '') {
            $where[]    = 'v.issue_date <= ?';
            $bindings[] = $filters['to'];
        }

        // المتأخّرة: مُصدَرة أو مسدَّدة جزئياً وتجاوز استحقاقها اليوم
        if (!empty($filters['overdue'])) {
            $where[] = "v.status IN ('issued','partially_paid')
                        AND v.due_date IS NOT NULL AND v.due_date < CURDATE()";
        }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM erp_invoices v WHERE {$clause}",
            $bindings,
        );

        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            "SELECT v.*, (v.total - v.amount_paid) AS balance_due
               FROM erp_invoices v
              WHERE {$clause}
           ORDER BY v.issue_date DESC, v.id DESC
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

    public function findOwned(int $invoiceId, int $organizationId): ?array
    {
        return Database::selectOne(
            'SELECT v.*, (v.total - v.amount_paid) AS balance_due,
                    c.code AS customer_code
               FROM erp_invoices v
          LEFT JOIN crm_customers c ON c.id = v.customer_id
              WHERE v.id = ? AND v.organization_id = ? AND v.deleted_at IS NULL
              LIMIT 1',
            [$invoiceId, $organizationId],
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function lines(int $invoiceId): array
    {
        return Database::select(
            'SELECT * FROM erp_invoice_items
              WHERE invoice_id = ?
           ORDER BY sort_order ASC, id ASC',
            [$invoiceId],
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function payments(int $invoiceId): array
    {
        return Database::select(
            'SELECT p.*, u.name AS actor_name
               FROM erp_payments p
          LEFT JOIN users u ON u.id = p.created_by
              WHERE p.invoice_id = ?
           ORDER BY p.paid_at ASC, p.id ASC',
            [$invoiceId],
        );
    }

    /**
     * أعمار الذمم | Receivables ageing.
     *
     * شرائح قياسية بأعمار الاستحقاق. الفاتورة بلا تاريخ استحقاق تُحسب في
     * «غير مستحقّة بعد» لا في شريحة متأخّرة: غياب التاريخ ليس تأخيراً.
     *
     * @return array<string,array{count:int,amount:float}>
     */
    public function ageing(int $organizationId): array
    {
        $rows = Database::select(
            "SELECT
                CASE
                    WHEN v.due_date IS NULL OR v.due_date >= CURDATE() THEN 'not_due'
                    WHEN DATEDIFF(CURDATE(), v.due_date) <= 30  THEN 'd1_30'
                    WHEN DATEDIFF(CURDATE(), v.due_date) <= 60  THEN 'd31_60'
                    WHEN DATEDIFF(CURDATE(), v.due_date) <= 90  THEN 'd61_90'
                    ELSE 'd90_plus'
                END AS bucket,
                COUNT(*) AS invoice_count,
                COALESCE(SUM(v.total - v.amount_paid), 0) AS amount
              FROM erp_invoices v
             WHERE v.organization_id = ? AND v.deleted_at IS NULL
               AND v.status IN ('issued','partially_paid')
               AND (v.total - v.amount_paid) > 0
          GROUP BY bucket",
            [$organizationId],
        );

        $buckets = [
            'not_due'  => ['count' => 0, 'amount' => 0.0],
            'd1_30'    => ['count' => 0, 'amount' => 0.0],
            'd31_60'   => ['count' => 0, 'amount' => 0.0],
            'd61_90'   => ['count' => 0, 'amount' => 0.0],
            'd90_plus' => ['count' => 0, 'amount' => 0.0],
        ];

        foreach ($rows as $row) {
            $buckets[(string) $row['bucket']] = [
                'count'  => (int) $row['invoice_count'],
                'amount' => (float) $row['amount'],
            ];
        }

        return $buckets;
    }

    /** هل الرقم مستخدم؟ | Is the invoice number taken? */
    public function numberExists(int $organizationId, string $number): bool
    {
        return Database::scalar(
            'SELECT 1 FROM erp_invoices WHERE organization_id = ? AND invoice_number = ? LIMIT 1',
            [$organizationId, $number],
        ) !== null;
    }
}
