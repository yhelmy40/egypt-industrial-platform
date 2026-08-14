<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\MembershipRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrganizationRepository;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Services\QuotationService;
use App\Validation\Validator;

/**
 * مبيعات المنشأة | Seller-side sales: orders, enquiries and quotations (§4.4).
 *
 * الثلاثة في متحكّم واحد لأنها تشترك في السياق (المنشأة النشطة) وفي التبعيات،
 * وفصلها كان سيكرّر نفس التمهيد ثلاث مرات دون فائدة.
 */
final class SalesController extends Controller
{
    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
        private readonly OrderService $orderService = new OrderService(),
        private readonly QuotationService $quotationService = new QuotationService(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    // ═══════════════════ الطلبات | Orders ═══════════════════

    public function orders(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $status         = (string) ($request->input('status') ?? '');

        return $this->view('sme/orders/index', [
            'pageTitle'     => 'الطلبات',
            'results'       => $this->orders->forOrganization(
                $organizationId,
                $status === '' ? null : $status,
                (string) ($request->input('q') ?? ''),
                $this->page($request),
            ),
            'counts'        => $this->orders->countsByStatus($organizationId),
            'summary'       => $this->orders->salesSummary($organizationId),
            'filters'       => ['status' => $status, 'q' => (string) ($request->input('q') ?? '')],
            'orderService'  => $this->orderService,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function showOrder(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $orderId        = $request->routeInt('id');

        if ($orderId === null) {
            throw new HttpException(404, 'الطلب المطلوب غير موجود.');
        }

        $order = $this->orders->findWithDetails($orderId, $organizationId);

        if ($order === null) {
            throw new HttpException(404, 'الطلب المطلوب غير موجود.');
        }

        return $this->view('sme/orders/show', [
            'pageTitle'        => 'الطلب ' . $order['order_number'],
            'order'            => $order,
            'availableActions' => $this->orderService->availableActions((string) $order['status']),
            'orderService'     => $this->orderService,
            'payments'         => Database::select(
                'SELECT * FROM order_payments WHERE order_id = ? ORDER BY id DESC',
                [$orderId],
            ),
            'organizations'    => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    /** تغيير حالة الطلب | Change the order status. */
    public function updateOrderStatus(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $orderId        = $request->routeInt('id');
        $action         = (string) ($request->input('action') ?? '');

        if ($orderId === null) {
            throw new HttpException(404, 'الطلب المطلوب غير موجود.');
        }

        try {
            $result = $this->orderService->transition(
                orderId: $orderId,
                organizationId: $organizationId,
                action: $action,
                actorUserId: $this->currentUserId(),
                actorType: 'seller',
                request: $request,
                note: $request->filled('note') ? (string) $request->input('note') : null,
            );

            $this->flash(
                'success',
                'تم تحديث حالة الطلب إلى: ' . $this->orderService->statusLabel($result['to']) . '.',
            );
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/orders/' . $orderId);
    }

    // ═══════════════════ الاستفسارات | Enquiries ═══════════════════

    public function enquiries(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $status         = (string) ($request->input('status') ?? '');

        $where    = ['e.organization_id = ?', 'e.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if ($status !== '') {
            $where[]    = 'e.status = ?';
            $bindings[] = $status;
        }

        $whereSql = implode(' AND ', $where);

        return $this->view('sme/enquiries/index', [
            'pageTitle'     => 'استفسارات العملاء',
            'enquiries'     => Database::select(
                "SELECT e.*, l.name_ar AS listing_name, l.slug AS listing_slug
                   FROM customer_enquiries e
                   LEFT JOIN listings l ON l.id = e.listing_id
                  WHERE {$whereSql}
                  ORDER BY FIELD(e.status,'new','read','replied','converted','closed','spam'), e.created_at DESC
                  LIMIT 100",
                $bindings,
            ),
            'counts'        => $this->enquiryCounts($organizationId),
            'filters'       => ['status' => $status],
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function showEnquiry(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $enquiryId      = $request->routeInt('id');

        if ($enquiryId === null) {
            throw new HttpException(404, 'الاستفسار المطلوب غير موجود.');
        }

        $enquiry = $this->findEnquiry($enquiryId, $organizationId);

        // فتح الاستفسار يعلّمه كمقروء | Opening marks it read
        if ($enquiry['status'] === 'new') {
            Database::statement(
                "UPDATE customer_enquiries SET status = 'read' WHERE id = ? AND organization_id = ?",
                [$enquiryId, $organizationId],
            );
            $enquiry['status'] = 'read';
        }

        return $this->view('sme/enquiries/show', [
            'pageTitle'     => 'استفسار من ' . $enquiry['customer_name'],
            'enquiry'       => $enquiry,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function replyToEnquiry(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $enquiryId      = $request->routeInt('id');

        if ($enquiryId === null) {
            throw new HttpException(404, 'الاستفسار المطلوب غير موجود.');
        }

        $enquiry = $this->findEnquiry($enquiryId, $organizationId);

        $validator = Validator::make($request->all())
            ->labels(['reply' => 'الرد'])
            ->required('reply')->minLength('reply', 5)->maxLength('reply', 2000);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/enquiries/' . $enquiryId);
        }

        Database::statement(
            "UPDATE customer_enquiries
                SET reply = ?, replied_by = ?, replied_at = NOW(), status = 'replied'
              WHERE id = ? AND organization_id = ?",
            [
                mb_substr((string) $request->input('reply'), 0, 2000),
                $this->currentUserId(),
                $enquiryId,
                $organizationId,
            ],
        );

        // العميل المسجَّل يُشعَر داخل المنصة؛ الزائر يتلقّى الرد على بريده لاحقاً
        if ($enquiry['customer_user_id'] !== null) {
            $this->notifications->notify(
                userId: (int) $enquiry['customer_user_id'],
                type: 'enquiry.replied',
                title: 'وصلك رد على استفسارك',
                body: mb_substr((string) $request->input('reply'), 0, 300),
                severity: 'info',
                organizationId: $organizationId,
                entityType: 'enquiry',
                entityId: $enquiryId,
            );
        }

        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'enquiry.replied',
            category: AuditLogger::CATEGORY_ORDER,
            entityType: 'enquiry',
            entityId: $enquiryId,
            description: 'الرد على استفسار عميل',
            userId: $this->currentUserId(),
            organizationId: $organizationId,
        );

        $this->flash('success', 'تم حفظ الرد.');

        return $this->redirect('/app/enquiries/' . $enquiryId);
    }

    // ═══════════════════ عروض الأسعار | Quotations ═══════════════════

    public function quotations(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $status         = (string) ($request->input('status') ?? '');

        $where    = ['q.organization_id = ?', 'q.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if ($status !== '') {
            $where[]    = 'q.status = ?';
            $bindings[] = $status;
        }

        $whereSql = implode(' AND ', $where);

        return $this->view('sme/quotations/index', [
            'pageTitle'        => 'عروض الأسعار',
            'quotations'       => Database::select(
                "SELECT q.*, l.name_ar AS listing_name
                   FROM quotations q
                   LEFT JOIN listings l ON l.id = q.listing_id
                  WHERE {$whereSql}
                  ORDER BY FIELD(q.status,'requested','quoted','accepted','rejected','expired','withdrawn'),
                           q.created_at DESC
                  LIMIT 100",
                $bindings,
            ),
            'filters'          => ['status' => $status],
            'quotationService' => $this->quotationService,
            'organizations'    => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function showQuotation(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $quotationId    = $request->routeInt('id');

        if ($quotationId === null) {
            throw new HttpException(404, 'طلب عرض السعر غير موجود.');
        }

        $quotation = $this->findQuotation($quotationId, $organizationId);

        return $this->view('sme/quotations/show', [
            'pageTitle'        => 'عرض سعر ' . $quotation['quotation_number'],
            'quotation'        => $quotation,
            'items'            => Database::select(
                'SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order ASC',
                [$quotationId],
            ),
            'quotationService' => $this->quotationService,
            'defaultVat'       => \App\Services\SettingsService::decimal('marketplace', 'default_vat_rate', 14.0),
            'organizations'    => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    /** تقديم عرض السعر | Submit the quote to the customer. */
    public function submitQuote(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $quotationId    = $request->routeInt('id');

        if ($quotationId === null) {
            throw new HttpException(404, 'طلب عرض السعر غير موجود.');
        }

        $descriptions = $request->array('item_description');
        $quantities   = $request->array('item_quantity');
        $units        = $request->array('item_unit');
        $prices       = $request->array('item_price');
        $vatRates     = $request->array('item_vat');

        $items = [];
        foreach ($descriptions as $index => $description) {
            $items[] = [
                'description'     => (string) $description,
                'quantity'        => $quantities[$index] ?? 1,
                'unit_of_measure' => $units[$index] ?? null,
                'unit_price'      => $prices[$index] ?? null,
                'vat_rate'        => $vatRates[$index] ?? 0,
            ];
        }

        try {
            $this->quotationService->submitQuote(
                quotationId: $quotationId,
                organizationId: $organizationId,
                items: $items,
                terms: [
                    'delivery_fee'   => $request->input('delivery_fee') ?? 0,
                    'lead_time_days' => $request->integer('lead_time_days'),
                    'terms'          => $request->input('terms'),
                    'valid_until'    => $request->filled('valid_until') ? (string) $request->input('valid_until') : null,
                ],
                actorId: $this->currentUserId(),
                request: $request,
            );

            $this->flash('success', 'تم إرسال عرض السعر إلى العميل.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/quotations/' . $quotationId);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function findEnquiry(int $enquiryId, int $organizationId): array
    {
        $enquiry = Database::selectOne(
            'SELECT e.*, l.name_ar AS listing_name, l.slug AS listing_slug
               FROM customer_enquiries e
               LEFT JOIN listings l ON l.id = e.listing_id
              WHERE e.id = ? AND e.organization_id = ? AND e.deleted_at IS NULL
              LIMIT 1',
            [$enquiryId, $organizationId],
        );

        if ($enquiry === null) {
            throw new HttpException(404, 'الاستفسار المطلوب غير موجود.');
        }

        return $enquiry;
    }

    private function findQuotation(int $quotationId, int $organizationId): array
    {
        $quotation = Database::selectOne(
            'SELECT q.*, l.name_ar AS listing_name, g.name_ar AS governorate_name
               FROM quotations q
               LEFT JOIN listings l ON l.id = q.listing_id
               LEFT JOIN governorates g ON g.id = q.governorate_id
              WHERE q.id = ? AND q.organization_id = ? AND q.deleted_at IS NULL
              LIMIT 1',
            [$quotationId, $organizationId],
        );

        if ($quotation === null) {
            throw new HttpException(404, 'طلب عرض السعر غير موجود.');
        }

        return $quotation;
    }

    /** @return array<string,int> */
    private function enquiryCounts(int $organizationId): array
    {
        $rows = Database::select(
            'SELECT status, COUNT(*) AS total FROM customer_enquiries
              WHERE organization_id = ? AND deleted_at IS NULL GROUP BY status',
            [$organizationId],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
