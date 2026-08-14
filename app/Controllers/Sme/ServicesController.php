<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\MembershipRepository;
use App\Repositories\ServiceOfferingRepository;
use App\Services\MatchingService;
use App\Services\ServiceOfferingService;
use App\Services\ServiceRequestService;

/**
 * الخدمات من جانب المشروع | The applicant side of business services (§4.6).
 *
 * المشروع يتصفّح الباقات المعتمدة، يطلب ما يحتاجه، ثم يقرّر بنفسه قبول العرض
 * أو رفضه ويؤكّد الاستلام في نهاية التنفيذ. المزوّد لا يملك أياً من هذه
 * القرارات الثلاثة.
 */
final class ServicesController extends Controller
{
    public function __construct(
        private readonly ServiceOfferingRepository $offerings = new ServiceOfferingRepository(),
        private readonly ServiceOfferingService $offeringService = new ServiceOfferingService(),
        private readonly ServiceRequestService $requests = new ServiceRequestService(),
        private readonly MatchingService $matching = new MatchingService(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    // ═══════════════════ التصفّح والطلب | Browse and request ═══════════════════

    public function browse(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $filters = [
            'q'             => (string) ($request->input('q') ?? ''),
            'service_type'  => (string) ($request->input('service_type') ?? ''),
            'delivery_mode' => (string) ($request->input('delivery_mode') ?? ''),
            'free_only'     => $request->input('free_only') ? '1' : '',
            'sort'          => (string) ($request->input('sort') ?? 'newest'),
        ];

        return $this->view('sme/services/browse', [
            'pageTitle'      => 'الخدمات غير المالية',
            'results'        => $this->offerings->searchPublic($filters, $this->page($request)),
            'filters'        => $filters,
            'types'          => ServiceOfferingService::TYPES,
            'deliveryModes'  => ServiceOfferingService::DELIVERY_MODES,
            'suggestions'    => $this->matching->storedFor($organizationId, 'service_offering'),
            'service'        => $this->offeringService,
            'organizations'  => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function requestForm(Request $request): Response
    {
        $this->requireOrganization();
        $offeringId = $request->routeInt('id');

        if ($offeringId === null) {
            throw new HttpException(404, 'الخدمة المطلوبة غير موجودة.');
        }

        $offering = $this->offerings->findPublicById($offeringId);

        if ($offering === null) {
            throw new HttpException(404, 'الخدمة المطلوبة غير متاحة.');
        }

        return $this->view('sme/services/request-form', [
            'pageTitle'     => 'طلب خدمة',
            'offering'      => $offering,
            'service'       => $this->offeringService,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function submitRequest(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $offeringId     = $request->routeInt('id');

        if ($offeringId === null) {
            throw new HttpException(404, 'الخدمة المطلوبة غير موجودة.');
        }

        try {
            $result = $this->requests->submit(
                organizationId: $organizationId,
                offeringId: $offeringId,
                data: $request->all(),
                actorId: $this->currentUserId(),
                request: $request,
            );

            $this->flash('success', 'أُرسل طلبك برقم ' . $result['number'] . ' إلى مقدّم الخدمة.');

            return $this->redirect('/app/services/my-requests/' . $result['id']);
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/app/services/browse/' . $offeringId . '/request');
        }
    }

    // ═══════════════════ طلباتي | My requests ═══════════════════

    public function myRequests(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $status         = (string) ($request->input('status') ?? '');

        return $this->view('sme/services/my-requests', [
            'pageTitle'     => 'طلبات الخدمة',
            'requests'      => $this->requests->listFor('applicant', $organizationId, $status === '' ? null : $status),
            'counts'        => $this->requests->countsByStatus('applicant', $organizationId),
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

        $serviceRequest = $this->requests->findForApplicant($requestId, $organizationId);

        return $this->view('sme/services/request', [
            'pageTitle'      => 'طلب الخدمة ' . $serviceRequest['request_number'],
            'serviceRequest' => $serviceRequest,
            'history'        => $this->requests->history($requestId),
            'milestones'     => $this->requests->milestones($requestId),
            'actions'        => $this->requests->actionsFor((string) $serviceRequest['status'], 'applicant'),
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
                actorType: 'applicant',
                actorUserId: $this->currentUserId(),
                actorOrganizationId: $organizationId,
                request: $request,
                note: $request->filled('note') ? (string) $request->input('note') : null,
            );

            $this->flash('success', 'حالة الطلب الآن: ' . $this->requests->statusLabel($result['to']) . '.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/services/my-requests/' . $requestId);
    }
}
