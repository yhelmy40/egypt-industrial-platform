<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Policies\OrganizationPolicy;
use App\Repositories\DocumentRepository;
use App\Repositories\OrganizationProfileRepository;
use App\Repositories\OrganizationRepository;
use App\Services\AuditLogger;
use App\Services\ProfileCompletionService;
use App\Services\VerificationService;
use App\Support\TenantContext;
use App\Validation\Validator;

/**
 * مراجعة طلبات التوثيق | Verification review queue (§3.1, §3.2, §4.2).
 *
 * قرارات التوثيق هي أكثر ما يمسّ ثقة المنشآت بالمنصة، لذا كل قرار هنا:
 *  - يمرّ بسياسة صريحة تتحقق من صلاحية التوثيق تحديداً
 *  - يُلزم بسبب مكتوب عند الرفض أو طلب الاستكمال
 *  - يُسجَّل في سجل القرارات وسجل التدقيق ويُشعِر المنشأة
 * Every decision passes an explicit verify-permission check, requires a written
 * reason for any negative outcome, and is recorded and notified.
 */
final class VerificationController extends Controller
{
    public function __construct(
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly OrganizationProfileRepository $profiles = new OrganizationProfileRepository(),
        private readonly DocumentRepository $documents = new DocumentRepository(),
        private readonly VerificationService $verification = new VerificationService(),
        private readonly ProfileCompletionService $completion = new ProfileCompletionService(),
        private readonly OrganizationPolicy $policy = new OrganizationPolicy(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /** طابور المراجعة | The review queue. */
    public function index(Request $request): Response
    {
        $status = (string) ($request->input('status') ?? 'submitted');
        $type   = (string) ($request->input('type') ?? '');
        $term   = (string) ($request->input('q') ?? '');

        $allowedStatuses = [
            'submitted', 'under_review', 'more_info_required',
            'verified', 'rejected', 'suspended', 'draft', '',
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'submitted';
        }

        $results = $this->organizations->adminList(
            status: $status === '' ? null : $status,
            typeCode: $type === '' ? null : $type,
            governorateId: $request->integer('governorate_id'),
            term: $term,
            page: $this->page($request),
        );

        return $this->view('admin/verifications/index', [
            'pageTitle'    => 'طلبات التوثيق',
            'results'      => $results,
            'filters'      => ['status' => $status, 'type' => $type, 'q' => $term],
            'statusCounts' => $this->organizations->countsByStatus(),
            'types'        => Database::select('SELECT code, name_ar FROM organization_types WHERE is_active = 1 ORDER BY sort_order'),
            'governorates' => Database::select('SELECT id, name_ar FROM governorates WHERE is_active = 1 ORDER BY sort_order'),
        ], 'admin');
    }

    /** شاشة مراجعة منشأة واحدة | Single-organization review screen. */
    public function show(Request $request): Response
    {
        $organizationId = $request->routeInt('id');

        if ($organizationId === null) {
            throw new HttpException(404, 'المنشأة المطلوبة غير موجودة.');
        }

        $organization = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            throw new HttpException(404, 'المنشأة المطلوبة غير موجودة.');
        }

        $this->policy->authorizeView($organization);

        $typeCode = (string) $organization['type_code'];

        return $this->view('admin/verifications/show', [
            'pageTitle'        => 'مراجعة: ' . $organization['legal_name'],
            'organization'     => $organization,
            'profile'          => $this->profiles->findForOrganization($organizationId, $typeCode) ?? [],
            'checklist'        => $this->documents->checklistFor($organizationId, $typeCode),
            'documents'        => $this->documents->forOrganization($organizationId),
            'completion'       => $this->completion->evaluate($organizationId),
            'history'          => $this->verification->history($organizationId),
            'availableActions' => $this->verification->availableActions((string) $organization['status']),
            'members'          => Database::select(
                "SELECT u.name, u.email, u.phone, r.name_ar AS role_name, m.is_primary_contact
                   FROM organization_members m
                   JOIN users u ON u.id = m.user_id
                   JOIN roles r ON r.id = m.role_id
                  WHERE m.organization_id = ? AND m.status = 'active'
                  ORDER BY m.is_primary_contact DESC",
                [$organizationId],
            ),
            'verificationService' => $this->verification,
        ], 'admin');
    }

    /** تنفيذ قرار توثيق | Execute a verification decision. */
    public function decide(Request $request): Response
    {
        $this->policy->authorizeDecideVerification();

        $organizationId = $request->routeInt('id');
        $action         = (string) ($request->input('action') ?? '');

        if ($organizationId === null) {
            throw new HttpException(404, 'المنشأة المطلوبة غير موجودة.');
        }

        $allowedActions = ['start_review', 'request_info', 'approve', 'reject', 'suspend', 'reinstate'];

        if (!in_array($action, $allowedActions, true)) {
            throw new HttpException(422, 'الإجراء المطلوب غير معروف.');
        }

        $validator = Validator::make($request->all())
            ->labels(['reason' => 'السبب', 'internal_note' => 'ملاحظة داخلية'])
            ->maxLength('reason', 1000)
            ->maxLength('internal_note', 1000)
            // السبب إلزامي في القرارات السلبية حتى تعرف المنشأة ما المطلوب
            ->requiredIf('reason', in_array($action, ['request_info', 'reject', 'suspend'], true));

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/admin/verifications/' . $organizationId);
        }

        $this->verification->assertActorMayPerform(
            $action,
            TenantContext::isPlatformStaff() || TenantContext::can('org.account.verify'),
            false,
        );

        try {
            $result = $this->verification->transition(
                organizationId: $organizationId,
                action: $action,
                actorUserId: $this->currentUserId(),
                actorRole: implode('،', TenantContext::platformRoles()),
                request: $request,
                reason: $request->filled('reason') ? (string) $request->input('reason') : null,
                internalNote: $request->filled('internal_note') ? (string) $request->input('internal_note') : null,
            );
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/admin/verifications/' . $organizationId);
        }

        $this->flash('success', 'تم تنفيذ الإجراء: ' . $this->verification->actionLabel($action)
            . ' — الحالة الآن: ' . $this->verification->statusLabel($result['to']) . '.');

        return $this->redirect('/admin/verifications/' . $organizationId);
    }

