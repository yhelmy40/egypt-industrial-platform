<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CustomerRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\PipelineRepository;
use App\Services\CustomerService;
use App\Services\PipelineService;

/**
 * دفتر العملاء | The customer book (§4.9).
 *
 * كل استعلام مقيَّد بالمنشأة النشطة: محاولة فتح عميل منشأة أخرى تعود 404 لا
 * 403، فلا يؤكّد الردّ وجود السجلّ.
 */
final class CustomersController extends Controller
{
    public function __construct(
        private readonly CustomerRepository $customers = new CustomerRepository(),
        private readonly PipelineRepository $pipeline = new PipelineRepository(),
        private readonly CustomerService $service = new CustomerService(),
        private readonly PipelineService $pipelineService = new PipelineService(),
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    public function index(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $filters = [
            'q'              => (string) ($request->input('q') ?? ''),
            'status'         => (string) ($request->input('status') ?? ''),
            'customer_type'  => (string) ($request->input('customer_type') ?? ''),
            'governorate_id' => (string) ($request->input('governorate_id') ?? ''),
        ];

        return $this->view('sme/customers/index', array_merge(
            $this->chrome($organizationId, 'العملاء'),
            [
                'results'      => $this->customers->search($organizationId, $filters, $this->page($request)),
                'filters'      => $filters,
                'governorates' => $this->governorates(),
                'service'      => $this->service,
            ],
        ), 'app');
    }

    public function create(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        return $this->view('sme/customers/form', array_merge(
            $this->chrome($organizationId, 'إضافة عميل'),
            [
                'customer'     => null,
                'governorates' => $this->governorates(),
                'service'      => $this->service,
            ],
        ), 'app');
    }

    public function store(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        try {
            $customerId = $this->service->create($organizationId, $request->all());
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->back($request, [], '/app/customers/new');
        }

        $this->flash('success', 'أُضيف العميل إلى دفترك.');

        return $this->redirect('/app/customers/' . $customerId);
    }

    public function show(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $customerId     = $this->routeId($request);
        $customer       = $this->customers->findOwned($customerId, $organizationId);

        if ($customer === null) {
            throw new HttpException(404, 'العميل غير موجود.');
        }

        return $this->view('sme/customers/show', array_merge(
            $this->chrome($organizationId, $customer['name_ar']),
            [
                'customer'      => $customer,
                'contacts'      => $this->customers->contacts($customerId, $organizationId),
                'summary'       => $this->customers->summary($customerId, $organizationId),
                'activities'    => $this->pipeline->activitiesFor('customer', $customerId, $organizationId, 30),
                'opportunities' => $this->pipeline->opportunities($organizationId, ['customer_id' => $customerId]),
                'invoices'      => $this->recentInvoices($customerId, $organizationId),
                'service'       => $this->service,
                'pipeline'      => $this->pipelineService,
            ],
        ), 'app');
    }

    public function edit(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $customerId     = $this->routeId($request);
        $customer       = $this->customers->findOwned($customerId, $organizationId);

        if ($customer === null) {
            throw new HttpException(404, 'العميل غير موجود.');
        }

        return $this->view('sme/customers/form', array_merge(
            $this->chrome($organizationId, 'تعديل بيانات العميل'),
            [
                'customer'     => $customer,
                'governorates' => $this->governorates(),
                'service'      => $this->service,
            ],
        ), 'app');
    }

    public function update(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $customerId     = $this->routeId($request);

        try {
            $this->service->update($customerId, $organizationId, $request->all());
            $this->flash('success', 'حُفظت بيانات العميل.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/customers/' . $customerId);
    }

    public function archive(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $customerId     = $this->routeId($request);

        try {
            $this->service->archive($customerId, $organizationId);
            $this->flash('success', 'أُرشِف العميل.');

            return $this->redirect('/app/customers');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/app/customers/' . $customerId);
        }
    }

    // ═══════════════════ جهات الاتصال | Contacts ═══════════════════

    public function addContact(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $customerId     = $this->routeId($request);

        try {
            $this->service->addContact($customerId, $organizationId, $request->all());
            $this->flash('success', 'أُضيفت جهة الاتصال.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/customers/' . $customerId);
    }

    public function removeContact(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $customerId     = $this->routeId($request);
        $contactId      = (int) ($request->input('contact_id') ?? 0);

        try {
            $this->service->removeContact($contactId, $customerId, $organizationId);
            $this->flash('success', 'حُذفت جهة الاتصال.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/customers/' . $customerId);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function routeId(Request $request): int
    {
        $id = $request->routeInt('id');

        if ($id === null) {
            throw new HttpException(404, 'العميل غير موجود.');
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

    /** @return array<int,array<string,mixed>> */
    private function governorates(): array
    {
        return Database::select('SELECT id, name_ar FROM governorates ORDER BY name_ar ASC');
    }

    /** @return array<int,array<string,mixed>> */
    private function recentInvoices(int $customerId, int $organizationId): array
    {
        return Database::select(
            'SELECT id, invoice_number, issue_date, due_date, status, total, amount_paid,
                    (total - amount_paid) AS balance_due
               FROM erp_invoices
              WHERE customer_id = ? AND organization_id = ? AND deleted_at IS NULL
           ORDER BY issue_date DESC, id DESC
              LIMIT 10',
            [$customerId, $organizationId],
        );
    }
}
