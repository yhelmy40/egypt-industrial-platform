<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ApprovableCatalogRepository;
use App\Repositories\FinancingProductRepository;
use App\Repositories\ServiceOfferingRepository;
use App\Services\ApprovableCatalogService;
use App\Services\FinancingProductService;
use App\Services\ServiceOfferingService;
use App\Validation\Validator;

/**
 * اعتماد المنتجات والباقات | Approving products and service packages (§4.5, §4.6).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * هذا هو الطابور الذي يقف بين ما تكتبه الجهات وما يراه أصحاب المشروعات. كل
 * قرار هنا يُسجَّل باسم متّخذه وتاريخه، والرفض يستوجب سبباً يصل للجهة.
 *
 * الطابوران — التمويل والخدمات — يمرّان بنفس المسارات لأن القاعدة واحدة.
 * فصلهما إلى متحكّمين كان يعني نسخ منطق القرار مرتين، ثم تشديده في أحدهما.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class CatalogApprovalController extends Controller
{
    /** الحالات المسموح تصفّحها في الطابور | Browsable queue statuses. */
    private const STATUSES = ['pending_review', 'published', 'rejected', 'draft', 'archived'];

    public function __construct(
        private readonly FinancingProductRepository $products = new FinancingProductRepository(),
        private readonly ServiceOfferingRepository $offerings = new ServiceOfferingRepository(),
        private readonly FinancingProductService $productService = new FinancingProductService(),
        private readonly ServiceOfferingService $offeringService = new ServiceOfferingService(),
    ) {
    }

    // ═══════════════════ المنتجات التمويلية | Financing products ═══════════════════

    public function financingIndex(Request $request): Response
    {
        return $this->queue($request, 'financing');
    }

    public function financingShow(Request $request): Response
    {
        return $this->detail($request, 'financing');
    }

    public function financingDecide(Request $request): Response
    {
        return $this->decide($request, 'financing');
    }

    // ═══════════════════ باقات الخدمات | Service offerings ═══════════════════

    public function servicesIndex(Request $request): Response
    {
        return $this->queue($request, 'services');
    }

    public function servicesShow(Request $request): Response
    {
        return $this->detail($request, 'services');
    }

    public function servicesDecide(Request $request): Response
    {
        return $this->decide($request, 'services');
    }

    // ─────────────────── المشترك | Shared implementation ───────────────────

    private function queue(Request $request, string $kind): Response
    {
        $repository = $this->repository($kind);
        $status     = (string) ($request->input('status') ?? 'pending_review');

        if (!in_array($status, self::STATUSES, true)) {
            $status = 'pending_review';
        }

        return $this->view('admin/approvals/index', [
            'pageTitle' => $kind === 'financing' ? 'اعتماد المنتجات التمويلية' : 'اعتماد باقات الخدمات',
            'kind'      => $kind,
            'basePath'  => $this->basePath($kind),
            'results'   => $repository->moderationQueue($status, $this->page($request)),
            'counts'    => $repository->platformCountsByStatus(),
            'filters'   => ['status' => $status],
            'service'   => $this->service($kind),
        ], 'admin');
    }

    private function detail(Request $request, string $kind): Response
    {
        $id = $request->routeInt('id');

        if ($id === null) {
            throw new HttpException(404, 'العنصر المطلوب غير موجود.');
        }

        $item = $this->repository($kind)->findForModeration($id);

        if ($item === null) {
            throw new HttpException(404, 'العنصر المطلوب غير موجود.');
        }

        return $this->view('admin/approvals/show', [
            'pageTitle' => 'مراجعة: ' . $item['name_ar'],
            'kind'      => $kind,
            'basePath'  => $this->basePath($kind),
            'item'      => $item,
            'service'   => $this->service($kind),
            'typeLabel' => $kind === 'financing'
                ? $this->productService->typeLabel((string) $item['financing_type'])
                : $this->offeringService->typeLabel((string) $item['service_type']),
        ], 'admin');
    }

    private function decide(Request $request, string $kind): Response
    {
        $id       = $request->routeInt('id');
        $decision = (string) ($request->input('decision') ?? '');

        if ($id === null) {
            throw new HttpException(404, 'العنصر المطلوب غير موجود.');
        }

        $validator = Validator::make($request->all())
            ->labels(['note' => 'سبب القرار'])
            ->maxLength('note', 1000)
            ->requiredIf('note', $decision === 'reject');

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), $this->basePath($kind) . '/' . $id);
        }

        try {
            $this->service($kind)->moderate(
                id: $id,
                decision: $decision,
                note: $request->filled('note') ? (string) $request->input('note') : null,
                moderatorId: (int) $this->currentUserId(),
                request: $request,
            );

            $this->flash(
                'success',
                $decision === 'approve'
                    ? 'تم الاعتماد. أصبح العنصر ظاهراً للمشروعات.'
                    : 'تم الرفض وأُبلغت الجهة بالسبب.',
            );
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect($this->basePath($kind));
    }

    private function repository(string $kind): ApprovableCatalogRepository
    {
        return $kind === 'financing' ? $this->products : $this->offerings;
    }

    private function service(string $kind): ApprovableCatalogService
    {
        return $kind === 'financing' ? $this->productService : $this->offeringService;
    }

    private function basePath(string $kind): string
    {
        return $kind === 'financing' ? '/admin/finance/products' : '/admin/services/offerings';
    }
}
