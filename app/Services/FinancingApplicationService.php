<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Core\Request;

/**
 * طلبات التمويل | Financing applications (§4.5).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * القاعدة الحاكمة: **لا يُعرض طلب كمقبول إلا إذا سجّل المزوّد المسؤول القبول.**
 *
 * تُطبَّق بثلاث طبقات متراكبة، لأن طبقة واحدة تُنسى:
 *  1. `PROVIDER_ONLY` — القبول والرفض إجراءان لا ينفّذهما إلا فاعل من المزوّد.
 *  2. التحقق من أن منشأة الفاعل هي `provider_organization_id` نفسها.
 *  3. جملة التحديث تكتب `decided_by` في نفس العبارة التي تكتب `approved`،
 *     فلا يوجد مسار كتابة يُنتج حالة مقبولة بلا فاعل مسجَّل.
 *
 * المنصة تفرز وتحيل فقط. لا تملك إجراء `approve` إطلاقاً، ومحاولتها ترفع
 * استثناء تفويض لا رسالة تحقّق — الفرق مقصود: هذه ليست بيانات ناقصة بل
 * تجاوز صلاحية يُسجَّل.
 *
 * The platform screens and routes; it has no approve action at all. Attempting
 * one raises an authorization exception, not a validation message: this is not
 * missing data, it is an overreach worth recording.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class FinancingApplicationService
{
    /** @var array<string,array<int,string>> */
    private const TRANSITIONS = [
        'draft'           => ['submit', 'cancel'],
        'submitted'       => ['start_screening', 'forward', 'withdraw', 'cancel'],
        'screening'       => ['forward', 'withdraw', 'cancel'],
        'forwarded'       => ['start_review', 'withdraw'],
        'provider_review' => ['request_info', 'approve', 'reject', 'withdraw'],
        'info_requested'  => ['resubmit', 'withdraw'],
        'approved'        => [],
        'rejected'        => [],
        'withdrawn'       => [],
        'cancelled'       => [],
    ];

    /** @var array<string,string> */
    private const RESULTING_STATUS = [
        'submit'          => 'submitted',
        'start_screening' => 'screening',
        'forward'         => 'forwarded',
        'start_review'    => 'provider_review',
        'request_info'    => 'info_requested',
        'resubmit'        => 'provider_review',
        'approve'         => 'approved',
        'reject'          => 'rejected',
        'withdraw'        => 'withdrawn',
        'cancel'          => 'cancelled',
    ];

    /** إجراءات المزوّد وحده — لا المنصة ولا المشروع | Provider-only actions. */
    private const PROVIDER_ONLY = ['start_review', 'request_info', 'approve', 'reject'];

    /** إجراءات المشروع صاحب الطلب | Applicant-only actions. */
    private const APPLICANT_ONLY = ['submit', 'resubmit', 'withdraw'];

    /** إجراءات فريق المنصة | Platform-only actions. */
    private const PLATFORM_ONLY = ['start_screening', 'forward'];

    /** إجراءات تستوجب سبباً مكتوباً | Actions requiring a written reason. */
    private const REASON_REQUIRED = ['reject', 'request_info', 'cancel'];

    public function __construct(
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

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
     * الإجراءات المتاحة لجهة بعينها | Actions available to one side.
     *
     * @return array<int,string>
     */
    public function actionsFor(string $currentStatus, string $actorType): array
    {
        return array_values(array_filter(
            $this->availableActions($currentStatus),
            fn (string $action): bool => $this->actorMayPerform($action, $actorType),
        ));
    }

    public function requiresReason(string $action): bool
    {
        return in_array($action, self::REASON_REQUIRED, true);
    }

    // ═══════════════════ التقديم | Submission ═══════════════════

    /**
     * إنشاء طلب تمويل وتقديمه | Create and submit an application.
     *
     * المنتج يُعاد التحقق منه لحظة التقديم لا لحظة عرض النموذج: قد يكون سُحب
     * أو رُفض اعتماده بينهما، وقبول طلب على منتج غير معتمد يعد صاحب المشروع
     * بشيء لا وجود له.
     *
     * @param  array<string,mixed> $data
     * @return array{id:int,number:string,token:string}
     */
    public function submit(int $organizationId, int $productId, array $data, ?int $actorId, Request $request): array
    {
        $product = Database::selectOne(
            "SELECT p.*, o.status AS provider_status
               FROM financing_products p
               JOIN organizations o ON o.id = p.organization_id
              WHERE p.id = ? AND p.status = 'published' AND p.deleted_at IS NULL
                AND o.status = 'verified' AND o.deleted_at IS NULL
              LIMIT 1",
            [$productId],
        );

        if ($product === null) {
            throw new HttpException(404, 'المنتج التمويلي غير متاح للتقديم عليه.');
        }

        $applicant = Database::selectOne(
            'SELECT * FROM organizations WHERE id = ? AND deleted_at IS NULL',
            [$organizationId],
        );

        if ($applicant === null) {
            throw new HttpException(404, 'المنشأة غير موجودة.');
        }

        if ($applicant['status'] !== 'verified') {
            throw new HttpException(
                422,
                'لا يمكن تقديم طلب تمويل قبل توثيق المنشأة. أكمل ملف التوثيق وأرسله للمراجعة.',
            );
        }

        $amount = $data['requested_amount'] ?? null;

        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw new HttpException(422, 'أدخل مبلغ التمويل المطلوب.');
        }

        $this->assertAmountWithinBand($product, (float) $amount);

        $purpose = trim((string) ($data['purpose_ar'] ?? ''));

        if (mb_strlen($purpose) < 20) {
            throw new HttpException(422, 'وضّح الغرض من التمويل في عشرين حرفاً على الأقل.');
        }

        return Database::transaction(function () use (
            $organizationId, $product, $productId, $data, $amount, $purpose, $actorId, $request
        ): array {
            $number = 'FIN-' . date('ym') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $token  = bin2hex(random_bytes(24));

            $id = Database::insert(
                'INSERT INTO financing_applications
                    (application_number, organization_id, provider_organization_id,
                     financing_product_id, product_name_ar, requested_amount, currency_code,
                     requested_tenor_months, purpose_ar, declared_annual_revenue,
                     declared_employees, years_in_business, status, submitted_at, tracking_token)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
                [
                    $number,
                    $organizationId,
                    (int) $product['organization_id'],
                    $productId,
                    (string) $product['name_ar'],
                    number_format((float) $amount, 2, '.', ''),
                    (string) $product['currency_code'],
                    $this->nullableInt($data['requested_tenor_months'] ?? null),
                    mb_substr($purpose, 0, 2000),
                    $this->nullableDecimal($data['declared_annual_revenue'] ?? null),
                    $this->nullableInt($data['declared_employees'] ?? null),
                    $this->nullableInt($data['years_in_business'] ?? null),
                    'submitted',
                    $token,
                ],
            );

            $this->recordHistory($id, null, 'submitted', $actorId, $organizationId, 'applicant', 'تقديم الطلب');

            Database::statement(
                'UPDATE financing_products SET application_count = application_count + 1 WHERE id = ?',
                [$productId],
            );

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'financing_application.submitted',
                category: AuditLogger::CATEGORY_APPLICATION,
                entityType: 'financing_application',
                entityId: $id,
                description: 'تقديم طلب تمويل ' . $number . ' على «' . $product['name_ar'] . '»',
                severity: 'notice',
                userId: $actorId,
                organizationId: $organizationId,
            );

            $this->notifications->notifyPlatformReviewers(
                type: 'financing_application.submitted',
                title: 'طلب تمويل جديد للفرز',
                body: 'وصل طلب تمويل رقم ' . $number . ' بانتظار الفرز والإحالة.',
                actionUrl: url('/admin/finance/applications'),
                organizationId: $organizationId,
                entityType: 'financing_application',
                entityId: $id,
                permission: 'finance.application.screen',
            );

            return ['id' => $id, 'number' => $number, 'token' => $token];
        });
    }

    // ═══════════════════ الانتقالات | Transitions ═══════════════════

    /**
     * تنفيذ إجراء على الطلب | Perform an action on an application.
     *
     * @param  string $actorType applicant|platform|provider
     * @param  array<string,mixed> $decision بيانات القرار عند الاعتماد
     * @return array{from:string,to:string,action:string}
     */
    public function transition(
        int $applicationId,
        string $action,
        string $actorType,
        ?int $actorUserId,
        ?int $actorOrganizationId,
        Request $request,
        ?string $note = null,
        ?string $internalNote = null,
        array $decision = [],
    ): array {
        return Database::transaction(function () use (
            $applicationId, $action, $actorType, $actorUserId, $actorOrganizationId,
            $request, $note, $internalNote, $decision
        ): array {
            $application = Database::selectOne(
                'SELECT * FROM financing_applications WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
                [$applicationId],
            );

            if ($application === null) {
                throw new HttpException(404, 'طلب التمويل غير موجود.');
            }

            $this->assertActorBelongs($application, $actorType, $actorOrganizationId);

            $from = (string) $application['status'];

            if (!$this->can($from, $action)) {
                throw new HttpException(
                    422,
                    'لا يمكن تنفيذ هذا الإجراء على طلب حالته: ' . $this->statusLabel($from) . '.',
                );
            }

            if (!$this->actorMayPerform($action, $actorType)) {
                throw new AuthorizationException(
                    $this->actorRefusalMessage($action),
                    'finance.application.' . $action,
                    'financing_application',
                );
            }

            if ($this->requiresReason($action) && trim((string) $note) === '') {
                throw new HttpException(422, 'يجب توضيح السبب لتنفيذ هذا الإجراء.');
            }

            $to = self::RESULTING_STATUS[$action];

            $this->applyStatus($applicationId, $action, $to, $actorUserId, $note, $decision);

            $this->recordHistory(
                $applicationId,
                $from,
                $to,
                $actorUserId,
                $actorOrganizationId,
                $actorType,
                $note,
                // الملاحظة الداخلية للمنصة والمزوّد فقط، ولا تُعرض للمشروع أبداً
                $actorType === 'applicant' ? null : $internalNote,
            );

            $this->audit->setRequest($request);
            $this->audit->logStatusChange(
                'financing_application',
                $applicationId,
                $from,
                $to,
                AuditLogger::CATEGORY_APPLICATION,
                (int) $application['organization_id'],
            );

            $this->notifyTransition($application, $to, $note);

            return ['from' => $from, 'to' => $to, 'action' => $action];
        });
    }

    // ═══════════════════ المستندات | Requested documents ═══════════════════

    /** طلب مستند من المشروع | The provider requests a document. */
    public function requestDocument(
        int $applicationId,
        int $providerOrganizationId,
        string $label,
        ?string $note,
        bool $required,
        ?int $actorId,
    ): int {
        $application = $this->findForProvider($applicationId, $providerOrganizationId);

        $label = trim($label);

        if ($label === '') {
            throw new HttpException(422, 'اكتب اسم المستند المطلوب.');
        }

        return Database::insert(
            'INSERT INTO financing_application_documents
                (application_id, organization_id, provider_organization_id,
                 label_ar, note_ar, is_required, requested_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $applicationId,
                (int) $application['organization_id'],
                $providerOrganizationId,
                mb_substr($label, 0, 200),
                $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500),
                $required ? 1 : 0,
                $actorId,
            ],
        );
    }

    /** ربط ملف مرفوع بطلب مستند | Attach an uploaded file to a document request. */
    public function attachDocument(
        int $documentId,
        int $applicantOrganizationId,
        int $mediaId,
        ?int $actorId,
    ): void {
        $affected = Database::affectingStatement(
            'UPDATE financing_application_documents
                SET media_id = ?, uploaded_at = NOW(), uploaded_by = ?
              WHERE id = ? AND organization_id = ?',
            [$mediaId, $actorId, $documentId, $applicantOrganizationId],
        );

        if ($affected === 0) {
            throw new HttpException(404, 'طلب المستند غير موجود.');
        }
    }

    // ═══════════════════ الوصول | Access ═══════════════════

    /**
     * طلب يخصّ المشروع | An application belonging to the applicant.
     *
     * @return array<string,mixed>
     */
    public function findForApplicant(int $applicationId, int $organizationId): array
    {
        $row = Database::selectOne(
            'SELECT a.*, o.legal_name AS provider_legal_name, o.trading_name AS provider_trading_name,
                    o.slug AS provider_slug
               FROM financing_applications a
               JOIN organizations o ON o.id = a.provider_organization_id
              WHERE a.id = ? AND a.organization_id = ? AND a.deleted_at IS NULL
              LIMIT 1',
            [$applicationId, $organizationId],
        );

        if ($row === null) {
            throw new HttpException(404, 'طلب التمويل غير موجود.');
        }

        return $row;
    }

    /**
     * طلب يخصّ المزوّد | An application belonging to the provider.
     *
     * @return array<string,mixed>
     */
    public function findForProvider(int $applicationId, int $providerOrganizationId): array
    {
        $row = Database::selectOne(
            'SELECT a.*, o.legal_name AS applicant_legal_name, o.trading_name AS applicant_trading_name,
                    o.slug AS applicant_slug, o.completion_score,
                    g.name_ar AS applicant_governorate, s.name_ar AS applicant_sector
               FROM financing_applications a
               JOIN organizations o ON o.id = a.organization_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN sectors s ON s.id = o.sector_id
              WHERE a.id = ? AND a.provider_organization_id = ? AND a.deleted_at IS NULL
              LIMIT 1',
            [$applicationId, $providerOrganizationId],
        );

        if ($row === null) {
            throw new HttpException(404, 'طلب التمويل غير موجود.');
        }

        return $row;
    }

    /**
     * سجل الطلب كما يراه طرف بعينه | The history as one side may see it.
     *
     * الملاحظات الداخلية تُحذف من نسخة المشروع: هي مساحة عمل المنصة والمزوّد،
     * وعرضها للمشروع يكشف تقييمات ومداولات ليست موجَّهة إليه.
     *
     * @return array<int,array<string,mixed>>
     */
    public function history(int $applicationId, bool $includeInternal): array
    {
        $rows = Database::select(
            'SELECT h.*, u.name AS actor_name
               FROM financing_application_history h
               LEFT JOIN users u ON u.id = h.actor_user_id
              WHERE h.application_id = ?
              ORDER BY h.created_at ASC, h.id ASC',
            [$applicationId],
        );

        if ($includeInternal) {
            return $rows;
        }

        return array_map(static function (array $row): array {
            unset($row['internal_note_ar']);

            return $row;
        }, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function documents(int $applicationId): array
    {
        return Database::select(
            'SELECT d.*, m.original_name, m.size_bytes
               FROM financing_application_documents d
               LEFT JOIN media m ON m.id = d.media_id
              WHERE d.application_id = ?
              ORDER BY d.is_required DESC, d.id ASC',
            [$applicationId],
        );
    }

    // ═══════════════════ تسميات | Labels ═══════════════════

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'           => 'مسودة',
            'submitted'       => 'مُقدَّم',
            'screening'       => 'قيد الفرز',
            'forwarded'       => 'محال إلى المؤسسة',
            'provider_review' => 'قيد دراسة المؤسسة',
            'info_requested'  => 'بانتظار مستندات',
            'approved'        => 'مقبول من المؤسسة',
            'rejected'        => 'مرفوض من المؤسسة',
            'withdrawn'       => 'مسحوب',
            'cancelled'       => 'ملغي',
            default           => $status,
        };
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'submitted', 'info_requested'         => 'np-badge--pending',
            'screening', 'provider_review'        => 'np-badge--review',
            'forwarded'                           => 'np-badge--info',
            'approved'                            => 'np-badge--success',
            'rejected'                            => 'np-badge--danger',
            'withdrawn', 'cancelled'              => 'np-badge--muted',
            default                               => 'np-badge--draft',
        };
    }

    public function actionLabel(string $action): string
    {
        return match ($action) {
            'submit'          => 'تقديم الطلب',
            'start_screening' => 'بدء الفرز',
            'forward'         => 'إحالة إلى المؤسسة',
            'start_review'    => 'بدء الدراسة',
            'request_info'    => 'طلب مستندات إضافية',
            'resubmit'        => 'إعادة الإرسال بعد الاستكمال',
            'approve'         => 'تسجيل الموافقة',
            'reject'          => 'تسجيل الرفض',
            'withdraw'        => 'سحب الطلب',
            'cancel'          => 'إلغاء الطلب',
            default           => $action,
        };
    }

    // ─────────────────── داخلي | Internals ───────────────────

    private function actorMayPerform(string $action, string $actorType): bool
    {
        if (in_array($action, self::PROVIDER_ONLY, true)) {
            return $actorType === 'provider';
        }

        if (in_array($action, self::APPLICANT_ONLY, true)) {
            return $actorType === 'applicant';
        }

        if (in_array($action, self::PLATFORM_ONLY, true)) {
            return $actorType === 'platform';
        }

        // الإلغاء متاح للمنصة والمشروع | Cancellation: platform or applicant
        return in_array($actorType, ['platform', 'applicant'], true);
    }

    private function actorRefusalMessage(string $action): string
    {
        if (in_array($action, self::PROVIDER_ONLY, true)) {
            return 'هذا الإجراء من اختصاص المؤسسة المالية وحدها. المنصة تفرز وتحيل ولا تقرّر.';
        }

        if (in_array($action, self::APPLICANT_ONLY, true)) {
            return 'هذا الإجراء من حق صاحب الطلب وحده.';
        }

        return 'هذا الإجراء من اختصاص فريق المنصة.';
    }

    /**
     * الفاعل يجب أن ينتمي للجهة التي يدّعيها | The actor must belong to the side they claim.
     *
     * @param array<string,mixed> $application
     */
    private function assertActorBelongs(array $application, string $actorType, ?int $actorOrganizationId): void
    {
        if ($actorType === 'platform') {
            return; // التحقق من صلاحية المنصة يتم في طبقة المسارات
        }

        $expected = $actorType === 'provider'
            ? (int) $application['provider_organization_id']
            : (int) $application['organization_id'];

        if ($actorOrganizationId !== $expected) {
            // 404 لا 403: منشأة غريبة لا يجوز أن تتأكد من وجود الطلب
            throw new HttpException(404, 'طلب التمويل غير موجود.');
        }
    }

    /** @param array<string,mixed> $decision */
    private function applyStatus(
        int $applicationId,
        string $action,
        string $to,
        ?int $actorUserId,
        ?string $note,
        array $decision,
    ): void {
        // القرار والفاعل يُكتبان معاً في عبارة واحدة: لا مسار يُنتج «مقبول» بلا
        // `decided_by`، وهو ما يجعل القاعدة بنيوية لا اتفاقية.
        if (in_array($action, ['approve', 'reject'], true)) {
            Database::statement(
                'UPDATE financing_applications
                    SET status = ?, decided_by = ?, decided_at = NOW(), decision_note_ar = ?,
                        approved_amount = ?, approved_tenor_months = ?
                  WHERE id = ?',
                [
                    $to,
                    $actorUserId,
                    $note === null ? null : mb_substr($note, 0, 2000),
                    $action === 'approve' ? $this->nullableDecimal($decision['approved_amount'] ?? null) : null,
                    $action === 'approve' ? $this->nullableInt($decision['approved_tenor_months'] ?? null) : null,
                    $applicationId,
                ],
            );

            return;
        }

        if (in_array($action, ['start_screening', 'forward'], true)) {
            Database::statement(
                'UPDATE financing_applications
                    SET status = ?, screened_by = ?, screened_at = NOW(), screening_note = ?
                  WHERE id = ?',
                [
                    $to,
                    $actorUserId,
                    $note === null ? null : mb_substr($note, 0, 1000),
                    $applicationId,
                ],
            );

            return;
        }

        Database::statement(
            'UPDATE financing_applications SET status = ? WHERE id = ?',
            [$to, $applicationId],
        );
    }

    private function recordHistory(
        int $applicationId,
        ?string $from,
        string $to,
        ?int $actorUserId,
        ?int $actorOrganizationId,
        string $actorType,
        ?string $note = null,
        ?string $internalNote = null,
    ): void {
        Database::statement(
            'INSERT INTO financing_application_history
                (application_id, from_status, to_status, actor_user_id, actor_organization_id,
                 actor_type, note_ar, internal_note_ar)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $applicationId,
                $from,
                $to,
                $actorUserId,
                $actorOrganizationId,
                in_array($actorType, ['applicant', 'platform', 'provider', 'system'], true)
                    ? $actorType : 'system',
                $note === null ? null : mb_substr($note, 0, 1000),
                $internalNote === null ? null : mb_substr($internalNote, 0, 1000),
            ],
        );
    }

    /** @param array<string,mixed> $application */
    private function notifyTransition(array $application, string $to, ?string $note): void
    {
        $number = (string) $application['application_number'];

        // إشعار المشروع بما يخصّه | Tell the applicant what concerns them
        if (in_array($to, ['forwarded', 'provider_review', 'info_requested', 'approved', 'rejected', 'cancelled'], true)) {
            $this->notifications->notifyOrganizationMembers(
                organizationId: (int) $application['organization_id'],
                type: 'financing_application.' . $to,
                title: 'تحديث على طلب التمويل ' . $number,
                body: $this->applicantMessage($to, $note),
                severity: match ($to) {
                    'approved'             => 'success',
                    'rejected', 'cancelled' => 'warning',
                    default                => 'info',
                },
                actionUrl: url('/app/finance/applications/' . $application['id']),
                actionLabel: 'عرض الطلب',
                entityType: 'financing_application',
                entityId: (int) $application['id'],
            );
        }

        // إشعار المؤسسة عند وصول الطلب إليها أو استكمال المستندات
        if (in_array($to, ['forwarded', 'provider_review'], true)) {
            $this->notifications->notifyOrganizationMembers(
                organizationId: (int) $application['provider_organization_id'],
                type: 'financing_application.' . $to,
                title: 'طلب تمويل ' . $number,
                body: $to === 'forwarded'
                    ? 'أُحيل إليكم طلب تمويل جديد للدراسة.'
                    : 'استكمل مقدّم الطلب المستندات المطلوبة.',
                severity: 'info',
                actionUrl: url('/app/finance/requests/' . $application['id']),
                actionLabel: 'فتح الطلب',
                entityType: 'financing_application',
                entityId: (int) $application['id'],
            );
        }
    }

    private function applicantMessage(string $to, ?string $note): string
    {
        $suffix = $note !== null && trim($note) !== '' ? ' — ' . $note : '';

        return match ($to) {
            'forwarded'       => 'أُحيل طلبك إلى المؤسسة المالية للدراسة.' . $suffix,
            'provider_review' => 'بدأت المؤسسة المالية دراسة طلبك.' . $suffix,
            'info_requested'  => 'طلبت المؤسسة مستندات إضافية.' . $suffix,
            // نص الموافقة يذكر أن الالتزام على المؤسسة لا على المنصة
            'approved'        => 'سجّلت المؤسسة المالية موافقتها على طلبك. '
                . 'استكمال الإجراءات والتعاقد يتم مع المؤسسة مباشرةً.' . $suffix,
            'rejected'        => 'لم تُقبل المؤسسة المالية الطلب.' . $suffix,
            'cancelled'       => 'أُلغي الطلب.' . $suffix,
            default           => 'تغيّرت حالة طلبك.' . $suffix,
        };
    }

    /** @param array<string,mixed> $product */
    private function assertAmountWithinBand(array $product, float $amount): void
    {
        if ($product['min_amount'] !== null && $amount < (float) $product['min_amount']) {
            throw new HttpException(
                422,
                'الحد الأدنى لهذا المنتج هو ' . money((float) $product['min_amount']) . '.',
            );
        }

        if ($product['max_amount'] !== null && $amount > (float) $product['max_amount']) {
            throw new HttpException(
                422,
                'الحد الأعلى لهذا المنتج هو ' . money((float) $product['max_amount']) . '.',
            );
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (int) $value;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        return ($value === null || $value === '' || !is_numeric($value))
            ? null
            : number_format((float) $value, 2, '.', '');
    }
}
