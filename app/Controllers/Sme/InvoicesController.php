<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CustomerRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Services\InvoiceService;
use App\Services\ManagementReportService;

/**
 * فواتير البيع والمقبوضات | Sales invoices and receipts (§4.10, §14).
 *
 * **المنصة تسجّل ولا تحصّل.** لا تحصيل إلكتروني هنا: المقبوض تدوين لمبلغ
 * استلمه صاحب المشروع خارج المنصة.
 */
final class InvoicesController extends Controller
{
    public function __construct(
        private readonly InvoiceRepository $invoices = new InvoiceRepository(),
        private readonly InvoiceService $service = new InvoiceService(),
        private readonly CustomerRepository $customers = new CustomerRepository(),
        private readonly InventoryRepository $items = new InventoryRepository(),
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    public function index(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $filters = [
            'q'       => (string) ($request->input('q') ?? ''),
            'status'  => (string) ($request->input('status') ?? ''),
            'from'    => (string) ($request->input('from') ?? ''),
            'to'      => (string) ($request->input('to') ?? ''),
            'overdue' => $request->input('overdue') !== null,
        ];

        return $this->view('sme/invoices/index', array_merge(
            $this->chrome($organizationId, 'فواتير البيع'),
            [
                'results'   => $this->invoices->search($organizationId, $filters, $this->page($request)),
                'filters'   => $filters,
                'ageing'    => $this->invoices->ageing($organizationId),
                'customers' => $this->customers->selectable($organizationId),
                'service'   => $this->service,
            ],
        ), 'app');
    }

    public function create(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        return $this->view('sme/invoices/create', array_merge(
            $this->chrome($organizationId, 'فاتورة جديدة'),
            [
                'customers' => $this->customers->selectable($organizationId),
                'service'   => $this->service,
            ],
        ), 'app');
    }

    public function store(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        try {
            $result = $this->service->create($organizationId, $request->all(), $this->currentUserId());
            $this->flash('success', 'أُنشئت المسودة برقم ' . $result['number'] . '.');

            return $this->redirect('/app/invoices/' . $result['id']);
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->back($request, [], '/app/invoices/new');
        }
    }

    public function show(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $invoiceId      = $this->routeId($request);
        $invoice        = $this->invoices->findOwned($invoiceId, $organizationId);

        if ($invoice === null) {
            throw new HttpException(404, 'الفاتورة غير موجودة.');
        }

        return $this->view('sme/invoices/show', array_merge(
            $this->chrome($organizationId, 'فاتورة ' . $invoice['invoice_number']),
            [
                'invoice'    => $invoice,
                'lines'      => $this->invoices->lines($invoiceId),
                'payments'   => $this->invoices->payments($invoiceId),
                'items'      => $this->items->selectable($organizationId),
                'customers'  => $this->customers->selectable($organizationId),
                'editable'   => (string) $invoice['status'] === 'draft',
                'disclaimer' => ManagementReportService::DISCLAIMER_AR,
                'service'    => $this->service,
            ],
        ), 'app');
    }

    public function update(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $invoiceId      = $this->routeId($request);

        try {
            $this->service->updateDraft($invoiceId, $organizationId, $request->all());
            $this->flash('success', 'حُفظت بيانات المسودة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/invoices/' . $invoiceId);
    }

    // ═══════════════════ البنود | Lines ═══════════════════

    public function addLine(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $invoiceId      = $this->routeId($request);

        try {
            $this->service->addLine($invoiceId, $organizationId, $request->all());
            $this->flash('success', 'أُضيف البند.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/invoices/' . $invoiceId);
    }

    public function removeLine(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $invoiceId      = $this->routeId($request);

        try {
            $this->service->removeLine(
                $invoiceId,
                $organizationId,
                (int) ($request->input('line_id') ?? 0),
            );
            $this->flash('success', 'حُذف البند.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/invoices/' . $invoiceId);
    }

    public function applyDiscount(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $invoiceId      = $this->routeId($request);

        try {
            $this->service->applyDiscount(
                $invoiceId,
                $organizationId,
                (float) ($request->input('discount_amount') ?? 0),
            );
            $this->flash('success', 'طُبِّق الخصم.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/invoices/' . $invoiceId);
    }

    // ═══════════════════ الإصدار والإلغاء | Issuing and cancelling ═══════════════════

    public function issue(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $invoiceId      = $this->routeId($request);

        try {
            $this->service->issue($invoiceId, $organizationId, $this->currentUserId(), $request);
            $this->flash('success', 'صدرت الفاتورة. لا تُعدَّل بنودها بعد الآن.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/invoices/' . $invoiceId);
    }

    public function cancel(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $invoiceId      = $this->routeId($request);

        try {
            $this->service->cancel(
                $invoiceId,
                $organizationId,
                (string) ($request->input('cancelled_reason_ar') ?? ''),
                $this->currentUserId(),
                $request,
            );
            $this->flash('success', 'أُلغيت الفاتورة وأُعيد المخزون المصروف.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/invoices/' . $invoiceId);
    }

    // ═══════════════════ المقبوضات | Receipts ═══════════════════

    public function recordPayment(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $invoiceId      = $this->routeId($request);

        try {
            $result = $this->service->recordPayment(
                $invoiceId,
                $organizationId,
                $request->all(),
                $this->currentUserId(),
            );
            $this->flash('success', 'سُجّل المقبوض بإيصال رقم ' . $result['number'] . '.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/invoices/' . $invoiceId);
    }

    public function deletePayment(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $invoiceId      = $this->routeId($request);

        try {
            $this->service->deletePayment(
                (int) ($request->input('payment_id') ?? 0),
                $invoiceId,
                $organizationId,
            );
            $this->flash('success', 'حُذف الإيصال وأُعيد حساب المسدَّد.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/invoices/' . $invoiceId);
    }

    /** نسخة للطباعة | A printable copy of the invoice. */
    public function print(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $invoiceId      = $this->routeId($request);
        $invoice        = $this->invoices->findOwned($invoiceId, $organizationId);

        if ($invoice === null) {
            throw new HttpException(404, 'الفاتورة غير موجودة.');
        }

        // المسودة لا تُطبع: ورقة تحمل رقم فاتورة وتخرج للعميل قبل الإصدار
        // تصير مستنداً بلا سجلّ.
        if ((string) $invoice['status'] === 'draft') {
            $this->flash('warning', 'أصدر الفاتورة قبل طباعتها.');

            return $this->redirect('/app/invoices/' . $invoiceId);
        }

        return $this->view('sme/invoices/print', [
            'pageTitle'    => 'فاتورة ' . $invoice['invoice_number'],
            'invoice'      => $invoice,
            'lines'        => $this->invoices->lines($invoiceId),
            'payments'     => $this->invoices->payments($invoiceId),
            'organization' => $this->organizations->findWithDetails($organizationId),
            'service'      => $this->service,
        ], 'print');
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function routeId(Request $request): int
    {
        $id = $request->routeInt('id');

        if ($id === null) {
            throw new HttpException(404, 'الفاتورة غير موجودة.');
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
