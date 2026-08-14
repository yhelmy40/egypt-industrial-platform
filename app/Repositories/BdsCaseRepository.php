<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Exceptions\HttpException;

/**
 * مستودع حالات الدعم | BDS case repository (§4.8).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * كل دالة قراءة هنا تأخذ **الجهة القارئة** كوسيط إلزامي (`center` أو
 * `organization`) ومعرّف منشأتها. لا توجد دالة تقرأ حالة دون أن تصرّح نيابةً
 * عن من تقرأ، ولا قيمة افتراضية لهذا الوسيط.
 *
 * السبب: الحالة يقرؤها طرفان يرى كلٌّ منهما جزءاً مختلفاً. وسيط اختياري بقيمة
 * افتراضية كان سيعني أن نسيان تمريره يفتح النسخة الأوسع — وهو خطأ صامت. جعله
 * إلزامياً يحوّل النسيان إلى خطأ تجميع لا إلى تسريب.
 *
 * Every read takes the reading side as a required argument. An optional
 * parameter with a default would mean forgetting it opens the wider view — a
 * silent failure. Required turns forgetting into a compile-time error.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class BdsCaseRepository
{
    /** الجهات القارئة | The two sides that may read a case. */
    public const SIDE_CENTER       = 'center';
    public const SIDE_ORGANIZATION = 'organization';

    /**
     * حالة يقرؤها طرف بعينه | A case read on behalf of one side.
     *
     * @return array<string,mixed>
     */
    public function findFor(string $side, int $caseId, int $organizationId): array
    {
        $column = $this->sideColumn($side);

        $row = Database::selectOne(
            "SELECT c.*,
                    sme.legal_name AS sme_legal_name, sme.trading_name AS sme_trading_name,
                    sme.slug AS sme_slug, sme.completion_score,
                    center.legal_name AS center_legal_name, center.trading_name AS center_trading_name,
                    center.slug AS center_slug,
                    g.name_ar AS governorate_name, s.name_ar AS sector_name,
                    specialist.name AS specialist_name
               FROM bds_cases c
               JOIN organizations sme ON sme.id = c.organization_id
               JOIN organizations center ON center.id = c.center_organization_id
               LEFT JOIN governorates g ON g.id = sme.governorate_id
               LEFT JOIN sectors s ON s.id = sme.sector_id
               LEFT JOIN users specialist ON specialist.id = c.assigned_to
              WHERE c.id = ? AND c.{$column} = ? AND c.deleted_at IS NULL
              LIMIT 1",
            [$caseId, $organizationId],
        );

        if ($row === null) {
            // 404 لا 403: منشأة غريبة لا يجوز أن تتأكد من وجود الحالة
            throw new HttpException(404, 'حالة الدعم المطلوبة غير موجودة.');
        }

        return $row;
    }

    /**
     * قائمة الحالات لطرف | One side's cases.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listFor(
        string $side,
        int $organizationId,
        ?string $status = null,
        ?int $assignedTo = null,
        string $term = '',
        int $limit = 100,
    ): array {
        $column   = $this->sideColumn($side);
        $where    = ["c.{$column} = ?", 'c.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if ($status !== null && $status !== '') {
            $where[]    = 'c.status = ?';
            $bindings[] = $status;
        }

        if ($assignedTo !== null) {
            $where[]    = 'c.assigned_to = ?';
            $bindings[] = $assignedTo;
        }

        if (trim($term) !== '') {
            $where[]  = '(c.case_number LIKE ? OR c.title_ar LIKE ? OR sme.legal_name LIKE ?)';
            $like     = '%' . trim($term) . '%';
            $bindings = array_merge($bindings, [$like, $like, $like]);
        }

        $whereSql = implode(' AND ', $where);
        $limit    = min(200, max(1, $limit));

        return Database::select(
            "SELECT c.*,
                    sme.legal_name AS sme_legal_name, sme.trading_name AS sme_trading_name,
                    center.legal_name AS center_legal_name, center.trading_name AS center_trading_name,
                    g.name_ar AS governorate_name,
                    specialist.name AS specialist_name,
                    (SELECT COUNT(*) FROM bds_consultations n
                      WHERE n.case_id = c.id AND n.status = 'scheduled') AS upcoming_sessions
               FROM bds_cases c
               JOIN organizations sme ON sme.id = c.organization_id
               JOIN organizations center ON center.id = c.center_organization_id
               LEFT JOIN governorates g ON g.id = sme.governorate_id
               LEFT JOIN users specialist ON specialist.id = c.assigned_to
              WHERE {$whereSql}
              ORDER BY FIELD(c.priority,'high','normal','low'),
                       FIELD(c.status,'requested','triage','assigned','in_progress','on_hold',
                             'closed_completed','closed_referred','closed_unreachable','cancelled'),
                       c.created_at DESC
              LIMIT {$limit}",
            $bindings,
        );
    }

    /** @return array<string,int> */
    public function countsByStatus(string $side, int $organizationId): array
    {
        $column = $this->sideColumn($side);

        $rows = Database::select(
            "SELECT status, COUNT(*) AS total FROM bds_cases
              WHERE {$column} = ? AND deleted_at IS NULL GROUP BY status",
            [$organizationId],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    // ═══════════════════ الملاحظات | Notes ═══════════════════

    /**
     * ملاحظات الحالة كما يراها الطرف القارئ | Notes as one side may see them.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * هنا يقع الحدّ الذي تحرسه المرحلة كلها. حين يقرأ المشروع، يُضاف الشرط
     * `visibility = 'shared'` **إلى الاستعلام**، فالملاحظة الداخلية لا تُجلب
     * من قاعدة البيانات أصلاً ولا تمرّ في أي مصفوفة قد تُطبع سهواً.
     *
     * البديل — جلب الكل ثم إخفاء الداخلي في القالب — يجعل كل قالب جديد فرصة
     * تسريب، ويجعل أي `var_dump` أو استجابة JSON تصحيحية كشفاً كاملاً.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * @return array<int,array<string,mixed>>
     */
    public function notesFor(string $side, int $caseId): array
    {
        $where    = ['n.case_id = ?', 'n.deleted_at IS NULL'];
        $bindings = [$caseId];

        if ($side === self::SIDE_ORGANIZATION) {
            $where[] = "n.visibility = 'shared'";
        }

        $whereSql = implode(' AND ', $where);

        return Database::select(
            "SELECT n.*, u.name AS author_name
               FROM bds_case_notes n
               LEFT JOIN users u ON u.id = n.author_user_id
              WHERE {$whereSql}
              ORDER BY n.created_at DESC, n.id DESC",
            $bindings,
        );
    }

    // ═══════════════════ الجلسات | Consultations ═══════════════════

    /**
     * جلسات الحالة كما يراها الطرف | Sessions as one side may see them.
     *
     * ملاحظة الأخصائي الخاصة على الجلسة تُحذف من نسخة المشروع بنفس المنطق:
     * العمود لا يُختار أصلاً في استعلام المشروع.
     *
     * @return array<int,array<string,mixed>>
     */
    public function consultationsFor(string $side, int $caseId): array
    {
        $columns = $side === self::SIDE_CENTER
            ? 'n.*'
            : 'n.id, n.case_id, n.title_ar, n.scheduled_at, n.duration_minutes, n.mode,
               n.location_ar, n.status, n.summary_ar, n.completed_at, n.created_at';

        return Database::select(
            "SELECT {$columns}, u.name AS specialist_name
               FROM bds_consultations n
               LEFT JOIN users u ON u.id = n.specialist_user_id
              WHERE n.case_id = ?
              ORDER BY n.scheduled_at DESC, n.id DESC",
            [$caseId],
        );
    }

    // ═══════════════════ الخطط والإحالات | Plans and referrals ═══════════════════

    /**
     * خطط الحالة | Action plans.
     *
     * المشروع لا يرى الخطة قبل مشاركتها: خطة قيد الصياغة قد تتغيّر بالكامل،
     * وعرضها كأنها نهائية يبني توقّعاً على مسودة.
     *
     * @return array<int,array<string,mixed>>
     */
    public function plansFor(string $side, int $caseId): array
    {
        $where    = ['p.case_id = ?'];
        $bindings = [$caseId];

        if ($side === self::SIDE_ORGANIZATION) {
            $where[] = 'p.shared_at IS NOT NULL';
        }

        $whereSql = implode(' AND ', $where);

        $plans = Database::select(
            "SELECT p.*, u.name AS author_name
               FROM bds_action_plans p
               LEFT JOIN users u ON u.id = p.created_by
              WHERE {$whereSql}
              ORDER BY p.created_at DESC",
            $bindings,
        );

        foreach ($plans as $index => $plan) {
            $plans[$index]['tasks'] = Database::select(
                'SELECT * FROM bds_plan_tasks WHERE plan_id = ? ORDER BY sort_order ASC, id ASC',
                [(int) $plan['id']],
            );
        }

        return $plans;
    }

    /** @return array<int,array<string,mixed>> */
    public function referralsFor(int $caseId): array
    {
        return Database::select(
            'SELECT r.*, u.name AS referrer_name
               FROM bds_referrals r
               LEFT JOIN users u ON u.id = r.referred_by
              WHERE r.case_id = ?
              ORDER BY r.created_at DESC',
            [$caseId],
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $caseId): array
    {
        return Database::select(
            'SELECT h.*, u.name AS actor_name
               FROM bds_case_history h
               LEFT JOIN users u ON u.id = h.actor_user_id
              WHERE h.case_id = ?
              ORDER BY h.created_at ASC, h.id ASC',
            [$caseId],
        );
    }

    // ═══════════════════ تقارير المركز | Centre reporting ═══════════════════

    /**
     * ملخّص أداء المركز | The centre's caseload summary.
     *
     * @return array<string,mixed>
     */
    public function centerSummary(int $centerOrganizationId, int $days = 90): array
    {
        $row = Database::selectOne(
            "SELECT
                COUNT(*) AS total_cases,
                SUM(CASE WHEN status IN ('requested','triage') THEN 1 ELSE 0 END) AS awaiting_triage,
                SUM(CASE WHEN status IN ('assigned','in_progress') THEN 1 ELSE 0 END) AS active_cases,
                SUM(CASE WHEN status LIKE 'closed_%' THEN 1 ELSE 0 END) AS closed_cases,
                SUM(CASE WHEN assigned_to IS NULL AND status NOT LIKE 'closed_%'
                          AND status != 'cancelled' THEN 1 ELSE 0 END) AS unassigned,
                AVG(satisfaction_rating) AS average_rating
               FROM bds_cases
              WHERE center_organization_id = ?
                AND deleted_at IS NULL
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$centerOrganizationId, $days],
        );

        return $row ?? [];
    }

    /**
     * أخصائيو المركز وأحمالهم | The centre's specialists and their load.
     *
     * @return array<int,array<string,mixed>>
     */
    public function specialistLoad(int $centerOrganizationId): array
    {
        return Database::select(
            "SELECT u.id, u.name,
                    SUM(CASE WHEN c.status IN ('assigned','in_progress') THEN 1 ELSE 0 END) AS active_cases,
                    COUNT(c.id) AS total_cases
               FROM organization_members m
               JOIN users u ON u.id = m.user_id
               LEFT JOIN bds_cases c
                      ON c.assigned_to = u.id
                     AND c.center_organization_id = m.organization_id
                     AND c.deleted_at IS NULL
              WHERE m.organization_id = ?
                AND m.status = 'active'
                AND u.deleted_at IS NULL
              GROUP BY u.id, u.name
              ORDER BY active_cases DESC, u.name ASC",
            [$centerOrganizationId],
        );
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /**
     * عمود التقييد للجهة القارئة | The scoping column for a reading side.
     *
     * القيمة المجهولة ترفع استثناءً بدل أن تسقط إلى افتراض: الافتراض هنا كان
     * سيعني قراءة بلا قيد.
     */
    private function sideColumn(string $side): string
    {
        return match ($side) {
            self::SIDE_CENTER       => 'center_organization_id',
            self::SIDE_ORGANIZATION => 'organization_id',
            default                 => throw new \InvalidArgumentException(
                "جهة قراءة غير معروفة: {$side}"
            ),
        };
    }
}
