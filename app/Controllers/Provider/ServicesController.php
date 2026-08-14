<?php

declare(strict_types=1);

namespace App\Controllers\Provider;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\MembershipRepository;
use App\Repositories\ServiceOfferingRepository;
use App\Services\ServiceOfferingService;
use App\Services\ServiceRequestService;
use App\Validation\Validator;

/**
 * الخدمات من جانب مقدّم الخدمة | The provider side of business services (§4.6).
 *
 * يُدخل باقاته ويرسلها للاعتماد، ثم يستقبل الطلبات ويقدّم عروضه ويتابع التنفيذ
 * بمراحل. لا تظهر أي باقة للمشروعات قبل اعتماد المنصة.
 */
final class ServicesController extends Controller
{
    public function __construct(
        private readonly ServiceOfferingRepository $offerings = new ServiceOfferingRepository(),
        private readonly ServiceOfferingService $offeringService = new ServiceOfferingService(),
        private readonly ServiceRequestService $requests = new ServiceRequestService(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    // ═══════════════════ الباقات | Offerings ═══════════════════

    public function offerings(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $status         = (string) ($request->input('status') ?? '');

        return $this->view('provider/services/offerings', [
            'pageTitle'     => 'باقات الخدمات',
            'offerings'     => $this->offerings->forOrganization($organizationId, $status === '' ? null : $status),
            'counts'        => $this->offerings->countsByStatus($organizationId),
            'filters'       => ['status' => $status],
            'service'       => $this->offeringService,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function createForm(Request $request): Response
    {
        $this->requireOrganization();

        return $this->offeringForm(null);
    }

    public function editForm(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $offeringId     = $request->routeInt('id');

        if ($offeringId === null) {
            throw new HttpException(404, 'باقة الخدمة غير موجودة.');
        }

        $offering = $this->offerings->findOwned($offeringId, $organizationId);

        if ($offering === null) {
            throw new HttpException(404, 'باقة الخدمة غير موجودة.');
        }

        return $this->offeringForm($offering);
    }

    public function store(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $validator = $this->offeringValidator($request);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/services/offerings/new');
        }

        try {
            $id = $this->offeringService->create(
                $organizationId,
                $this->offeringInput($request),
                $this->currentUserId(),
                $request,
            );

            $this->flash('success', 'تم حفظ الباقة كمسودة. أرسلها للاعتماد لتظهر للمشروعات.');

            return $this->redirect('/app/services/offerings/' . $id);
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/app/services/offerings/new');
        }
    }

    public function update(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $offeringId     = $request->routeInt('id');

        if ($offeringId === null) {
            throw new HttpException(404, 'باقة الخدمة غير موجودة.');
        }

        $validator = $this->offeringValidator($request);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/services/offerings/' . $offeringId);
        }

        try {
            $this->offeringService->update(
                $offeringId,
                $organizationId,
                $this->offeringInput($request),
                $this->currentUserId(),
                $request,
            );

            $this->flash('success', 'تم حفظ التعديلات.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/services/offerings/' . $offeringId);
    }

    public function submit(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $offeringId     = $request->routeInt('id');

        if ($offeringId === null) {
            throw new HttpException(404, 'باقة الخدمة غير موجودة.');
        }

        try {
            $this->offeringService->submitForApproval(
                $offeringId,
                $organizationId,
                $this->currentUserId(),
                $request,
            );

            $this->flash('success', 'أُرسلت الباقة لاعتماد المنصة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/services/offerings/' . $offeringId);
    }

    public function archive(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $offeringId     = $request->routeInt('id');

        if ($offeringId === null) {
            throw new HttpException(404, 'باقة الخدمة غير موجودة.');
        }

        $this->offeringService->archive($offeringId, $organizationId, $this->currentUserId(), $request);
        $this->flash('success', 'تم سحب الباقة.');

        return $this->redirect('/app/services/offerings/' . $offeringId);
    }

    // ═══════════════════ الطلبات الواردة | Incoming requests ═══════════════════

    public function requests(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $status         = (string) ($request->input('status') ?? '');

        return $this->view('provider/services/requests', [
            'pageTitle'     => 'طلبات الخدمة الواردة',
            'requests'      => $this->requests->listFor('provider', $organizationId, $status === '' ? null : $status),
            'counts'        => $this->requests->countsByStatus('provider', $organizationId),
            'filters'       => ['status' => $status],
            'service'       => $this->requests,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function showRequest(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $requestId      = $request->routeInt('id');

        if ($requestId === null) {
            throw new HttpException(404, 'طلب الخدمة غير موجود.');
        }

        $serviceRequest = $this->requests->findForProvider($requestId, $organizationId);

        return $this->view('provider/services/request', [
            'pageTitle'      => 'طلب الخدمة ' . $serviceRequest['request_number'],
            'serviceRequest' => $serviceRequest,
            'history'        => $this->requests->history($requestId),
            'milestones'     => $this->requests->milestones($requestId),
            'actions'        => $this->requests->actionsFor((string) $serviceRequest['status'], 'provider'),
            'service'        => $this->requests,
            'organizations'  => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function requestAction(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $requestId      = $request->routeInt('id');
        $action         = (string) ($request->input('action') ?? '');

        if ($requestId === null) {
            throw new HttpException(404, 'طلب الخدمة غير موجود.');
        }

        try {
            $result = $this->requests->transition(
                requestId: $requestId,
                action: $action,
                actorType: 'provider',
                actorUserId: $this->currentUserId(),
                actorOrganizationId: $organizationId,
                request: $request,
                note: $request->filled('note') ? (string) $request->input('note') : null,
                proposal: [
                    'proposed_price'         => $request->input('proposed_price'),
                    'proposed_duration_days' => $request->input('proposed_duration_days'),
                    'proposal_note_ar'       => $request->input('proposal_note_ar'),
                ],
            );

            $this->flash('success', 'حالة الطلب الآن: ' . $this->requests->statusLabel($result['to']) . '.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/services/requests/' . $requestId);
    }

    public function addMilestone(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $requestId      = $request->routeInt('id');

        if ($requestId === null) {
            throw new HttpException(404, 'طلب الخدمة غير موجود.');
        }

        try {
            $this->requests->addMilestone(
                requestId: $requestId,
                providerOrganizationId: $organizationId,
                title: (string) ($request->input('title_ar') ?? ''),
                description: $request->filled('description_ar') ? (string) $request->input('description_ar') : null,
                dueDate: $request->filled('due_date') ? (string) $request->input('due_date') : null,
            );

            $this->flash('success', 'أُضيفت المرحلة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/services/requests/' . $requestId);
    }

    public function updateMilestone(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $requestId      = $request->routeInt('id');
        $milestoneId    = $request->integer('milestone_id');

        if ($requestId === null || $milestoneId === null) {
            throw new HttpException(404, 'المرحلة غير موجودة.');
        }

        try {
            $this->requests->updateMilestone(
                $milestoneId,
                $organizationId,
                (string) ($request->input('status') ?? ''),
            );

            $this->flash('success', 'تم تحديث المرحلة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/services/requests/' . $requestId);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @param array<string,mixed>|null $offering */
    private function offeringForm(?array $offering): Response
    {
        return $this->view('provider/services/offering-form', [
            'pageTitle'      => $offering === null ? 'باقة خدمة جديدة' : 'تعديل: ' . $offering['name_ar'],
            'offering'       => $offering,
            'types'          => ServiceOfferingService::TYPES,
            'deliveryModes'  => ServiceOfferingService::DELIVERY_MODES,
            'pricingModes'   => ServiceOfferingService::PRICING_MODES,
            'service'        => $this->offeringService,
            'governorates'   => Database::select(
                'SELECT id, name_ar FROM governorates WHERE is_active = 1 ORDER BY sort_order ASC',
            ),
            'sectors'        => Database::select(
                'SELECT id, name_ar FROM sectors WHERE is_active = 1 ORDER BY sort_order ASC',
            ),
            'categories'     => Database::select(
                "SELECT id, name_ar FROM categories WHERE type = 'service' AND is_active = 1
                  ORDER BY sort_order ASC",
            ),
            'organizations'  => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    private function offeringValidator(Request $request): Validator
    {
        return Validator::make($request->all())
            ->labels([
                'name_ar'            => 'اسم الباقة',
                'short_description'  => 'الوصف المختصر',
                'deliverables_ar'    => 'المخرجات',
                'target_audience_ar' => 'الفئة المستهدفة',
            ])
            ->required('name_ar')->minLength('name_ar', 3)->maxLength('name_ar', 200)
            ->maxLength('short_description', 500)
            ->numeric('price_from')->numeric('price_to')
            ->maxLength('duration_note_ar', 300)
            ->maxLength('funded_by_ar', 200)
            ->maxLength('target_audience_ar', 1000)
            ->maxLength('deliverables_ar', 2000);
    }

    /** @return array<string,mixed> */
    private function offeringInput(Request $request): array
    {
        $data = $request->all();

        // القوائم المتعدّدة تصل كمصفوفات، ويتكفّل التنظيف بها في الخدمة
        $data['governorate_ids'] = $request->array('governorate_ids');
        $data['sector_ids']      = $request->array('sector_ids');

        return $data;
    }
}
