<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\OrderRepository;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Services\QuotationService;
use App\Validation\Validator;

/**
 * واجهات العميل | Customer-facing flows (§4.4).
 *
 * تتبّع الطلبات، الاستفسارات، طلبات عروض الأسعار، والتقييمات — كلها تعمل للزائر
 * بلا حساب، لأن اشتراط التسجيل قبل أول تواصل يُفقد المشروعات عملاء حقيقيين.
 * Order tracking, enquiries, RFQs and reviews all work for guests: requiring an
 * account before first contact costs SMEs real customers.
 *
 * الوصول للزائر يتم برمز عشوائي 48 حرفاً لا بمعرّف رقمي — فلا يمكن تصفّح طلبات
 * الآخرين بتغيير رقم في الرابط.
 */
final class CustomerController extends Controller
{
    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly OrderService $orderService = new OrderService(),
        private readonly QuotationService $quotations = new QuotationService(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    // ═══════════════════ تتبّع الطلب | Order tracking ═══════════════════

    public function trackOrder(Request $request): Response
    {
        $token = (string) $request->route('token');
        $order = $this->orders->findByTrackingToken($token);

        if ($order === null) {
            throw new HttpException(404, 'الطلب المطلوب غير موجود.');
        }

        return $this->view('public/orders/track', [
            'pageTitle'    => 'تتبّع الطلب ' . $order['order_number'],
            'order'        => $order,
            'orderService' => $this->orderService,
            'canReview'    => $order['status'] === 'completed' && !$this->hasReview((int) $order['id']),
        ], 'public');
    }

    /** فتح نزاع من العميل | Customer opens a dispute. */
    public function disputeOrder(Request $request): Response
    {
        $token = (string) $request->route('token');
        $order = $this->orders->findByTrackingToken($token);

        if ($order === null) {
            throw new HttpException(404, 'الطلب المطلوب غير موجود.');
        }

        $reason = trim((string) ($request->input('reason') ?? ''));

        if (mb_strlen($reason) < 10) {
            $this->flash('warning', 'يرجى توضيح سبب النزاع في عشرة أحرف على الأقل.');

            return $this->redirect('/orders/track/' . $token);
        }

        try {
            $this->orderService->transition(
                orderId: (int) $order['id'],
                organizationId: (int) $order['organization_id'],
                action: 'dispute',
                actorUserId: $this->currentUserId(),
                actorType: 'customer',
                request: $request,
                note: $reason,
            );

            $this->flash('success', 'تم تسجيل النزاع وسيتواصل معك البائع أو فريق المنصة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/orders/track/' . $token);
    }

    // ═══════════════════ الاستفسارات | Enquiries ═══════════════════

    public function submitEnquiry(Request $request): Response
    {
        $organizationId = $request->integer('organization_id');
        $listingId      = $request->integer('listing_id');

        if ($organizationId === null) {
            throw new HttpException(422, 'لم تُحدَّد المنشأة.');
        }

        $organization = $this->verifiedOrganization($organizationId);

        $validator = Validator::make($request->all())
            ->labels([
                'customer_name'  => 'الاسم',
                'customer_phone' => 'رقم الهاتف',
                'customer_email' => 'البريد الإلكتروني',
                'message'        => 'الرسالة',
            ])
            ->required('customer_name')->minLength('customer_name', 3)->maxLength('customer_name', 150)
            ->required('customer_phone')->phone('customer_phone')
            ->email('customer_email')
            ->required('message')->minLength('message', 10)->maxLength('message', 2000)
            ->maxLength('subject', 200);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/business/' . $organization['slug']);
        }

        $packedIp = @inet_pton($request->ip());

        $enquiryId = Database::insert(
            'INSERT INTO customer_enquiries
                (organization_id, listing_id, customer_user_id, customer_name,
                 customer_phone, customer_email, subject, message, status, source_ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $organizationId,
                $listingId,
                $this->currentUserId(),
                mb_substr((string) $request->input('customer_name'), 0, 150),
                mb_substr((string) $request->input('customer_phone'), 0, 30),
                $request->filled('customer_email') ? (string) $request->input('customer_email') : null,
                $request->filled('subject') ? mb_substr((string) $request->input('subject'), 0, 200) : null,
                mb_substr((string) $request->input('message'), 0, 2000),
                'new',
                $packedIp === false ? null : $packedIp,
            ],
        );

        $this->notifications->notifyOrganizationMembers(
            organizationId: $organizationId,
            type: 'enquiry.received',
            title: 'استفسار جديد من عميل',
            body: 'وصل استفسار من ' . $request->input('customer_name') . '.',
            severity: 'info',
            actionUrl: url('/app/enquiries/' . $enquiryId),
            actionLabel: 'عرض الاستفسار',
            entityType: 'enquiry',
            entityId: $enquiryId,
        );

        $this->flash('success', 'تم إرسال استفسارك. ستتلقّى رداً من المنشأة قريباً.');

        return $this->redirect('/business/' . $organization['slug']);
    }

    // ═══════════════════ عروض الأسعار | Quotations ═══════════════════

    public function requestQuotation(Request $request): Response
    {
        $organizationId = $request->integer('organization_id');
        $listingId      = $request->integer('listing_id');

        if ($organizationId === null) {
            throw new HttpException(422, 'لم تُحدَّد المنشأة.');
        }

        $organization = $this->verifiedOrganization($organizationId);

        $validator = Validator::make($request->all())
            ->labels([
                'customer_name'    => 'الاسم',
                'customer_phone'   => 'رقم الهاتف',
                'customer_email'   => 'البريد الإلكتروني',
                'request_details'  => 'تفاصيل الطلب',
                'needed_by'        => 'تاريخ الاحتياج',
            ])
            ->required('customer_name')->minLength('customer_name', 3)->maxLength('customer_name', 150)
            ->required('customer_phone')->phone('customer_phone')
            ->email('customer_email')
            ->required('request_details')->minLength('request_details', 15)->maxLength('request_details', 2000)
            ->numeric('requested_quantity')
            ->date('needed_by');

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/business/' . $organization['slug']);
        }

        $result = $this->quotations->request(
            organizationId: $organizationId,
            listingId: $listingId,
            data: [
                'customer_user_id'   => $this->currentUserId(),
                'customer_name'      => (string) $request->input('customer_name'),
                'customer_phone'     => (string) $request->input('customer_phone'),
                'customer_email'     => $request->filled('customer_email') ? (string) $request->input('customer_email') : null,
                'governorate_id'     => $request->integer('governorate_id'),
                'request_details'    => (string) $request->input('request_details'),
                'requested_quantity' => $request->filled('requested_quantity') ? (float) $request->input('requested_quantity') : null,
                'needed_by'          => $request->filled('needed_by') ? (string) $request->input('needed_by') : null,
            ],
            request: $request,
        );

        return $this->view('public/quotations/submitted', [
            'pageTitle'  => 'تم إرسال طلب عرض السعر',
            'quotation'  => $result,
            'trackUrl'   => url('/quotations/track/' . $result['token']),
        ], 'public');
    }

