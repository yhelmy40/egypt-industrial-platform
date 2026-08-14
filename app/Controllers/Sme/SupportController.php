<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\BdsCaseRepository;
use App\Repositories\MembershipRepository;
use App\Services\AssessmentService;
use App\Services\BdsCaseService;

/**
 * الدعم من جانب المشروع | The SME side of BDS support (§4.8).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * كل قراءة هنا تمرّ بـ `SIDE_ORGANIZATION`، وهو ما يجعل المستودع يستبعد
 * الملاحظات الداخلية والخطط غير المشتركة **من الاستعلام نفسه**. لا يوجد في
 * هذا المتحكّم مسار واحد يجلب نسخة المركز ثم يفلترها.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class SupportController extends Controller
{
    public function __construct(
        private readonly BdsCaseRepository $cases = new BdsCaseRepository(),
        private readonly BdsCaseService $service = new BdsCaseService(),
        private readonly AssessmentService $assessments = new AssessmentService(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    // ═══════════════════ المراكز وطلب الدعم | Centres and requesting ═══════════════════

    public function centers(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $governorateId  = $request->integer('governorate_id');

        return $this->view('sme/support/centers', [
            'pageTitle'     => 'مراكز تطوير الأعمال',
            'centers'       => $this->availableCenters($governorateId),
            'myCases'       => $this->cases->listFor(
                BdsCaseRepository::SIDE_ORGANIZATION,
                $organizationId,
                null,
                null,
                '',
                10,
            ),
            'governorates'  => Database::select(
                'SELECT id, name_ar FROM governorates WHERE is_active = 1 ORDER BY sort_order ASC',
            ),
            'filters'       => ['governorate_id' => (string) ($request->input('governorate_id') ?? '')],
            'service'       => $this->service,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function requestForm(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $centerId       = $request->routeInt('id');

        if ($centerId === null) {
            throw new HttpException(404, 'المركز المطلوب غير موجود.');
        }

        $center = $this->findCenter($centerId);

        return $this->view('sme/support/request-form', [
            'pageTitle'     => 'طلب دعم من ' . ($center['trading_name'] ?: $center['legal_name']),
            'center'        => $center,
            'sections'      => AssessmentService::SECTIONS,
            // التقييم الأخير يُقترح كمرفق: المركز يبدأ من تشخيص لا من صفحة بيضاء
            'assessment'    => $this->assessments->latestCompleted($organizationId),
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function submitRequest(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $centerId       = $request->routeInt('id');

        if ($centerId === null) {
            throw new HttpException(404, 'المركز المطلوب غير موجود.');
        }

        try {
            $result = $this->service->request(
                organizationId: $organizationId,
                centerOrganizationId: $centerId,
                data: [
                    'title_ar'           => $request->input('title_ar'),
                    'request_details_ar' => $request->input('request_details_ar'),
                    'focus_areas'        => $request->array('focus_areas'),
                    'assessment_id'      => $request->input('assessment_id'),
                ],
                actorId: $this->currentUserId(),
                request: $request,
            );

            $this->flash('success', 'أُرسل طلبك برقم ' . $result['number'] . ' إلى المركز.');

            return $this->redirect('/app/bds/my-cases/' . $result['id']);
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/app/bds/centers/' . $centerId . '/request');
        }
    }

    // ═══════════════════ حالاتي | My cases ═══════════════════

    public function myCases(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $status         = (string) ($request->input('status') ?? '');

        return $this->view('sme/support/my-cases', [
            'pageTitle'     => 'طلبات الدعم',
            'cases'         => $this->cases->listFor(
                BdsCaseRepository::SIDE_ORGANIZATION,
                $organizationId,
                $status === '' ? null : $status,
            ),
            'counts'        => $this->cases->countsByStatus(
                BdsCaseRepository::SIDE_ORGANIZATION,
                $organizationId,
            ),
            'filters'       => ['status' => $status],
            'service'       => $this->service,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function showCase(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $caseId         = $request->routeInt('id');

        if ($caseId === null) {
            throw new HttpException(404, 'حالة الدعم غير موجودة.');
        }

        $case = $this->cases->findFor(BdsCaseRepository::SIDE_ORGANIZATION, $caseId, $organizationId);

        return $this->view('sme/support/case', [
            'pageTitle'     => 'حالة الدعم ' . $case['case_number'],
            'case'          => $case,
            // نسخة المشروع: المشتركة فقط — الاستبعاد يقع في الاستعلام
            'notes'         => $this->cases->notesFor(BdsCaseRepository::SIDE_ORGANIZATION, $caseId),
            'consultations' => $this->cases->consultationsFor(BdsCaseRepository::SIDE_ORGANIZATION, $caseId),
            'plans'         => $this->cases->plansFor(BdsCaseRepository::SIDE_ORGANIZATION, $caseId),
            'referrals'     => $this->cases->referralsFor($caseId),
            'history'       => $this->cases->history($caseId),
            'actions'       => $this->service->actionsFor(
                (string) $case['status'],
                BdsCaseRepository::SIDE_ORGANIZATION,
            ),
            'sections'      => AssessmentService::SECTIONS,
            'service'       => $this->service,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function action(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $caseId         = $request->routeInt('id');

        if ($caseId === null) {
            throw new HttpException(404, 'حالة الدعم غير موجودة.');
        }

        try {
            $result = $this->service->transition(
                caseId: $caseId,
                action: (string) ($request->input('action') ?? ''),
                side: BdsCaseRepository::SIDE_ORGANIZATION,
                actorUserId: $this->currentUserId(),
                actorOrganizationId: $organizationId,
                request: $request,
                note: $request->filled('note') ? (string) $request->input('note') : null,
            );

            $this->flash('success', 'وضع الحالة الآن: ' . $this->service->statusLabel($result['to']) . '.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/bds/my-cases/' . $caseId);
    }

    /** ردّ المشروع على ملاحظة مشتركة | The SME replies on the shared thread. */
    public function addNote(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $caseId         = $request->routeInt('id');

        if ($caseId === null) {
            throw new HttpException(404, 'حالة الدعم غير موجودة.');
        }

        try {
            $this->service->addNote(
                caseId: $caseId,
                side: BdsCaseRepository::SIDE_ORGANIZATION,
                actorOrganizationId: $organizationId,
                actorUserId: $this->currentUserId(),
                body: (string) ($request->input('body_ar') ?? ''),
                // المشروع لا يملك مساحة داخلية: كل ما يكتبه مشترك
                visibility: 'shared',
            );

            $this->flash('success', 'أُضيفت ملاحظتك.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/bds/my-cases/' . $caseId);
    }

    public function updateTask(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $caseId         = $request->routeInt('id');
        $taskId         = $request->integer('task_id');

        if ($caseId === null || $taskId === null) {
            throw new HttpException(404, 'المهمة غير موجودة.');
        }

        try {
            $this->service->updateTask(
                taskId: $taskId,
                side: BdsCaseRepository::SIDE_ORGANIZATION,
                actorOrganizationId: $organizationId,
                actorUserId: $this->currentUserId(),
                status: (string) ($request->input('status') ?? ''),
            );

            $this->flash('success', 'تم تحديث المهمة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/bds/my-cases/' . $caseId);
    }

    public function respondToReferral(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $caseId         = $request->routeInt('id');
        $referralId     = $request->integer('referral_id');

        if ($caseId === null || $referralId === null) {
            throw new HttpException(404, 'التوصية غير موجودة.');
        }

        try {
            $this->service->respondToReferral(
                referralId: $referralId,
                organizationId: $organizationId,
                decision: (string) ($request->input('decision') ?? ''),
                note: $request->filled('note') ? (string) $request->input('note') : null,
            );

            $this->flash('success', 'سُجّل ردّك على التوصية.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/bds/my-cases/' . $caseId);
    }

    /** تقييم المشروع للدعم بعد الإغلاق | Rate the support after closure. */
    public function rate(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $caseId         = $request->routeInt('id');

        if ($caseId === null) {
            throw new HttpException(404, 'حالة الدعم غير موجودة.');
        }

        $case   = $this->cases->findFor(BdsCaseRepository::SIDE_ORGANIZATION, $caseId, $organizationId);
        $rating = $request->integer('rating');

        if (!str_starts_with((string) $case['status'], 'closed_')) {
            $this->flash('warning', 'التقييم متاح بعد إغلاق الحالة.');

            return $this->redirect('/app/bds/my-cases/' . $caseId);
        }

        if ($rating === null || $rating < 1 || $rating > 5) {
            $this->flash('warning', 'اختر تقييماً من ١ إلى ٥.');

            return $this->redirect('/app/bds/my-cases/' . $caseId);
        }

        Database::statement(
            'UPDATE bds_cases
                SET satisfaction_rating = ?, satisfaction_comment_ar = ?
              WHERE id = ? AND organization_id = ?',
            [
                $rating,
                $request->filled('comment')
                    ? mb_substr((string) $request->input('comment'), 0, 1000) : null,
                $caseId,
                $organizationId,
            ],
        );

        $this->flash('success', 'شكراً لتقييمك.');

        return $this->redirect('/app/bds/my-cases/' . $caseId);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @return array<int,array<string,mixed>> */
    private function availableCenters(?int $governorateId): array
    {
        $where    = ["t.code = 'bds_center'", "o.status = 'verified'", 'o.deleted_at IS NULL'];
        $bindings = [];

        if ($governorateId !== null && $governorateId > 0) {
            // المركز الذي يخدم كل المحافظات لا يختفي عند تحديد محافظة
            $where[]    = '(b.serves_all_governorates = 1 OR FIND_IN_SET(?, b.coverage_governorates)
                            OR o.governorate_id = ?)';
            $bindings[] = $governorateId;
            $bindings[] = $governorateId;
        }

        $whereSql = implode(' AND ', $where);

        return Database::select(
            "SELECT o.id, o.legal_name, o.trading_name, o.slug, o.short_description,
                    o.logo_media_id, o.is_demo,
                    g.name_ar AS governorate_name,
                    b.host_entity, b.services_offered, b.working_hours,
                    b.appointment_required, b.serves_all_governorates
               FROM organizations o
               JOIN organization_types t ON t.id = o.organization_type_id
               LEFT JOIN bds_centers b ON b.organization_id = o.id
               LEFT JOIN governorates g ON g.id = o.governorate_id
              WHERE {$whereSql}
              ORDER BY o.legal_name ASC
              LIMIT 100",
            $bindings,
        );
    }

    /** @return array<string,mixed> */
    private function findCenter(int $centerId): array
    {
        $center = Database::selectOne(
            "SELECT o.*, b.host_entity, b.services_offered, b.working_hours,
                    b.appointment_required, g.name_ar AS governorate_name
               FROM organizations o
               JOIN organization_types t ON t.id = o.organization_type_id
               LEFT JOIN bds_centers b ON b.organization_id = o.id
               LEFT JOIN governorates g ON g.id = o.governorate_id
              WHERE o.id = ? AND t.code = 'bds_center'
                AND o.status = 'verified' AND o.deleted_at IS NULL
              LIMIT 1",
            [$centerId],
        );

        if ($center === null) {
            throw new HttpException(404, 'مركز تطوير الأعمال المطلوب غير متاح.');
        }

        return $center;
    }
}
