<?php

declare(strict_types=1);

namespace App\Controllers\Provider;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\FinancingProductRepository;
use App\Repositories\MembershipRepository;
use App\Services\FinancingApplicationService;
use App\Services\FinancingProductService;
use App\Validation\Validator;

/**
 * التمويل من جانب المؤسسة المالية | The provider side of financing (§4.5).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * المؤسسة تُدخل منتجاتها بنفسها وترسلها لاعتماد المنصة، وتدرس ما يُحال إليها
 * من طلبات وتسجّل قرارها.
 *
 * القرار قرارها وحدها: لا يوجد في هذا المتحكّم ولا في غيره مسار يجعل المنصة
 * تقبل طلباً نيابةً عنها. وبالمقابل لا تستطيع المؤسسة نشر منتج دون اعتماد.
 * كل طرف يملك قراره ولا يملك قرار الآخر.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class FinanceController extends Controller
{
    public function __construct(
        private readonly FinancingProductRepository $products = new FinancingProductRepository(),
        private readonly FinancingProductService $productService = new FinancingProductService(),
        private readonly FinancingApplicationService $applications = new FinancingApplicationService(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    // ═══════════════════ المنتجات | Products ═══════════════════

    public function products(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $status         = (string) ($request->input('status') ?? '');

        return $this->view('provider/finance/products', [
            'pageTitle'     => 'المنتجات التمويلية',
            'products'      => $this->products->forOrganization($organizationId, $status === '' ? null : $status),
            'counts'        => $this->products->countsByStatus($organizationId),
            'filters'       => ['status' => $status],
            'service'       => $this->productService,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function createForm(Request $request): Response
    {
        $this->requireOrganization();

        return $this->productForm(null);
    }

    public function editForm(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $productId      = $request->routeInt('id');

        if ($productId === null) {
            throw new HttpException(404, 'المنتج التمويلي غير موجود.');
        }

        $product = $this->products->findOwned($productId, $organizationId);

        if ($product === null) {
            throw new HttpException(404, 'المنتج التمويلي غير موجود.');
        }

        return $this->productForm($product);
    }

    public function store(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $validator = $this->productValidator($request);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/finance/products/new');
        }

        try {
            $id = $this->productService->create(
                $organizationId,
                $request->all(),
                $this->currentUserId(),
                $request,
            );

            $this->flash('success', 'تم حفظ المنتج كمسودة. أرسله للاعتماد ليظهر للمشروعات.');

            return $this->redirect('/app/finance/products/' . $id);
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/app/finance/products/new');
        }
    }

    public function update(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $productId      = $request->routeInt('id');

        if ($productId === null) {
            throw new HttpException(404, 'المنتج التمويلي غير موجود.');
        }

        $validator = $this->productValidator($request);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/finance/products/' . $productId);
        }

        try {
            $this->productService->update(
                $productId,
                $organizationId,
                $request->all(),
                $this->currentUserId(),
                $request,
            );

            $this->flash('success', 'تم حفظ التعديلات.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/finance/products/' . $productId);
    }

    public function submit(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $productId      = $request->routeInt('id');

        if ($productId === null) {
            throw new HttpException(404, 'المنتج التمويلي غير موجود.');
        }

        try {
            $this->productService->submitForApproval(
                $productId,
                $organizationId,
                $this->currentUserId(),
                $request,
            );

            $this->flash('success', 'أُرسل المنتج لاعتماد المنصة. سيظهر للمشروعات بعد الاعتماد.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/finance/products/' . $productId);
    }

    public function archive(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $productId      = $request->routeInt('id');

        if ($productId === null) {
            throw new HttpException(404, 'المنتج التمويلي غير موجود.');
        }

        $this->productService->archive($productId, $organizationId, $this->currentUserId(), $request);
        $this->flash('success', 'تم سحب المنتج. لم يعد ظاهراً للمشروعات.');

        return $this->redirect('/app/finance/products/' . $productId);
    }

    // ═══════════════════ الطلبات الواردة | Incoming applications ═══════════════════

    public function requests(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $status         = (string) ($request->input('status') ?? '');

        $where    = ['a.provider_organization_id = ?', 'a.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if ($status !== '') {
            $where[]    = 'a.status = ?';
            $bindings[] = $status;
        }

        $whereSql = implode(' AND ', $where);

        return $this->view('provider/finance/requests', [
            'pageTitle'     => 'طلبات التمويل الواردة',
            'applications'  => Database::select(
                "SELECT a.*, o.legal_name, o.trading_name, g.name_ar AS governorate_name
                   FROM financing_applications a
                   JOIN organizations o ON o.id = a.organization_id
                   LEFT JOIN governorates g ON g.id = o.governorate_id
                  WHERE {$whereSql}
                  ORDER BY FIELD(a.status,'forwarded','provider_review','info_requested',
                                 'approved','rejected','withdrawn','cancelled'),
                           a.created_at DESC
                  LIMIT 100",
                $bindings,
            ),
            'counts'        => $this->countsForProvider($organizationId),
            'filters'       => ['status' => $status],
            'service'       => $this->applications,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function showRequest(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $applicationId  = $request->routeInt('id');

        if ($applicationId === null) {
            throw new HttpException(404, 'طلب التمويل غير موجود.');
        }

        $application = $this->applications->findForProvider($applicationId, $organizationId);

        return $this->view('provider/finance/request', [
            'pageTitle'     => 'طلب التمويل ' . $application['application_number'],
            'application'   => $application,
            'history'       => $this->applications->history($applicationId, true),
            'documents'     => $this->applications->documents($applicationId),
            'actions'       => $this->applications->actionsFor((string) $application['status'], 'provider'),
            'service'       => $this->applications,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    /** إجراء المؤسسة على الطلب | A provider-side action, including the decision. */
    public function requestAction(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $applicationId  = $request->routeInt('id');
        $action         = (string) ($request->input('action') ?? '');

        if ($applicationId === null) {
            throw new HttpException(404, 'طلب التمويل غير موجود.');
        }

        try {
            $result = $this->applications->transition(
                applicationId: $applicationId,
                action: $action,
                actorType: 'provider',
                actorUserId: $this->currentUserId(),
                actorOrganizationId: $organizationId,
                request: $request,
                note: $request->filled('note') ? (string) $request->input('note') : null,
                internalNote: $request->filled('internal_note') ? (string) $request->input('internal_note') : null,
                decision: [
                    'approved_amount'       => $request->input('approved_amount'),
                    'approved_tenor_months' => $request->input('approved_tenor_months'),
                ],
            );

            $this->flash(
                'success',
                'تم تسجيل الإجراء. حالة الطلب الآن: ' . $this->applications->statusLabel($result['to']) . '.',
            );
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/finance/requests/' . $applicationId);
    }

    /** طلب مستند إضافي من المشروع | Ask the applicant for a document. */
    public function requestDocument(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $applicationId  = $request->routeInt('id');

        if ($applicationId === null) {
            throw new HttpException(404, 'طلب التمويل غير موجود.');
        }

        $validator = Validator::make($request->all())
            ->labels(['label_ar' => 'اسم المستند'])
            ->required('label_ar')->minLength('label_ar', 3)->maxLength('label_ar', 200)
            ->maxLength('note_ar', 500);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/finance/requests/' . $applicationId);
        }

        try {
            $this->applications->requestDocument(
                applicationId: $applicationId,
                providerOrganizationId: $organizationId,
                label: (string) $request->input('label_ar'),
                note: $request->filled('note_ar') ? (string) $request->input('note_ar') : null,
                required: (bool) $request->input('is_required'),
                actorId: $this->currentUserId(),
            );

            $this->flash('success', 'أُضيف المستند إلى قائمة المطلوب من المشروع.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/finance/requests/' . $applicationId);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @param array<string,mixed>|null $product */
    private function productForm(?array $product): Response
    {
        return $this->view('provider/finance/product-form', [
            'pageTitle'     => $product === null ? 'منتج تمويلي جديد' : 'تعديل: ' . $product['name_ar'],
            'product'       => $product,
            'types'         => FinancingProductService::TYPES,
            'service'       => $this->productService,
            'governorates'  => Database::select(
                'SELECT id, name_ar FROM governorates WHERE is_active = 1 ORDER BY sort_order ASC',
            ),
            'sectors'       => Database::select(
                'SELECT id, name_ar FROM sectors WHERE is_active = 1 ORDER BY sort_order ASC',
            ),
            'categories'    => Database::select(
                "SELECT id, name_ar FROM categories WHERE type = 'financial' AND is_active = 1
                  ORDER BY sort_order ASC",
            ),
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    private function productValidator(Request $request): Validator
    {
        return Validator::make($request->all())
            ->labels([
                'name_ar'                => 'اسم المنتج',
                'short_description'      => 'الوصف المختصر',
                'min_amount'             => 'أقل مبلغ',
                'max_amount'             => 'أعلى مبلغ',
                'rate_note_ar'           => 'بيان التكلفة',
                'eligibility_summary_ar' => 'شروط الأهلية',
            ])
            ->required('name_ar')->minLength('name_ar', 3)->maxLength('name_ar', 200)
            ->maxLength('short_description', 500)
            ->numeric('min_amount')->numeric('max_amount')
            ->integer('min_tenor_months')->integer('max_tenor_months')
            ->maxLength('rate_note_ar', 500)
            ->maxLength('fees_note_ar', 500)
            ->maxLength('eligibility_summary_ar', 2000)
            ->maxLength('required_documents_ar', 2000);
    }

    /** @return array<string,int> */
    private function countsForProvider(int $organizationId): array
    {
        $rows = Database::select(
            'SELECT status, COUNT(*) AS total FROM financing_applications
              WHERE provider_organization_id = ? AND deleted_at IS NULL GROUP BY status',
            [$organizationId],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