    public function trackQuotation(Request $request): Response
    {
        $token     = (string) $request->route('token');
        $quotation = $this->findQuotationByToken($token);

        if ($quotation === null) {
            throw new HttpException(404, 'طلب عرض السعر غير موجود.');
        }

        return $this->view('public/quotations/track', [
            'pageTitle'         => 'عرض السعر ' . $quotation['quotation_number'],
            'quotation'         => $quotation,
            'items'             => Database::select(
                'SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order ASC',
                [(int) $quotation['id']],
            ),
            'quotationService'  => $this->quotations,
        ], 'public');
    }

    public function respondToQuotation(Request $request): Response
    {
        $token    = (string) $request->route('token');
        $decision = (string) ($request->input('decision') ?? '');

        try {
            $orderId = $this->quotations->respond($token, $decision, $this->currentUserId(), $request);
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/quotations/track/' . $token);
        }

        if ($orderId !== null) {
            $orderToken = Database::scalar('SELECT tracking_token FROM orders WHERE id = ?', [$orderId]);

            $this->flash('success', 'تم قبول العرض وتحويله إلى طلب مؤكَّد.');

            return $this->redirect('/orders/track/' . (string) $orderToken);
        }

        $this->flash('info', 'تم تسجيل اعتذارك عن العرض.');

        return $this->redirect('/quotations/track/' . $token);
    }

    // ═══════════════════ التقييم | Reviews ═══════════════════

