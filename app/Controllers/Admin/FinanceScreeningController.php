<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\FinancingApplicationService;

/**
 * فرز طلبات التمويل وإحالتها | Screening and routing financing applications (§4.5).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * دور المنصة هنا محدَّد بدقة: تتأكّد من اكتمال الطلب وتحيله إلى المؤسسة
 * المالية. **لا تقبل ولا ترفض.** الإجراءان غير موجودين أصلاً في الواجهة، وإن
 * أُرسلا مباشرةً رفضتهما الخدمة باستثناء تفويض.
 *
 * هذا ليس تشدّداً شكلياً: عرض طلب كمقبول دون قرار المموّل يعد صاحب المشروع
 * بتمويل لم يوافق عليه أحد، وهو أخطر ما يمكن أن تفعله منصة وساطة.
 *
 * The platform's role is bounded: verify completeness and route. It cannot
 * approve or reject — those actions do not exist in this interface and are
 * refused by the service if posted directly.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class FinanceScreeningController extends Controller
{
    public function __construct(
        private readonly FinancingApplicationService $applications = new FinancingApplicationService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $status = (string) ($request->input('status') ?? 'submitted');

        $where    = ['a.deleted_at IS NULL'];
        $bindings = [];

        if ($status !== '') {
            $where[]    = 'a.status = ?';
            $bindings[] = $status;
        }

        $whereSql = implode(' AND ', $where);

        return $this->view('admin/finance/applications', [
            'pageTitle'    => 'فرز طلبات التمويل',
            'applications' => Database::select(
                "SELECT a.*,
                        applicant.legal_name AS applicant_legal_name,
                        applicant.trading_name AS applicant_trading_name,
                        provider.legal_name AS provider_legal_name,
                        provider.trading_name AS provider_trading_name,
                        g.name_ar AS governorate_name
                   FROM financing_applications a
                   JOIN organizations applicant ON applicant.id = a.organization_id
                   JOIN organizations provider ON provider.id = a.provider_organization_id
                   LEFT JOIN governorates g ON g.id = applicant.governorate_id
                  WHERE {$whereSql}
                  ORDER BY FIELD(a.status,'submitted','screening','forwarded','provider_review',
                                 'info_requested','approved','rejected','withdrawn','cancelled'),
                           a.created_at ASC
                  LIMIT 100",
                $bindings,
            ),
            'counts'       => $this->counts(),
            'filters'      => ['status' => $status],
            'service'      => $this->applications,
        ], 'admin');
    }

    public function show(Request $request): Response
    {
        $applicationId = $request->routeInt('id');

        if ($applicationId === null) {
            throw new HttpException(404, 'طلب التمويل غير موجود.');
        }

        $application = Database::selectOne(
            'SELECT a.*,
                    applicant.legal_name AS applicant_legal_name,
                    applicant.trading_name AS applicant_trading_name,
                    applicant.slug AS applicant_slug,
                    applicant.completion_score,
                    applicant.status AS applicant_status,
                    provider.legal_name AS provider_legal_name,
                    provider.trading_name AS provider_trading_name,
                    provider.slug AS provider_slug,
                    g.name_ar AS governorate_name,
                    s.name_ar AS sector_name
               FROM financing_applications a
               JOIN organizations applicant ON applicant.id = a.organization_id
               JOIN organizations provider ON provider.id = a.provider_organization_id
               LEFT JOIN governorates g ON g.id = applicant.governorate_id
               LEFT JOIN sectors s ON s.id = applicant.sector_id
              WHERE a.id = ? AND a.deleted_at IS NULL
              LIMIT 1',
            [$applicationId],
        );

        if ($application === null) {
            throw new HttpException(404, 'طلب التمويل غير موجود.');
        }

        return $this->view('admin/finance/application', [
            'pageTitle'   => 'طلب التمويل ' . $application['application_number'],
            'application' => $application,
            'history'     => $this->applications->history($applicationId, true),
            'documents'   => $this->applications->documents($applicationId),
            // الفرز والإحالة فقط: لا «اعتماد» ولا «رفض» في هذه القائمة
            'actions'     => $this->applications->actionsFor((string) $application['status'], 'platform'),
            'service'     => $this->applications,
        ], 'admin');
    }

    public function action(Request $request): Response
    {
        $applicationId = $request->routeInt('id');
        $action        = (string) ($request->input('action') ?? '');

        if ($applicationId === null) {
            throw new HttpException(404, 'طلب التمويل غير موجود.');
        }

        try {
            $result = $this->applications->transition(
                applicationId: $applicationId,
                action: $action,
                actorType: 'platform',
                actorUserId: $this->currentUserId(),
                actorOrganizationId: null,
                request: $request,
                note: $request->filled('note') ? (string) $request->input('note') : null,
                internalNote: $request->filled('internal_note') ? (string) $request->input('internal_note') : null,
            );

            $this->flash('success', 'حالة الطلب الآن: ' . $this->applications->statusLabel($result['to']) . '.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/admin/finance/applications/' . $applicationId);
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $rows = Database::select(
            'SELECT status, COUNT(*) AS total FROM financing_applications
              WHERE deleted_at IS NULL GROUP BY status',
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
