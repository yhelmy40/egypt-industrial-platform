<?php

declare(strict_types=1);

namespace App\Controllers\Bds;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\BdsCaseRepository;
use App\Repositories\MembershipRepository;
use App\Services\AssessmentService;
use App\Services\BdsCaseService;
use App\Support\TenantContext;

/**
 * حالات الدعم من جانب المركز | The centre side of case management (§4.8).
 *
 * مساحة عمل الأخصائي: الطابور، الفرز والإسناد، الجلسات، خطط العمل، الإحالات،
 * والملاحظات الداخلية التي لا يراها المشروع.
 *
 * كل قراءة هنا تمرّ بـ `SIDE_CENTER` صراحةً، وكل كتابة مقيَّدة بمعرّف المركز
 * المشتقّ من العضوية لا من النموذج.
 */
final class CaseController extends Controller
{
    public function __construct(
        private readonly BdsCaseRepository $cases = new BdsCaseRepository(),
        private readonly BdsCaseService $service = new BdsCaseService(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    // ═══════════════════ الطابور | The queue ═══════════════════

    public function index(Request $request): Response
    {
        $centerId = $this->requireOrganization();

        $status     = (string) ($request->input('status') ?? '');
        $assignedTo = $request->input('assigned_to') === 'me'
            ? $this->currentUserId()
            : $request->integer('assigned_to');

        return $this->view('bds/cases/index', [
            'pageTitle'     => 'حالات الدعم',
            'cases'         => $this->cases->listFor(
                BdsCaseRepository::SIDE_CENTER,
                $centerId,
                $status === '' ? null : $status,
                $assignedTo,
                (string) ($request->input('q') ?? ''),
            ),
            'counts'        => $this->cases->countsByStatus(BdsCaseRepository::SIDE_CENTER, $centerId),
            'summary'       => $this->cases->centerSummary($centerId),
            'specialists'   => $this->cases->specialistLoad($centerId),
            'filters'       => [
                'status'      => $status,
                'q'           => (string) ($request->input('q') ?? ''),
                'assigned_to' => (string) ($request->input('assigned_to') ?? ''),
            ],
            'service'       => $this->service,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function show(Request $request): Response
    {
        $centerId = $this->requireOrganization();
        $caseId   = $request->routeInt('id');

        if ($caseId === null) {
            throw new HttpException(404, 'حالة الدعم غير موجودة.');
        }

        $case = $this->cases->findFor(BdsCaseRepository::SIDE_CENTER, $caseId, $centerId);

        return $this->view('bds/cases/show', [
            'pageTitle'     => 'حالة الدعم ' . $case['case_number'],
            'case'          => $case,
            // نسخة المركز: تشمل الملاحظات الداخلية والخطط قبل مشاركتها
            'notes'         => $this->cases->notesFor(BdsCaseRepository::SIDE_CENTER, $caseId),
            'consultations' => $this->cases->consultationsFor(BdsCaseRepository::SIDE_CENTER, $caseId),
            'plans'         => $this->cases->plansFor(BdsCaseRepository::SIDE_CENTER, $caseId),
            'referrals'     => $this->cases->referralsFor($caseId),
            'history'       => $this->cases->history($caseId),
            'actions'       => $this->service->actionsFor(
                (string) $case['status'],
                BdsCaseRepository::SIDE_CENTER,
            ),
            'specialists'   => $this->cases->specialistLoad($centerId),
            'sections'      => AssessmentService::SECTIONS,
            'referable'     => $this->referableOffers(),
            'service'       => $this->service,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    // ═══════════════════ الإجراءات | Actions ═══════════════════

    public function action(Request $request): Response
    {
        $centerId = $this->requireOrganization();
        $caseId   = $request->routeInt('id');
        $action   = (string) ($request->input('action') ?? '');

        if ($caseId === null) {
            throw new HttpException(404, 'حالة الدعم غير موجودة.');
        }

        try {
            $result = $this->service->transition(
                caseId: $caseId,
                action: $action,
                side: BdsCaseRepository::SIDE_CENTER,
                actorUserId: $this->currentUserId(),
                actorOrganizationId: $centerId,
                request: $request,
                note: $request->filled('note') ? (string) $request->input('note') : null,
                extra: ['assigned_to' => $request->input('assigned_to')],
            );

            $this->flash('success', 'وضع الحالة الآن: ' . $this->service->statusLabel($result['to']) . '.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/bds/cases/' . $caseId);
    }

    public function addNote(Request $request): Response
    {
        $centerId = $this->requireOrganization();
        $caseId   = $request->routeInt('id');

        if ($caseId === null) {
            throw new HttpException(404, 'حالة الدعم غير موجودة.');
        }

        $visibility = (string) ($request->input('visibility') ?? 'internal');

        // كتابة ملاحظة مشتركة تتطلّب صلاحيتها تحديداً، لأنها تصل للمشروع
        if ($visibility === 'shared' && !TenantContext::can('bds.note.shared')) {
            $this->flash('warning', 'لا تملك صلاحية كتابة ملاحظات مشتركة مع المشروع.');

            return $this->redirect('/app/bds/cases/' . $caseId);
        }

        try {
            $this->service->addNote(
                caseId: $caseId,
                side: BdsCaseRepository::SIDE_CENTER,
                actorOrganizationId: $centerId,
                actorUserId: $this->currentUserId(),
                body: (string) ($request->input('body_ar') ?? ''),
                visibility: $visibility,
            );

            $this->flash(
                'success',
                $visibility === 'shared'
                    ? 'أُضيفت الملاحظة وسيراها المشروع.'
                    : 'أُضيفت الملاحظة الداخلية. لا يراها المشروع.',
            );
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/bds/cases/' . $caseId);
    }

    // ═══════════════════ الجلسات | Consultations ═══════════════════

    public function scheduleConsultation(Request $request): Response
    {
        $centerId = $this->requireOrganization();
        $caseId   = $request->routeInt('id');

        if ($caseId === null) {
            throw new HttpException(404, 'حالة الدعم غير موجودة.');
        }

        try {
            $this->service->scheduleConsultation(
                caseId: $caseId,
                centerOrganizationId: $centerId,
                actorUserId: $this->currentUserId(),
                data: $request->all(),
            );

            $this->flash('success', 'تمت جدولة الجلسة وأُشعِر المشروع بالموعد.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/bds/cases/' . $caseId);
    }

    public function recordConsultation(Request $request): Response
    {
        $centerId       = $this->requireOrganization();
        $caseId         = $request->routeInt('id');
        $consultationId = $request->integer('consultation_id');

        if ($caseId === null || $consultationId === null) {
            throw new HttpException(404, 'الجلسة غير موجودة.');
        }

        try {
            $this->service->recordConsultation(
                consultationId: $consultationId,
                centerOrganizationId: $centerId,
                status: (string) ($request->input('status') ?? ''),
                summary: $request->filled('summary_ar') ? (string) $request->input('summary_ar') : null,
                internalNote: $request->filled('internal_note_ar')
                    ? (string) $request->input('internal_note_ar') : null,
            );

            $this->flash('success', 'تم تسجيل نتيجة الجلسة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/bds/cases/' . $caseId);
    }

    // ═══════════════════ خطط العمل | Action plans ═══════════════════

    public function createPlan(Request $request): Response
    {
        $centerId = $this->requireOrganization();
        $caseId   = $request->routeInt('id');

        if ($caseId === null) {
            throw new HttpException(404, 'حالة الدعم غير موجودة.');
        }

        try {
            $this->service->createPlan($caseId, $centerId, $this->currentUserId(), $request->all());
            $this->flash('success', 'أُنشئت الخطة كمسودة. أضف مهامها ثم شاركها مع المشروع.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/bds/cases/' . $caseId);
    }

    public function addTask(Request $request): Response
    {
        $centerId = $this->requireOrganization();
        $caseId   = $request->routeInt('id');
        $planId   = $request->integer('plan_id');

        if ($caseId === null || $planId === null) {
            throw new HttpException(404, 'خطة العمل غير موجودة.');
        }

        try {
            $this->service->addTask($planId, $centerId, $request->all());
            $this->flash('success', 'أُضيفت المهمة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/bds/cases/' . $caseId);
    }

    public function sharePlan(Request $request): Response
    {
        $centerId = $this->requireOrganization();
        $caseId   = $request->routeInt('id');
        $planId   = $request->integer('plan_id');

        if ($caseId === null || $planId === null) {
            throw new HttpException(404, 'خطة العمل غير موجودة.');
        }

        try {
            $this->service->sharePlan($planId, $centerId);
            $this->flash('success', 'شُوركت الخطة مع المشروع.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/bds/cases/' . $caseId);
    }

    public function updateTask(Request $request): Response
    {
        $centerId = $this->requireOrganization();
        $caseId   = $request->routeInt('id');
        $taskId   = $request->integer('task_id');

        if ($caseId === null || $taskId === null) {
            throw new HttpException(404, 'المهمة غير موجودة.');
        }

        try {
            $this->service->updateTask(
                taskId: $taskId,
                side: BdsCaseRepository::SIDE_CENTER,
                actorOrganizationId: $centerId,
                actorUserId: $this->currentUserId(),
                status: (string) ($request->input('status') ?? ''),
            );

            $this->flash('success', 'تم تحديث المهمة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/bds/cases/' . $caseId);
    }

    // ═══════════════════ الإحالات | Referrals ═══════════════════

    public function refer(Request $request): Response
    {
        $centerId = $this->requireOrganization();
        $caseId   = $request->routeInt('id');

        if ($caseId === null) {
            throw new HttpException(404, 'حالة الدعم غير موجودة.');
        }

        try {
            $this->service->refer(
                caseId: $caseId,
                centerOrganizationId: $centerId,
                actorUserId: $this->currentUserId(),
                targetType: (string) ($request->input('target_type') ?? ''),
                targetId: (int) ($request->input('target_id') ?? 0),
                reason: (string) ($request->input('reason_ar') ?? ''),
            );

            $this->flash('success', 'سُجّلت التوصية وأُشعِر المشروع بها. القرار قراره.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/bds/cases/' . $caseId);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /**
     * العروض المتاحة للإحالة | Offers a centre may refer to.
     *
     * معتمدة ومن جهات موثّقة فقط — نفس حدّ الرؤية العام. إحالة إلى عرض غير
     * معتمد تلتفّ على بوابة الاعتماد كلها.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function referableOffers(): array
    {
        return [
            'financing_product' => Database::select(
                "SELECT t.id, t.name_ar, o.legal_name, o.trading_name
                   FROM financing_products t
                   JOIN organizations o ON o.id = t.organization_id
                  WHERE t.status = 'published' AND t.deleted_at IS NULL
                    AND o.status = 'verified' AND o.deleted_at IS NULL
                  ORDER BY t.name_ar ASC
                  LIMIT 200",
            ),
            'service_offering' => Database::select(
                "SELECT t.id, t.name_ar, o.legal_name, o.trading_name
                   FROM service_offerings t
                   JOIN organizations o ON o.id = t.organization_id
                  WHERE t.status = 'published' AND t.deleted_at IS NULL
                    AND o.status = 'verified' AND o.deleted_at IS NULL
                  ORDER BY t.name_ar ASC
                  LIMIT 200",
            ),
        ];
    }
}