    /** قرار على مستند بعينه | Accept or reject a single document. */
    public function reviewDocument(Request $request): Response
    {
        $this->policy->authorizeDecideVerification();

        $organizationId = $request->routeInt('id');
        $documentId     = $request->routeInt('documentId');
        $status         = (string) ($request->input('status') ?? '');

        if ($organizationId === null || $documentId === null) {
            throw new HttpException(404, 'المستند المطلوب غير موجود.');
        }

        if (!in_array($status, ['accepted', 'rejected'], true)) {
            throw new HttpException(422, 'حالة المستند غير صالحة.');
        }

        if ($status === 'rejected' && !$request->filled('review_note')) {
            $this->flash('warning', 'يجب توضيح سبب رفض المستند حتى تتمكن المنشأة من تصحيحه.');

            return $this->redirect('/admin/verifications/' . $organizationId);
        }

        $affected = $this->documents->review(
            documentId: $documentId,
            organizationId: $organizationId,
            status: $status,
            note: $request->filled('review_note') ? (string) $request->input('review_note') : null,
            reviewerId: (int) $this->currentUserId(),
        );

        if ($affected === 0) {
            throw new HttpException(404, 'المستند المطلوب غير موجود.');
        }

        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'document.reviewed',
            category: AuditLogger::CATEGORY_VERIFICATION,
            entityType: 'organization_document',
            entityId: $documentId,
            changes: ['after' => ['status' => $status]],
            description: $status === 'accepted' ? 'اعتماد مستند' : 'رفض مستند',
            severity: $status === 'rejected' ? 'warning' : 'notice',
            userId: $this->currentUserId(),
            organizationId: $organizationId,
        );

        $this->completion->recalculate($organizationId);
        $this->flash('success', $status === 'accepted' ? 'تم اعتماد المستند.' : 'تم رفض المستند.');

        return $this->redirect('/admin/verifications/' . $organizationId);
    }
}
