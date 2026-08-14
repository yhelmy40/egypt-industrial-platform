<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع المهتمّين والفرص والأنشطة | Lead, opportunity and activity repository (§4.9).
 */
final class PipelineRepository extends BaseRepository
{
    protected string $table = 'crm_opportunities';

    protected bool $tenantScoped = true;

    protected bool $softDeletes = true;

    protected array $sortable = ['id', 'title_ar', 'stage', 'expected_value', 'expected_close_date'];

    /** مراحل خطّ الفرص بالترتيب | Pipeline stages, in order. */
    public const STAGES = ['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];

    // ═══════════════════ المهتمّون | Leads ═══════════════════

    /**
     * @param  array<string,mixed> $filters
     * @return array{data:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function searchLeads(int $organizationId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where    = ['l.organization_id = ?', 'l.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if (($filters['q'] ?? '') !== '') {
            $where[]    = '(l.name_ar LIKE ? OR l.phone LIKE ?)';
            $term       = '%' . $filters['q'] . '%';
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (($filters['status'] ?? '') !== '') {
            $where[]    = 'l.status = ?';
            $bindings[] = $filters['status'];
        }

        $clause  = implode(' AND ', $where);
        $total   = (int) Database::scalar("SELECT COUNT(*) FROM crm_leads l WHERE {$clause}", $bindings);
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            "SELECT l.*, u.name AS assignee_name, c.name_ar AS converted_customer_name
               FROM crm_leads l
          LEFT JOIN users u ON u.id = l.assigned_to
          LEFT JOIN crm_customers c ON c.id = l.converted_customer_id
              WHERE {$clause}
           ORDER BY l.created_at DESC, l.id DESC
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

    public function findLead(int $leadId, int $organizationId): ?array
    {
        return Database::selectOne(
            'SELECT l.*, u.name AS assignee_name, c.name_ar AS converted_customer_name
               FROM crm_leads l
          LEFT JOIN users u ON u.id = l.assigned_to
          LEFT JOIN crm_customers c ON c.id = l.converted_customer_id
              WHERE l.id = ? AND l.organization_id = ? AND l.deleted_at IS NULL
              LIMIT 1',
            [$leadId, $organizationId],
        );
    }

    // ═══════════════════ الفرص | Opportunities ═══════════════════

    /**
     * @param  array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function opportunities(int $organizationId, array $filters = []): array
    {
        $where    = ['o.organization_id = ?', 'o.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if (($filters['stage'] ?? '') !== '') {
            $where[]    = 'o.stage = ?';
            $bindings[] = $filters['stage'];
        }

        if (($filters['customer_id'] ?? '') !== '') {
            $where[]    = 'o.customer_id = ?';
            $bindings[] = (int) $filters['customer_id'];
        }

        if (!empty($filters['open_only'])) {
            $where[] = "o.stage NOT IN ('won','lost')";
        }

        $clause = implode(' AND ', $where);

        return Database::select(
            "SELECT o.*, c.name_ar AS customer_name, c.code AS customer_code,
                    u.name AS assignee_name
               FROM crm_opportunities o
               JOIN crm_customers c ON c.id = o.customer_id
          LEFT JOIN users u ON u.id = o.assigned_to
              WHERE {$clause}
           ORDER BY FIELD(o.stage, 'new','qualified','proposal','negotiation','won','lost'),
                    o.expected_close_date IS NULL, o.expected_close_date ASC, o.id DESC
              LIMIT 500",
            $bindings,
        );
    }

    public function findOpportunity(int $opportunityId, int $organizationId): ?array
    {
        return Database::selectOne(
            'SELECT o.*, c.name_ar AS customer_name, c.code AS customer_code,
                    u.name AS assignee_name
               FROM crm_opportunities o
               JOIN crm_customers c ON c.id = o.customer_id
          LEFT JOIN users u ON u.id = o.assigned_to
              WHERE o.id = ? AND o.organization_id = ? AND o.deleted_at IS NULL
              LIMIT 1',
            [$opportunityId, $organizationId],
        );
    }

    /**
     * ملخّص خطّ الفرص | Pipeline summary by stage.
     *
     * القيمة المعروضة **مجموع تقديرات صاحب المشروع** لا تنبّؤ من المنصة، وتُعرض
     * موسومة بذلك.
     *
     * @return array<string,array{count:int,value:float}>
     */
    public function pipelineSummary(int $organizationId): array
    {
        $rows = Database::select(
            'SELECT stage, COUNT(*) AS opportunity_count,
                    COALESCE(SUM(expected_value), 0) AS value
               FROM crm_opportunities
              WHERE organization_id = ? AND deleted_at IS NULL
           GROUP BY stage',
            [$organizationId],
        );

        $summary = [];

        foreach (self::STAGES as $stage) {
            $summary[$stage] = ['count' => 0, 'value' => 0.0];
        }

        foreach ($rows as $row) {
            $summary[(string) $row['stage']] = [
                'count' => (int) $row['opportunity_count'],
                'value' => (float) $row['value'],
            ];
        }

        return $summary;
    }

    // ═══════════════════ الأنشطة | Activities ═══════════════════

    /**
     * أنشطة مرتبطة بكيان | Activities attached to an entity.
     *
     * @return array<int,array<string,mixed>>
     */
    public function activitiesFor(string $entity, int $entityId, int $organizationId, int $limit = 100): array
    {
        $column = match ($entity) {
            'customer'    => 'customer_id',
            'lead'        => 'lead_id',
            'opportunity' => 'opportunity_id',
            default       => throw new \InvalidArgumentException("نوع كيان غير معروف: {$entity}"),
        };

        $limit = max(1, min(300, $limit));

        return Database::select(
            "SELECT a.*, u.name AS assignee_name, cu.name AS creator_name
               FROM crm_activities a
          LEFT JOIN users u ON u.id = a.assigned_to
          LEFT JOIN users cu ON cu.id = a.created_by
              WHERE a.{$column} = ? AND a.organization_id = ? AND a.deleted_at IS NULL
           ORDER BY COALESCE(a.occurred_at, a.due_at, a.created_at) DESC, a.id DESC
              LIMIT {$limit}",
            [$entityId, $organizationId],
        );
    }

    /**
     * المهام المستحقّة | Outstanding tasks.
     *
     * ما فات موعده أولاً: قائمة تبدأ بالمتأخّر تدفع للتصرّف، وقائمة مرتّبة
     * بتاريخ الإنشاء تُخفيه.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueTasks(int $organizationId, ?int $assignedTo = null, int $limit = 50): array
    {
        $where    = ["a.status = 'planned'", 'a.organization_id = ?', 'a.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if ($assignedTo !== null) {
            $where[]    = 'a.assigned_to = ?';
            $bindings[] = $assignedTo;
        }

        $clause = implode(' AND ', $where);
        $limit  = max(1, min(200, $limit));

        return Database::select(
            "SELECT a.*, c.name_ar AS customer_name, l.name_ar AS lead_name,
                    o.title_ar AS opportunity_title, u.name AS assignee_name,
                    (a.due_at IS NOT NULL AND a.due_at < NOW()) AS is_overdue
               FROM crm_activities a
          LEFT JOIN crm_customers c ON c.id = a.customer_id
          LEFT JOIN crm_leads l ON l.id = a.lead_id
          LEFT JOIN crm_opportunities o ON o.id = a.opportunity_id
          LEFT JOIN users u ON u.id = a.assigned_to
              WHERE {$clause}
           ORDER BY a.due_at IS NULL, a.due_at ASC, a.id ASC
              LIMIT {$limit}",
            $bindings,
        );
    }

    public function findActivity(int $activityId, int $organizationId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM crm_activities
              WHERE id = ? AND organization_id = ? AND deleted_at IS NULL
              LIMIT 1',
            [$activityId, $organizationId],
        );
    }
}
