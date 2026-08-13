<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\OrganizationRepository;

/**
 * لوحة تحكم المنصة | Platform administration dashboard (§4.13).
 *
 * كل الاستعلامات هنا تجميعية على مستوى المنصة، وهو استخدام مشروع للنطاق
 * العام لأن مدير المنصة يمثّل الجهة المشغّلة.
 * All queries here are platform-wide aggregates — a legitimate global scope,
 * since the platform admin represents the operator.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->view('admin/dashboard', [
            'pageTitle'        => 'لوحة تحكم المنصة',
            'counts'           => $this->headlineCounts(),
            'statusCounts'     => $this->organizations->countsByStatus(),
            'byGovernorate'    => $this->organizations->countsByGovernorate(8),
            'byType'           => $this->countsByType(),
            'recentActivity'   => $this->recentAudit(),
            'pendingQueue'     => $this->pendingVerifications(),
        ], 'admin');
    }

    /** @return array<string,int> */
    private function headlineCounts(): array
    {
        $row = Database::selectOne(
            "SELECT
                (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) AS users,
                (SELECT COUNT(*) FROM users WHERE status = 'active' AND deleted_at IS NULL) AS active_users,
                (SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL) AS organizations,
                (SELECT COUNT(*) FROM organizations
                  WHERE status = 'verified' AND deleted_at IS NULL) AS verified,
                (SELECT COUNT(*) FROM organizations
                  WHERE status IN ('submitted','under_review') AND deleted_at IS NULL) AS pending_review,
                (SELECT COUNT(*) FROM audit_logs
                  WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS events_24h,
                (SELECT COUNT(*) FROM login_attempts
                  WHERE successful = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS failed_logins_24h"
        );

        return array_map(static fn ($v) => (int) $v, $row ?? []);
    }

    /** @return array<int,array<string,mixed>> */
    private function countsByType(): array
    {
        return Database::select(
            "SELECT t.name_ar AS type_name, t.code,
                    COUNT(o.id) AS total,
                    SUM(CASE WHEN o.status = 'verified' THEN 1 ELSE 0 END) AS verified
               FROM organization_types t
               LEFT JOIN organizations o
                      ON o.organization_type_id = t.id AND o.deleted_at IS NULL
              GROUP BY t.id, t.name_ar, t.code
              ORDER BY t.sort_order ASC"
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function recentAudit(): array
    {
        return Database::select(
            'SELECT a.id, a.action, a.category, a.severity, a.description, a.created_at,
                    u.name AS user_name
               FROM audit_logs a
               LEFT JOIN users u ON u.id = a.user_id
              ORDER BY a.created_at DESC
              LIMIT 12'
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function pendingVerifications(): array
    {
        return Database::select(
            "SELECT o.id, o.legal_name, o.slug, o.status, o.submitted_at, o.completion_score,
                    t.name_ar AS type_name, g.name_ar AS governorate_name
               FROM organizations o
               JOIN organization_types t ON t.id = o.organization_type_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
              WHERE o.status IN ('submitted','under_review') AND o.deleted_at IS NULL
              ORDER BY o.submitted_at ASC
              LIMIT 10"
        );
    }
}
