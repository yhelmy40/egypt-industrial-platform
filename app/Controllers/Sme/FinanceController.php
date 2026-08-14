<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\FinancingProductRepository;
use App\Repositories\MembershipRepository;
use App\Services\FileStorageService;
use App\Services\FinancingApplicationService;
use App\Services\FinancingProductService;
use App\Services\MatchingService;

/**
 * التمويل من جانب المشروع | The applicant side of financing (§4.5).
 *
 * صاحب المشروع يتصفّح الفرص المعتمدة، يرى لماذا رُشِّح له كل منتج، يقدّم طلباً،
 * ثم يتابع حالته ويرفع ما يُطلب منه من مستندات.
 *
 * ما لا يراه هنا أبداً: ملاحظات الفرز الداخلية للمنصة ولا مداولات المؤسسة
 * المالية. تلك مساحة عمل الطرف الآخر، وكشفها له ليس شفافية بل تسريب.
 */
final class FinanceController extends Controller
{
    public function __construct(
        private readonly FinancingProductRepository $products = new FinancingProductRepository(),
        private readonly FinancingApplicationService $applications = new FinancingApplicationService(),
        private readonly FinancingProductService $productService = new FinancingProductService(),
        private readonly MatchingService $matching = new MatchingService(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
        private readonly FileStorageService $storage = new FileStorageService(),
    ) {
    }

    // ═══════════════════ الفرص | Opportunities ═══════════════════

    public function opportunities(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $filters = [
            'q'              => (string) ($request->input('q') ?? ''),
            'financing_type' => (string) ($request->input('financing_type') ?? ''),
            'amount'         => (string) ($request->input('amount') ?? ''),
            'sort'           => (string) ($request->input('sort') ?? 'newest'),
        ];

        return $this->view('sme/finance/opportunities', [
            'pageTitle'     => 'فرص التمويل',
            'results'       => $this->products->searchPublic($filters, $this->page($request)),
            'filters'       => $filters,
            'types'         => FinancingProductService::TYPES,
            'suggestions'   => $this->matching->storedFor($organizationId, 'financing_product'),
            'service'       => $this->productService,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    /** نموذج التقديم | The application form for one product. */
    public function applyForm(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $productId      = $request->routeInt('id');

        if ($productId === null) {
            throw new HttpException(404, 'المنتج التمويلي غير موجود.');
        }

        $product = $this->products->findPublicById($productId);

        if ($product === null) {
            throw new HttpException(404, 'المنتج التمويلي غير متاح للتقديم عليه.');
        }

        return $this->view('sme/finance/apply', [
            'pageTitle'     => 'تقديم طلب تمويل',
            'product'       => $product,
            'service'       => $this->productService,
            'profile'       => $this->matching->organizationProfile($organizationId),
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function apply(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $productId      = $request->routeInt('id');

        if ($productId === null) {
            throw new HttpException(404, 'المنتج التمويلي غير موجود.');
        }

        try {
            $result = $this->applications->submit(
                organizationId: $organizationId,
                productId: $productId,
                data: $request->all(),
                actorId: $this->currentUserId(),
                request: $request,
            );

            $this->flash(
                'success',
                'تم تقديم طلبك برقم ' . $result['number'] . '. '
                . 'سيُفرز ويُحال إلى المؤسسة المالية، والقرار النهائي قرارها وحدها.',
            );

            return $this->redirect('/app/finance/applications/' . $result['id']);
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/app/finance/products/' . $productId . '/apply');
        }
    }

    // ═══════════════════ طلباتي | My applications ═══════════════════

    public function applications(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $status         = (string) ($request->input('status') ?? '');

        $where    = ['a.organization_id = ?', 'a.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if ($status !== '') {
            $where[]    = 'a.status = ?';
            $bindings[] = $status;
        }

        $whereSql = implode(' AND ', $where);

        return $this->view('sme/finance/applications', [
            'pageTitle'     => 'طلبات التمويل',
            'applications'  => Database::select(
                "SELECT a.*, o.legal_name AS provider_legal_name, o.trading_name AS provider_trading_name
                   FROM financing_applications a
                   JOIN organizations o ON o.id = a.provider_organization_id
                  WHERE {$whereSql}
                  ORDER BY a.created_at DESC
                  LIMIT 100",
                $bindings,
            ),
            'counts'        => $this->countsByStatus($organizationId, 'organization_id'),
            'filters'       => ['status' => $status],
            'service'       => $this->applications,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function showApplication(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $applicationId  = $request->routeInt('id');

        if ($applicationId === null) {
            throw new HttpException(404, 'طلب التمويل غير موجود.');
        }

        $application = $this->applications->findForApplicant($applicationId, $organizationId);

        return $this->view('sme/finance/application', [
            'pageTitle'     => 'طلب التمويل ' . $application['application_number'],
            'application'   => $application,
            // الملاحظات الداخلية محذوفة من نسخة المشروع
            'history'       => $this->applications->history($applicationId, false),
            'documents'     => $this->applications->documents($applicationId),
            'actions'       => $this->applications->actionsFor((string) $application['status'], 'applicant'),
            'service'       => $this->applications,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    /** إجراء من المشروع على طلبه | An applicant-side action. */
    public function applicationAction(Request $request): Response
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
                actorType: 'applicant',
                actorUserId: $this->currentUserId(),
                actorOrganizationId: $organizationId,
                request: $request,
                note: $request->filled('note') ? (string) $request->input('note') : null,
            );

            $this->flash('success', 'تم تحديث الطلب: ' . $this->applications->statusLabel($result['to']) . '.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/finance/applications/' . $applicationId);
    }

    /** رفع مستند طلبته المؤسسة | Upload a document the provider asked for. */
    public function uploadDocument(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $applicationId  = $request->routeInt('id');
        $documentId     = $request->integer('document_id');

        if ($applicationId === null || $documentId === null) {
            throw new HttpException(404, 'طلب المستند غير موجود.');
        }

        // التأكد من ملكية الطلب قبل أي رفع
        $this->applications->findForApplicant($applicationId, $organizationId);

        $file = $request->file('document');

        if ($file === null) {
            $this->flash('warning', 'اختر ملفاً للرفع.');

            return $this->redirect('/app/finance/applications/' . $applicationId);
        }

        try {
            $mediaId = $this->storage->store(
                file: $file,
                typeKey: 'document',
                collection: 'financing',
                organizationId: $organizationId,
                uploadedBy: $this->currentUserId(),
                // خاص دائماً: مستندات التمويل لا تُقدَّم إلا عبر متحكّم مفوِّض
                visibility: 'private',
            );

            $this->applications->attachDocument(
                $documentId,
                $organizationId,
                $mediaId,
                $this->currentUserId(),
            );

            $this->flash('success', 'تم رفع المستند.');
        } catch (ValidationException $e) {
            $this->flash('warning', implode(' ', array_map(
                static fn ($messages) => is_array($messages) ? implode(' ', $messages) : (string) $messages,
                $e->errors(),
            )));
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/finance/applications/' . $applicationId);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @return array<string,int> */
    private function countsByStatus(int $organizationId, string $column): array
    {
        $rows = Database::select(
            "SELECT status, COUNT(*) AS total FROM financing_applications
              WHERE {$column} = ? AND deleted_at IS NULL GROUP BY status",
            [$organizationId],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
