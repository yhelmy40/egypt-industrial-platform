<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Repositories\DocumentRepository;
use App\Repositories\OrganizationRepository;

/**
 * آلة حالة التوثيق | Organization verification state machine (§4.2).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * الانتقالات المسموحة — أي انتقال غير مذكور هنا مرفوض
 * Allowed transitions — anything not listed is refused
 *
 *   draft ──submit──────────────► submitted
 *   submitted ──start_review────► under_review
 *   submitted ──request_info────► more_info_required
 *   submitted ──approve─────────► verified
 *   submitted ──reject──────────► rejected
 *   under_review ──request_info─► more_info_required
 *   under_review ──approve──────► verified
 *   under_review ──reject───────► rejected
 *   more_info_required ──submit─► submitted        (المنشأة تستكمل وتُعيد الإرسال)
 *   rejected ──submit───────────► submitted        (حق التظلّم وإعادة التقديم)
 *   verified ──suspend──────────► suspended
 *   suspended ──reinstate───────► verified
 *
 * كل انتقال:
 *  - يتحقق من كون الفاعل مخوَّلاً بهذا الإجراء تحديداً (لا مجرد «مسجَّل دخول»)
 *  - يُنفَّذ داخل معاملة مع كتابة سجل القرار وسجل التدقيق والإشعار معاً
 *  - يُلزم بسبب مكتوب عند الرفض أو طلب الاستكمال أو الإيقاف
 *
 * Every transition verifies the actor is authorised for that specific action,
 * runs inside one transaction that also writes the decision record, the audit
 * entry and the notification, and requires a written reason for any negative
 * or restrictive outcome.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class VerificationService
{
    /** @var array<string,array<int,string>> الحالة الحالية => الإجراءات المتاحة */
    private const TRANSITIONS = [
        'draft'              => ['submit'],
        'submitted'          => ['start_review', 'request_info', 'approve', 'reject'],
        'under_review'       => ['request_info', 'approve', 'reject'],
        'more_info_required' => ['submit'],
        'rejected'           => ['submit'],
        'verified'           => ['suspend'],
        'suspended'          => ['reinstate'],
    ];

    /** @var array<string,string> الإجراء => الحالة الناتجة */
    private const RESULTING_STATUS = [
        'submit'       => 'submitted',
        'start_review' => 'under_review',
        'request_info' => 'more_info_required',
        'approve'      => 'verified',
        'reject'       => 'rejected',
        'suspend'      => 'suspended',
        'reinstate'    => 'verified',
    ];

    /** الإجراءات التي يقوم بها فريق المنصة لا صاحب المنشأة. */
    private const PLATFORM_ACTIONS = ['start_review', 'request_info', 'approve', 'reject', 'suspend', 'reinstate'];

    /** الإجراءات التي تستوجب سبباً مكتوباً يظهر لصاحب المنشأة. */
    private const REASON_REQUIRED = ['request_info', 'reject', 'suspend'];

    public function __construct(
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly DocumentRepository $documents = new DocumentRepository(),
        private readonly ProfileCompletionService $completion = new ProfileCompletionService(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /** هل الإجراء متاح من الحالة الحالية؟ | Is the action allowed from here? */
    public function can(string $currentStatus, string $action): bool
    {
        return in_array($action, self::TRANSITIONS[$currentStatus] ?? [], true);
    }

    /** @return array<int,string> */
    public function availableActions(string $currentStatus): array
    {
        return self::TRANSITIONS[$currentStatus] ?? [];
    }

    /**
     * تنفيذ انتقال | Perform a transition.
     *
     * @throws HttpException 422 عند انتقال غير مسموح أو سبب ناقص
     */
    public function transition(
        int $organizationId,
        string $action,
        ?int $actorUserId,
        ?string $actorRole,
        Request $request,
        ?string $reason = null,
        ?string $internalNote = null,
    ): array {
        $organization = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            throw new HttpException(404, 'المنشأة المطلوبة غير موجودة.');
        }

        $from = (string) $organization['status'];

        if (!$this->can($from, $action)) {
            throw new HttpException(
                422,
                'لا يمكن تنفيذ هذا الإجراء على منشأة حالتها: ' . $this->statusLabel($from) . '.',
            );
        }

        if (in_array($action, self::REASON_REQUIRED, true) && trim((string) $reason) === '') {
            throw new HttpException(
                422,
                'يجب توضيح السبب حتى تعرف المنشأة ما المطلوب منها.',
            );
        }

        // شرط الإرسال: اكتمال المستندات الإلزامية | Submission gate
        if ($action === 'submit') {
            $this->assertReadyForSubmission($organization);
        }

        $to = self::RESULTING_STATUS[$action];

        return Database::transaction(function () use (
            $organizationId, $organization, $from, $to, $action,
            $actorUserId, $actorRole, $reason, $internalNote, $request
        ): array {
            $score          = $this->completion->recalculate($organizationId);
            $documentCounts = $this->documents->countsByStatus($organizationId);

            $this->organizations->updateStatus($organizationId, $to, $actorUserId, $reason);

            Database::statement(
                'INSERT INTO organization_verifications
                    (organization_id, from_status, to_status, action, actor_user_id,
                     actor_role, reason, internal_note, completion_score,
                     documents_accepted, documents_pending)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $organizationId, $from, $to, $action, $actorUserId, $actorRole,
                    $reason === null ? null : mb_substr($reason, 0, 1000),
                    $internalNote === null ? null : mb_substr($internalNote, 0, 1000),
                    $score,
                    $documentCounts['accepted'],
                    $documentCounts['pending'],
                ],
            );

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'organization.' . $action,
                category: AuditLogger::CATEGORY_VERIFICATION,
                entityType: 'organization',
                entityId: $organizationId,
                changes: ['before' => ['status' => $from], 'after' => ['status' => $to]],
                description: $this->auditDescription($action, (string) $organization['legal_name']),
                severity: in_array($action, ['reject', 'suspend'], true) ? 'warning' : 'notice',
                userId: $actorUserId,
                organizationId: $organizationId,
            );

            $this->notifyOutcome($organization, $action, $to, $reason);

            return [
                'from'   => $from,
                'to'     => $to,
                'action' => $action,
                'score'  => $score,
            ];
        });
    }

    /**
     * شرط الإرسال للمراجعة | Submission readiness gate.
     *
     * تُمنع المنشأة من الإرسال قبل رفع المستندات الإلزامية، ليس تشدّداً بل
     * لأن الإرسال الناقص يُهدر دورة مراجعة كاملة ويؤخّر المنشأة نفسها.
     */
    private function assertReadyForSubmission(array $organization): void
    {
        $typeCode = (string) ($organization['type_code'] ?? 'sme');
        $status   = $this->documents->requiredDocumentStatus((int) $organization['id'], $typeCode);

        if ($status['missing'] !== []) {
            throw new HttpException(
                422,
                'لا يمكن إرسال الطلب قبل رفع المستندات الإلزامية التالية: '
                . implode('، ', $status['missing']) . '.',
            );
        }

        $evaluation = $this->completion->evaluate((int) $organization['id']);

        // حد أدنى معقول للاكتمال — يمنع الطلبات الفارغة دون أن يكون تعجيزياً
        if ($evaluation['score'] < 60) {
            throw new HttpException(
                422,
                'نسبة اكتمال الملف ' . $evaluation['score'] . '% وهي غير كافية للمراجعة. '
                . 'يرجى استكمال البيانات الأساسية أولاً (الحد الأدنى 60%).',
            );
        }
    }

    /** إشعار صاحب المنشأة بالنتيجة | Notify the organization of the outcome. */
    private function notifyOutcome(array $organization, string $action, string $to, ?string $reason): void
    {
        $organizationId = (int) $organization['id'];
        $name           = (string) ($organization['trading_name'] ?: $organization['legal_name']);

        [$title, $body, $severity] = match ($action) {
            'submit' => [
                'تم استلام طلب التوثيق',
                'استلمنا طلب توثيق «' . $name . '» وهو الآن في انتظار المراجعة.',
                'info',
            ],
            'start_review' => [
                'بدأت مراجعة ملف منشأتك',
                'فريق المنصة يراجع ملف «' . $name . '» حالياً. سنُعلمك فور صدور القرار.',
                'info',
            ],
            'request_info' => [
                'مطلوب استكمال بيانات',
                'يحتاج ملف «' . $name . '» إلى استكمال: ' . (string) $reason,
                'warning',
            ],
            'approve' => [
                'تم توثيق منشأتك',
                'تهانينا، تم توثيق «' . $name . '». يمكنك الآن نشر صفحتك التعريفية ومنتجاتك.',
                'success',
            ],
            'reject' => [
                'لم يتم اعتماد طلب التوثيق',
                'لم يُعتمد طلب توثيق «' . $name . '». السبب: ' . (string) $reason
                . ' — يمكنك تعديل البيانات وإعادة الإرسال.',
                'danger',
            ],
            'suspend' => [
                'تم إيقاف المنشأة',
                'تم إيقاف «' . $name . '». السبب: ' . (string) $reason,
                'danger',
            ],
            'reinstate' => [
                'تم إعادة تفعيل المنشأة',
                'تم إعادة تفعيل «' . $name . '» ويمكنك استئناف العمل.',
                'success',
            ],
            default => ['تحديث حالة المنشأة', 'تم تحديث حالة «' . $name . '».', 'info'],
        };

        $this->notifications->notifyOrganizationMembers(
            organizationId: $organizationId,
            type: 'organization.' . $action,
            title: $title,
            body: $body,
            severity: $severity,
            actionUrl: url('/app/organization'),
            actionLabel: 'عرض ملف المنشأة',
            entityType: 'organization',
            entityId: $organizationId,
        );
    }

    private function auditDescription(string $action, string $organizationName): string
    {
        return match ($action) {
            'submit'       => 'إرسال طلب توثيق المنشأة: ' . $organizationName,
            'start_review' => 'بدء مراجعة توثيق المنشأة: ' . $organizationName,
            'request_info' => 'طلب استكمال بيانات من المنشأة: ' . $organizationName,
            'approve'      => 'اعتماد توثيق المنشأة: ' . $organizationName,
            'reject'       => 'رفض طلب توثيق المنشأة: ' . $organizationName,
            'suspend'      => 'إيقاف المنشأة: ' . $organizationName,
            'reinstate'    => 'إعادة تفعيل المنشأة: ' . $organizationName,
            default        => 'تغيير حالة المنشأة: ' . $organizationName,
        };
    }

    /**
     * التحقق من أن الفاعل مخوَّل بالإجراء | Verify the actor may take this action.
     *
     * @throws AuthorizationException
     */
    public function assertActorMayPerform(string $action, bool $isPlatformStaff, bool $isOrganizationMember): void
    {
        if (in_array($action, self::PLATFORM_ACTIONS, true)) {
            if (!$isPlatformStaff) {
                throw new AuthorizationException(
                    'قرارات التوثيق يتخذها فريق المنصة فقط.',
                    'org.account.verify',
                    'organization',
                );
            }

            return;
        }

        if ($action === 'submit' && !$isOrganizationMember && !$isPlatformStaff) {
            throw new AuthorizationException(
                'يمكن لأعضاء المنشأة فقط إرسال طلب التوثيق.',
                'org.profile.update',
                'organization',
            );
        }
    }

    /** سجل قرارات منشأة | An organization's decision history. */
    public function history(int $organizationId): array
    {
        return Database::select(
            'SELECT v.*, u.name AS actor_name
               FROM organization_verifications v
               LEFT JOIN users u ON u.id = v.actor_user_id
              WHERE v.organization_id = ?
              ORDER BY v.created_at DESC, v.id DESC',
            [$organizationId],
        );
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'              => 'مسودة',
            'submitted'          => 'بانتظار المراجعة',
            'under_review'       => 'قيد المراجعة',
            'more_info_required' => 'مطلوب استكمال بيانات',
            'verified'           => 'موثّقة',
            'rejected'           => 'مرفوضة',
            'suspended'          => 'موقوفة',
            default              => $status,
        };
    }

    public function actionLabel(string $action): string
    {
        return match ($action) {
            'submit'       => 'إرسال للمراجعة',
            'start_review' => 'بدء المراجعة',
            'request_info' => 'طلب استكمال بيانات',
            'approve'      => 'اعتماد التوثيق',
            'reject'       => 'رفض الطلب',
            'suspend'      => 'إيقاف المنشأة',
            'reinstate'    => 'إعادة التفعيل',
            default        => $action,
        };
    }
}
