<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CustomerRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\PipelineRepository;
use App\Services\PipelineService;

/**
 * المهتمّون والفرص والمهام | Leads, opportunities and tasks (§4.9).
 */
final class PipelineController extends Controller
{
    public function __construct(
        private readonly PipelineRepository $pipeline = new PipelineRepository(),
        private readonly PipelineService $service = new PipelineService(),
        private readonly CustomerRepository $customers = new CustomerRepository(),
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    // ═══════════════════ المهتمّون | Leads ═══════════════════

    public function leads(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $filters = [
            'q'      => (string) ($request->input('q') ?? ''),
            'status' => (string) ($request->input('status') ?? ''),
        ];

        return $this->view('sme/pipeline/leads', array_merge(
            $this->chrome($organizationId, 'المهتمّون'),
            [
                'results' => $this->pipeline->searchLeads($organizationId, $filters, $this->page($request)),
                'filters' => $filters,
                'service' => $this->service,
            ],
        ), 'app');
    }

    public function storeLead(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        try {
            $this->service->createLead($organizationId, $request->all(), $this->currentUserId());
            $this->flash('success', 'أُضيف المهتمّ.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/pipeline/leads');
    }

    public function updateLead(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $leadId         = $this->routeId($request, 'المهتمّ غير موجود.');

        try {
            $this->service->updateLeadStatus(
                $leadId,
                $organizationId,
                (string) ($request->input('status') ?? ''),
                $request->input('lost_reason_ar'),
            );
            $this->flash('success', 'حُدِّثت حالة المهتمّ.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/pipeline/leads');
    }

    /**
     * تحويل مهتمّ إلى عميل | Convert a lead into a customer.
     *
     * التحويل ينقل صاحب المشروع مباشرةً إلى ملفّ العميل الجديد: الخطوة التالية
     * بعد التحويل هي العمل على العلاقة لا العودة لقائمة المهتمّين.
     */
    public function convertLead(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $leadId         = $this->routeId($request, 'المهتمّ غير موجود.');

        try {
            $customerId = $this->service->convertLead($leadId, $organizationId);
            $this->flash('success', 'حُوِّل المهتمّ إلى عميل في دفترك.');

            return $this->redirect('/app/customers/' . $customerId);
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/app/pipeline/leads');
        }
    }

    // ═══════════════════ الفرص | Opportunities ═══════════════════

    public function opportunities(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $stage          = (string) ($request->input('stage') ?? '');

        return $this->view('sme/pipeline/opportunities', array_merge(
            $this->chrome($organizationId, 'خطّ الفرص'),
            [
                'opportunities' => $this->pipeline->opportunities($organizationId, ['stage' => $stage]),
                'summary'       => $this->pipeline->pipelineSummary($organizationId),
                'customers'     => $this->customers->selectable($organizationId),
                'filters'       => ['stage' => $stage],
                'stages'        => PipelineRepository::STAGES,
                'service'       => $this->service,
            ],
        ), 'app');
    }

    public function storeOpportunity(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        try {
            $this->service->createOpportunity($organizationId, $request->all(), $this->currentUserId());
            $this->flash('success', 'أُضيفت الفرصة إلى خطّ المبيعات.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/pipeline/opportunities');
    }

    public function showOpportunity(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $opportunityId  = $this->routeId($request, 'الفرصة غير موجودة.');
        $opportunity    = $this->pipeline->findOpportunity($opportunityId, $organizationId);

        if ($opportunity === null) {
            throw new HttpException(404, 'الفرصة غير موجودة.');
        }

        return $this->view('sme/pipeline/opportunity', array_merge(
            $this->chrome($organizationId, $opportunity['title_ar']),
            [
                'opportunity' => $opportunity,
                'activities'  => $this->pipeline->activitiesFor(
                    'opportunity',
                    $opportunityId,
                    $organizationId,
                    30,
                ),
                'nextStages'  => $this->service->nextStages((string) $opportunity['stage']),
                'service'     => $this->service,
            ],
        ), 'app');
    }

    public function updateOpportunity(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $opportunityId  = $this->routeId($request, 'الفرصة غير موجودة.');

        try {
            $this->service->updateOpportunity($opportunityId, $organizationId, $request->all());
            $this->flash('success', 'حُفظت بيانات الفرصة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/pipeline/opportunities/' . $opportunityId);
    }

    public function moveStage(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $opportunityId  = $this->routeId($request, 'الفرصة غير موجودة.');

        try {
            $this->service->moveStage(
                $opportunityId,
                $organizationId,
                (string) ($request->input('stage') ?? ''),
                $request->input('close_reason_ar'),
            );
            $this->flash('success', 'نُقلت الفرصة إلى المرحلة الجديدة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/pipeline/opportunities/' . $opportunityId);
    }

    // ═══════════════════ الأنشطة والمهام | Activities and tasks ═══════════════════

    public function tasks(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $mine           = $request->input('mine') !== null;

        return $this->view('sme/pipeline/tasks', array_merge(
            $this->chrome($organizationId, 'المهام والمتابعات'),
            [
                'tasks'     => $this->pipeline->dueTasks(
                    $organizationId,
                    $mine ? $this->currentUserId() : null,
                ),
                'customers' => $this->customers->selectable($organizationId),
                'mine'      => $mine,
                'service'   => $this->service,
            ],
        ), 'app');
    }

    public function storeActivity(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        try {
            $this->service->logActivity($organizationId, $request->all(), $this->currentUserId());
            $this->flash('success', 'سُجّل النشاط.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->back($request, [], '/app/pipeline/tasks');
    }

    public function completeActivity(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $activityId     = $this->routeId($request, 'النشاط غير موجود.');

        try {
            $this->service->completeActivity(
                $activityId,
                $organizationId,
                $request->input('outcome_ar'),
            );
            $this->flash('success', 'أُنجزت المهمة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->back($request, [], '/app/pipeline/tasks');
    }

    public function cancelActivity(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $activityId     = $this->routeId($request, 'النشاط غير موجود.');

        try {
            $this->service->cancelActivity($activityId, $organizationId);
            $this->flash('success', 'أُلغيت المهمة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->back($request, [], '/app/pipeline/tasks');
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function routeId(Request $request, string $message): int
    {
        $id = $request->routeInt('id');

        if ($id === null) {
            throw new HttpException(404, $message);
        }

        return $id;
    }

    /** @return array<string,mixed> */
    private function chrome(int $organizationId, string $title): array
    {
        return [
            'pageTitle'     => $title,
            'organization'  => $this->organizations->findWithDetails($organizationId),
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ];
    }
}