    /**
     * تقييم بعد إتمام الطلب | Review after a completed order (§4.4).
     *
     * لا تقييم بلا معاملة مكتملة: الرابط يحمل رمز تتبّع الطلب، ويُتحقق من أن
     * الطلب مكتمل فعلاً ولم يُقيَّم من قبل.
     * No review without a completed transaction: the token proves the reviewer
     * is the buyer, and the order must be completed and not yet reviewed.
     */
    public function submitReview(Request $request): Response
    {
        $token = (string) $request->route('token');
        $order = $this->orders->findByTrackingToken($token);

        if ($order === null) {
            throw new HttpException(404, 'الطلب المطلوب غير موجود.');
        }

        if ($order['status'] !== 'completed') {
            $this->flash('warning', 'يمكن التقييم بعد إتمام الطلب فقط.');

            return $this->redirect('/orders/track/' . $token);
        }

        if ($this->hasReview((int) $order['id'])) {
            $this->flash('info', 'سبق تقييم هذا الطلب.');

            return $this->redirect('/orders/track/' . $token);
        }

        $rating = $request->integer('rating');

        if ($rating === null || $rating < 1 || $rating > 5) {
            $this->flash('warning', 'يرجى اختيار تقييم من 1 إلى 5.');

            return $this->redirect('/orders/track/' . $token);
        }

        $comment = $request->filled('comment')
            ? mb_substr((string) $request->input('comment'), 0, 1500)
            : null;

        // الصنف المُقيَّم: أول صنف في الطلب — التقييم على مستوى الطلب والمنشأة
        $listingId = Database::scalar(
            'SELECT listing_id FROM order_items WHERE order_id = ? AND listing_id IS NOT NULL LIMIT 1',
            [(int) $order['id']],
        );

        Database::transaction(function () use ($order, $rating, $comment, $listingId, $request): void {
            Database::statement(
                'INSERT INTO reviews
                    (organization_id, listing_id, order_id, customer_user_id,
                     customer_name, rating, comment, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $order['organization_id'],
                    $listingId === null ? null : (int) $listingId,
                    (int) $order['id'],
                    $this->currentUserId(),
                    mb_substr((string) $order['customer_name'], 0, 150),
                    $rating,
                    $comment,
                    'published',
                ],
            );

            if ($listingId !== null) {
                (new \App\Repositories\ListingRepository())->refreshRating((int) $listingId);
            }

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'review.created',
                category: AuditLogger::CATEGORY_ORDER,
                entityType: 'order',
                entityId: (int) $order['id'],
                description: 'تقييم بعد إتمام الطلب: ' . $rating . '/5',
                userId: $this->currentUserId(),
                organizationId: (int) $order['organization_id'],
            );
        });

        $this->notifications->notifyOrganizationMembers(
            organizationId: (int) $order['organization_id'],
            type: 'review.received',
            title: 'تقييم جديد',
            body: 'حصلت على تقييم ' . $rating . '/5 على الطلب ' . $order['order_number'] . '.',
            severity: $rating >= 4 ? 'success' : 'warning',
            actionUrl: url('/app/orders/' . $order['id']),
            entityType: 'order',
            entityId: (int) $order['id'],
        );

        $this->flash('success', 'شكراً لتقييمك.');

        return $this->redirect('/orders/track/' . $token);
    }

    // ═══════════════════ طلباتي | My orders (registered customers) ═══════════════════

    public function myOrders(Request $request): Response
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->redirect('/auth/login');
        }

        return $this->view('public/orders/mine', [
            'pageTitle'    => 'طلباتي',
            'orders'       => $this->orders->forCustomer($userId),
            'orderService' => $this->orderService,
        ], 'public');
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function verifiedOrganization(int $organizationId): array
    {
        $organization = Database::selectOne(
            "SELECT * FROM organizations
              WHERE id = ? AND status = 'verified' AND deleted_at IS NULL LIMIT 1",
            [$organizationId],
        );

        if ($organization === null) {
            throw new HttpException(404, 'المنشأة المطلوبة غير متاحة.');
        }

        return $organization;
    }

    private function hasReview(int $orderId): bool
    {
        return Database::scalar(
            'SELECT 1 FROM reviews WHERE order_id = ? AND deleted_at IS NULL LIMIT 1',
            [$orderId],
        ) !== null;
    }

    private function findQuotationByToken(string $token): ?array
    {
        if (strlen($token) !== 48) {
            return null;
        }

        return Database::selectOne(
            'SELECT q.*, o.legal_name, o.trading_name, o.slug AS seller_slug
               FROM quotations q
               JOIN organizations o ON o.id = q.organization_id
              WHERE q.tracking_token = ? AND q.deleted_at IS NULL
              LIMIT 1',
            [$token],
        );
    }
}
