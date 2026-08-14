<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Repositories\BdsCaseRepository;

/**
 * حالات دعم مراكز تطوير الأعمال | BDS support cases (§4.8).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * المسار: طلب المشروع ← فرز المركز ← إسناد لأخصائي ← جلسات وخطة عمل ← إغلاق
 * بنتيجة موثّقة. وإلى جانبه إحالات إلى منتجات تمويلية أو باقات خدمات معتمدة.
 *
 * ثلاث قواعد تحكم التصميم:
 *
 *  1. **الملاحظة الداخلية للأخصائي لا يراها المشروع.** محروسة في المستودع لا
 *     في القالب (انظر `BdsCaseRepository::notesFor`).
 *  2. **الإحالة توصية لا التزام.** لا تُنشئ طلب تمويل ولا طلب خدمة، ولا تلزم
 *     الجهة المُحال إليها بشيء. القرار يبقى للمشروع أولاً ثم للجهة (§4.5).
 *  3. **الإغلاق يستوجب نتيجة مكتوبة.** حالة تُغلق بلا ملخّص تترك المشروع بلا
 *     أثر لما جرى، وتُفرِغ تقارير المبادرة من المضمون.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class BdsCaseService
{
    /** @var array<string,array<int,string>> */
    private const TRANSITIONS = [
        'requested'          => ['start_triage', 'assign', 'cancel'],
        'triage'             => ['assign', 'cancel'],
        'assigned'           => ['start_work', 'reassign', 'hold', 'cancel'],
        'in_progress'        => ['hold', 'reassign', 'close_completed', 'close_referred', 'close_unreachable'],
        'on_hold'            => ['resume', 'close_unreachable', 'cancel'],
        'closed_completed'   => ['reopen'],
        'closed_referred'    => ['reopen'],
        'closed_unreachable' => ['reopen'],
        'cancelled'          => [],
    ];

    /** @var array<string,string> */
    private const RESULTING_STATUS = [
        'start_triage'       => 'triage',
        'assign'             => 'assigned',
        'reassign'           => 'assigned',
        'start_work'         => 'in_progress',
        'hold'               => 'on_hold',
        'resume'             => 'in_progress',
        'close_completed'    => 'closed_completed',
        'close_referred'     => 'closed_referred',
        'close_unreachable'  => 'closed_unreachable',
        'reopen'             => 'in_progress',
        'cancel'             => 'cancelled',
    ];

    /** إجراءات المركز وحده | Centre-only actions. */
    private const CENTER_ONLY = [
        'start_triage', 'assign', 'reassign', 'start_work', 'hold', 'resume',
        'close_completed', 'close_referred', 'close_unreachable', 'reopen',
    ];

    /** إجراءات تستوجب سبباً أو ملخّصاً | Actions requiring written text. */
    private const TEXT_REQUIRED = [
        'close_completed', 'close_referred', 'close_unreachable', 'hold', 'cancel',
    ];

    /** إجراءات الإغلاق | Closing actions. */
    private const CLOSING = ['close_completed', 'close_referred', 'close_unreachable'];

    public function __construct(
        private readonly BdsCaseRepository $cases = new BdsCaseRepository(),
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

    /** @return array<int,string> */
    public function actionsFor(string $currentStatus, string $side): array
    {
        return array_values(array_filter(
            $this->availableActions($currentStatus),
            fn (string $action): bool => $this->sideMayPerform($action, $side),
        ));
    }

    public function requiresText(string $action): bool
    {
        return in_array($action, self::TEXT_REQUIRED, true);
    }

    // ═══════════════════ طلب الدعم | Requesting support ═══════════════════

    /**
     * طلب المشروع دعماً من مركز | An SME requests support from a centre.
     *
     * @param  array<string,mixed> $data
     * @return array{id:int,number:string}
     */
    public function request(int $organizationId, int $centerOrganizationId, array $data, ?int $actorId, Request $request): array
    {
        $center = Database::selectOne(
            "SELECT o.id, o.legal_name, o.trading_name
               FROM organizations o
               JOIN organization_types t ON t.id = o.organization_type_id
              WHERE o.id = ? AND t.code = 'bds_center'
                AND o.status = 'verified' AND o.deleted_at IS NULL
              LIMIT 1",
            [$centerOrganizationId],
        );

        if ($center === null) {
            throw new HttpException(404, 'مركز تطوير الأعمال المطلوب غير متاح.');
        }

        // حالة مفتوحة قائمة مع نفس المركز: فتح ثانية يشتّت المتابعة ويضاعف
        // العبء على المركز دون أن يخدم المشروع.
        $open = Database::selectOne(
            "SELECT id, case_number FROM bds_cases
              WHERE organization_id = ? AND center_organization_id = ?
                AND status NOT IN ('closed_completed','closed_referred','closed_unreachable','cancelled')
                AND deleted_at IS NULL
              LIMIT 1",
            [$organizationId, $centerOrganizationId],
        );

        if ($open !== null) {
            throw new HttpException(
                422,
                'لديك حالة دعم مفتوحة مع هذا المركز برقم ' . $open['case_number'] . '. '
                . 'تابعها بدل فتح حالة جديدة.',
            );
        }

        $title   = trim((string) ($data['title_ar'] ?? ''));
        $details = trim((string) ($data['request_details_ar'] ?? ''));

        if (mb_strlen($title) < 5) {
            throw new HttpException(422, 'اكتب عنواناً واضحاً لطلب الدعم.');
        }

        if (mb_strlen($details) < 20) {
            throw new HttpException(
                422,
                'اشرح احتياجك في عشرين حرفاً على الأقل ليتمكّن المركز من إسناد الأخصائي المناسب.',
            );
        }

        return Database::transaction(function () use (
            $organizationId, $centerOrganizationId, $center, $title, $details, $data, $actorId, $request
        ): array {
            $number = 'BDS-' . date('ym') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $caseId = Database::insert(
                'INSERT INTO bds_cases
                    (case_number, organization_id, center_organization_id, title_ar,
                     request_details_ar, focus_areas, assessment_id, status, priority)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $number,
                    $organizationId,
                    $centerOrganizationId,
                    mb_substr($title, 0, 200),
                    mb_substr($details, 0, 2000),
                    $this->focusAreas($data['focus_areas'] ?? null),
                    $this->nullableInt($data['assessment_id'] ?? null),
                    'requested',
                    'normal',
                ],
            );

            $this->recordHistory($caseId, null, 'requested', $actorId, 'organization', 'طلب دعم جديد');

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'bds_case.requested',
                category: AuditLogger::CATEGORY_APPLICATION,
                entityType: 'bds_case',
                entityId: $caseId,
                description: 'طلب دعم ' . $number . ' من ' . ($center['trading_name'] ?: $center['legal_name']),
                userId: $actorId,
                organizationId: $organizationId,
            );

            $this->notifications->notifyOrganizationMembers(
                organizationId: $centerOrganizationId,
                type: 'bds_case.requested',
                title: 'طلب دعم جديد',
                body: 'وصلكم طلب دعم برقم ' . $number . ': ' . $title,
                severity: 'info',
                actionUrl: url('/app/bds/cases/' . $caseId),
                actionLabel: 'فتح الحالة',
                entityType: 'bds_case',
                entityId: $caseId,
            );

            return ['id' => $caseId, 'number' => $number];
        });
    }

    // ═══════════════════ الانتقالات | Transitions ═══════════════════

    /**
     * تنفيذ إجراء على الحالة | Perform an action on a case.
     *
     * @param  array<string,mixed> $extra
     * @return array{from:string,to:string,action:string}
     */
    public function transition(
        int $caseId,
        string $action,
        string $side,
        ?int $actorUserId,
        int $actorOrganizationId,
        Request $request,
        ?string $note = null,
        array $extra = [],
    ): array {
        return Database::transaction(function () use (
            $caseId, $action, $side, $actorUserId, $actorOrganizationId, $request, $note, $extra
        ): array {
            $case = Database::selectOne(
                'SELECT * FROM bds_cases WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
                [$caseId],
            );

            if ($case === null) {
                throw new HttpException(404, 'حالة الدعم غير موجودة.');
            }

            $this->assertActorBelongs($case, $side, $actorOrganizationId);

            $from = (string) $case['status'];

            if (!$this->can($from, $action)) {
                throw new HttpException(
                    422,
                    'لا يمكن تنفيذ هذا الإجراء على حالة وضعها: ' . $this->statusLabel($from) . '.',
                );
            }

            if (!$this->sideMayPerform($action, $side)) {
                throw new AuthorizationException(
                    'هذا الإجراء من اختصاص مركز تطوير الأعمال.',
                    'bds.case.manage',
                    'bds_case',
                );
            }

            if ($this->requiresText($action) && trim((string) $note) === '') {
                throw new HttpException(
                    422,
                    in_array($action, self::CLOSING, true)
                        ? 'اكتب ملخّص النتيجة قبل إغلاق الحالة. الإغلاق بلا ملخّص يترك المشروع بلا أثر.'
                        : 'يجب توضيح السبب لتنفيذ هذا الإجراء.',
                );
            }

            $to = self::RESULTING_STATUS[$action];

            $this->applyStatus($caseId, $action, $to, $actorUserId, $actorOrganizationId, $note, $extra);
            $this->recordHistory($caseId, $from, $to, $actorUserId, $side, $note);

            $this->audit->setRequest($request);
            $this->audit->logStatusChange(
                'bds_case',
                $caseId,
                $from,
                $to,
                AuditLogger::CATEGORY_APPLICATION,
                (int) $case['organization_id'],
            );

            $this->notifyTransition($case, $to, $note);

            return ['from' => $from, 'to' => $to, 'action' => $action];
        });
    }

    // ═══════════════════ الملاحظات | Notes ═══════════════════

    /**
     * إضافة ملاحظة | Add a note.
     *
     * الملاحظة الداخلية لا يكتبها إلا المركز: «داخلية» تعني داخل المركز، فلو
     * كتبها المشروع لما كان لها معنى — هو لا يملك مساحة داخلية هنا.
     */
    public function addNote(
        int $caseId,
        string $side,
        int $actorOrganizationId,
        ?int $actorUserId,
        string $body,
        string $visibility,
    ): int {
        $case = $this->cases->findFor($side, $caseId, $actorOrganizationId);

        $body = trim($body);

        if (mb_strlen($body) < 3) {
            throw new HttpException(422, 'اكتب نص الملاحظة.');
        }

        if ($side === BdsCaseRepository::SIDE_ORGANIZATION && $visibility !== 'shared') {
            throw new AuthorizationException(
                'الملاحظات الداخلية مساحة عمل المركز، ولا تُكتب من حساب المشروع.',
                'bds.note.internal',
                'bds_case_note',
            );
        }

        if (!in_array($visibility, ['internal', 'shared'], true)) {
            $visibility = 'internal';
        }

        return Database::insert(
            'INSERT INTO bds_case_notes
                (case_id, organization_id, center_organization_id, visibility,
                 body_ar, author_user_id, author_side)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $caseId,
                (int) $case['organization_id'],
                (int) $case['center_organization_id'],
                $visibility,
                mb_substr($body, 0, 3000),
                $actorUserId,
                $side === BdsCaseRepository::SIDE_CENTER ? 'center' : 'organization',
            ],
        );
    }

    // ═══════════════════ الجلسات | Consultations ═══════════════════

    /** جدولة جلسة | Schedule a consultation session. */
    public function scheduleConsultation(
        int $caseId,
        int $centerOrganizationId,
        ?int $actorUserId,
        array $data,
    ): int {
        $case = $this->cases->findFor(BdsCaseRepository::SIDE_CENTER, $caseId, $centerOrganizationId);

        $title     = trim((string) ($data['title_ar'] ?? ''));
        $scheduled = trim((string) ($data['scheduled_at'] ?? ''));

        if ($title === '') {
            throw new HttpException(422, 'اكتب عنوان الجلسة.');
        }

        $timestamp = strtotime($scheduled);

        if ($timestamp === false) {
            throw new HttpException(422, 'حدّد موعد الجلسة.');
        }

        $mode = (string) ($data['mode'] ?? 'onsite');

        if (!in_array($mode, ['onsite', 'phone', 'online'], true)) {
            $mode = 'onsite';
        }

        $consultationId = Database::insert(
            'INSERT INTO bds_consultations
                (case_id, organization_id, center_organization_id, title_ar, scheduled_at,
                 duration_minutes, mode, location_ar, specialist_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $caseId,
                (int) $case['organization_id'],
                $centerOrganizationId,
                mb_substr($title, 0, 200),
                date('Y-m-d H:i:s', $timestamp),
                $this->nullableInt($data['duration_minutes'] ?? null),
                $mode,
                $this->nullableText($data['location_ar'] ?? null, 300),
                $actorUserId,
            ],
        );

        $this->notifications->notifyOrganizationMembers(
            organizationId: (int) $case['organization_id'],
            type: 'bds_consultation.scheduled',
            title: 'موعد جلسة استشارية',
            body: $title . ' — ' . format_date(date('Y-m-d H:i:s', $timestamp), true),
            severity: 'info',
            actionUrl: url('/app/bds/my-cases/' . $caseId),
            actionLabel: 'عرض الحالة',
            entityType: 'bds_case',
            entityId: $caseId,
        );

        return $consultationId;
    }

    /** تسجيل نتيجة الجلسة | Record the outcome of a session. */
    public function recordConsultation(
        int $consultationId,
        int $centerOrganizationId,
        string $status,
        ?string $summary,
        ?string $internalNote,
    ): void {
        if (!in_array($status, ['scheduled', 'completed', 'no_show', 'cancelled'], true)) {
            throw new HttpException(422, 'حالة الجلسة غير معروفة.');
        }

        if ($status === 'completed' && trim((string) $summary) === '') {
            throw new HttpException(
                422,
                'اكتب ملخّص الجلسة. جلسة مكتملة بلا ملخّص لا تفيد المشروع ولا التقارير.',
            );
        }

        $affected = Database::affectingStatement(
            "UPDATE bds_consultations
                SET status = ?, summary_ar = ?, internal_note_ar = ?,
                    completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END
              WHERE id = ? AND center_organization_id = ?",
            [
                $status,
                $this->nullableText($summary, 2000),
                $this->nullableText($internalNote, 2000),
                $status,
                $consultationId,
                $centerOrganizationId,
            ],
        );

        if ($affected === 0) {
            throw new HttpException(404, 'الجلسة غير موجودة.');
        }
    }

    // ═══════════════════ خطط العمل | Action plans ═══════════════════

    public function createPlan(int $caseId, int $centerOrganizationId, ?int $actorUserId, array $data): int
    {
        $case = $this->cases->findFor(BdsCaseRepository::SIDE_CENTER, $caseId, $centerOrganizationId);

        $title = trim((string) ($data['title_ar'] ?? ''));

        if ($title === '') {
            throw new HttpException(422, 'اكتب عنوان خطة العمل.');
        }

        return Database::insert(
            'INSERT INTO bds_action_plans
                (case_id, organization_id, center_organization_id, title_ar,
                 objective_ar, starts_on, ends_on, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $caseId,
                (int) $case['organization_id'],
                $centerOrganizationId,
                mb_substr($title, 0, 200),
                $this->nullableText($data['objective_ar'] ?? null, 2000),
                $this->nullableDate($data['starts_on'] ?? null),
                $this->nullableDate($data['ends_on'] ?? null),
                'draft',
                $actorUserId,
            ],
        );
    }

    public function addTask(int $planId, int $centerOrganizationId, array $data): int
    {
        $plan = $this->ownedPlan($planId, $centerOrganizationId);

        $title = trim((string) ($data['title_ar'] ?? ''));

        if ($title === '') {
            throw new HttpException(422, 'اكتب عنوان المهمة.');
        }

        $ownerSide = (string) ($data['owner_side'] ?? 'organization');

        if (!in_array($ownerSide, ['organization', 'center'], true)) {
            $ownerSide = 'organization';
        }

        $order = (int) Database::scalar(
            'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM bds_plan_tasks WHERE plan_id = ?',
            [$planId],
        );

        return Database::insert(
            'INSERT INTO bds_plan_tasks
                (plan_id, organization_id, center_organization_id, title_ar,
                 description_ar, owner_side, due_date, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $planId,
                (int) $plan['organization_id'],
                $centerOrganizationId,
                mb_substr($title, 0, 200),
                $this->nullableText($data['description_ar'] ?? null, 1000),
                $ownerSide,
                $this->nullableDate($data['due_date'] ?? null),
                $order,
            ],
        );
    }

    /**
     * مشاركة الخطة مع المشروع | Share the plan with the SME.
     *
     * المشاركة نقطة تحوّل: قبلها الخطة مسودة داخلية، وبعدها التزام معلن. لذلك
     * تُرفض مشاركة خطة بلا مهام — خطة فارغة تُبلَّغ للمشروع وعد بلا محتوى.
     */
    public function sharePlan(int $planId, int $centerOrganizationId): void
    {
        $plan = $this->ownedPlan($planId, $centerOrganizationId);

        $taskCount = (int) Database::scalar(
            'SELECT COUNT(*) FROM bds_plan_tasks WHERE plan_id = ?',
            [$planId],
        );

        if ($taskCount === 0) {
            throw new HttpException(422, 'أضف مهمة واحدة على الأقل قبل مشاركة الخطة.');
        }

        Database::statement(
            "UPDATE bds_action_plans
                SET status = 'active', shared_at = NOW()
              WHERE id = ? AND center_organization_id = ?",
            [$planId, $centerOrganizationId],
        );

        $this->notifications->notifyOrganizationMembers(
            organizationId: (int) $plan['organization_id'],
            type: 'bds_plan.shared',
            title: 'خطة عمل جديدة',
            body: 'شارك معك المركز خطة عمل: ' . $plan['title_ar'],
            severity: 'info',
            actionUrl: url('/app/bds/my-cases/' . $plan['case_id']),
            actionLabel: 'عرض الخطة',
            entityType: 'bds_case',
            entityId: (int) $plan['case_id'],
        );
    }

    /**
     * تحديث حالة مهمة | Update a task's status.
     *
     * ينفّذها الطرف صاحب المهمة: مهمة على المشروع يحدّثها المشروع، ومهمة على
     * المركز يحدّثها المركز. تحديث أحدهما مهمة الآخر يزوّر تقدّماً لم يحدث.
     */
    public function updateTask(
        int $taskId,
        string $side,
        int $actorOrganizationId,
        ?int $actorUserId,
        string $status,
    ): void {
        if (!in_array($status, ['pending', 'in_progress', 'done', 'skipped'], true)) {
            throw new HttpException(422, 'حالة المهمة غير معروفة.');
        }

        $column = $side === BdsCaseRepository::SIDE_CENTER
            ? 'center_organization_id'
            : 'organization_id';

        $task = Database::selectOne(
            "SELECT t.*, p.shared_at
               FROM bds_plan_tasks t
               JOIN bds_action_plans p ON p.id = t.plan_id
              WHERE t.id = ? AND t.{$column} = ?
              LIMIT 1",
            [$taskId, $actorOrganizationId],
        );

        if ($task === null) {
            throw new HttpException(404, 'المهمة غير موجودة.');
        }

        $actorSide = $side === BdsCaseRepository::SIDE_CENTER ? 'center' : 'organization';

        if ((string) $task['owner_side'] !== $actorSide) {
            throw new AuthorizationException(
                'هذه المهمة على الطرف الآخر، ولا يجوز تحديث حالتها نيابةً عنه.',
                'bds.plan.manage',
                'bds_plan_task',
            );
        }

        // المشروع لا يرى الخطة قبل مشاركتها، فلا يحدّث مهامها كذلك
        if ($side === BdsCaseRepository::SIDE_ORGANIZATION && $task['shared_at'] === null) {
            throw new HttpException(404, 'المهمة غير موجودة.');
        }

        Database::statement(
            "UPDATE bds_plan_tasks
                SET status = ?,
                    completed_at = CASE WHEN ? = 'done' THEN NOW() ELSE NULL END,
                    completed_by = CASE WHEN ? = 'done' THEN ? ELSE NULL END
              WHERE id = ?",
            [$status, $status, $status, $actorUserId, $taskId],
        );
    }

    // ═══════════════════ الإحالات | Referrals ═══════════════════

    /**
     * إحالة المشروع إلى عرض معتمد | Refer the SME to an approved offer.
     *
     * الإحالة **توصية موثّقة لا التزام**: لا تُنشئ طلباً ولا تُلزم الجهة
     * المُحال إليها. المشروع هو من يقرّر التقديم، والجهة هي من تقرّر القبول.
     */
    public function refer(
        int $caseId,
        int $centerOrganizationId,
        ?int $actorUserId,
        string $targetType,
        int $targetId,
        string $reason,
    ): int {
        $case = $this->cases->findFor(BdsCaseRepository::SIDE_CENTER, $caseId, $centerOrganizationId);

        if (!in_array($targetType, ['financing_product', 'service_offering'], true)) {
            throw new HttpException(422, 'نوع الإحالة غير معروف.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new HttpException(
                422,
                'اشرح لماذا يناسب هذا العرض المشروع. إحالة بلا سبب لا تساعده على القرار.',
            );
        }

        $table  = $targetType === 'financing_product' ? 'financing_products' : 'service_offerings';

        // لا يُحال إلا إلى عرض معتمد من جهة موثّقة — نفس حدّ الرؤية العام
        $target = Database::selectOne(
            "SELECT t.id, t.name_ar
               FROM `{$table}` t
               JOIN organizations o ON o.id = t.organization_id
              WHERE t.id = ? AND t.status = 'published' AND t.deleted_at IS NULL
                AND o.status = 'verified' AND o.deleted_at IS NULL
              LIMIT 1",
            [$targetId],
        );

        if ($target === null) {
            throw new HttpException(404, 'العرض المطلوب غير متاح للإحالة إليه.');
        }

        $referralId = Database::insert(
            'INSERT INTO bds_referrals
                (case_id, organization_id, center_organization_id, target_type, target_id,
                 target_name_ar, reason_ar, referred_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $caseId,
                (int) $case['organization_id'],
                $centerOrganizationId,
                $targetType,
                $targetId,
                (string) $target['name_ar'],
                mb_substr($reason, 0, 1000),
                $actorUserId,
            ],
        );

        $this->notifications->notifyOrganizationMembers(
            organizationId: (int) $case['organization_id'],
            type: 'bds_referral.created',
            title: 'توصية من مركز تطوير الأعمال',
            body: 'أوصى المركز بـ«' . $target['name_ar'] . '». القرار قرارك، والتقديم اختياري.',
            severity: 'info',
            actionUrl: url('/app/bds/my-cases/' . $caseId),
            actionLabel: 'عرض التوصية',
            entityType: 'bds_case',
            entityId: $caseId,
        );

        return $referralId;
    }

    /** ردّ المشروع على الإحالة | The SME's response to a referral. */
    public function respondToReferral(
        int $referralId,
        int $organizationId,
        string $decision,
        ?string $note,
    ): void {
        if (!in_array($decision, ['accepted', 'declined'], true)) {
            throw new HttpException(422, 'قرار غير معروف.');
        }

        $affected = Database::affectingStatement(
            'UPDATE bds_referrals
                SET status = ?, responded_at = NOW(), response_note_ar = ?
              WHERE id = ? AND organization_id = ?',
            [$decision, $this->nullableText($note, 500), $referralId, $organizationId],
        );

        if ($affected === 0) {
            throw new HttpException(404, 'التوصية غير موجودة.');
        }
    }

    // ═══════════════════ تسميات | Labels ═══════════════════

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'requested'          => 'طلب جديد',
            'triage'             => 'قيد الفرز',
            'assigned'           => 'مُسندة لأخصائي',
            'in_progress'        => 'قيد المتابعة',
            'on_hold'            => 'موقوفة مؤقتاً',
            'closed_completed'   => 'مغلقة — اكتمل الدعم',
            'closed_referred'    => 'مغلقة — أُحيلت لجهة أخرى',
            'closed_unreachable' => 'مغلقة — تعذّر التواصل',
            'cancelled'          => 'ملغاة',
            default              => $status,
        };
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'requested'                          => 'np-badge--pending',
            'triage', 'assigned'                 => 'np-badge--review',
            'in_progress'                        => 'np-badge--info',
            'closed_completed'                   => 'np-badge--success',
            'closed_referred'                    => 'np-badge--info',
            'on_hold', 'closed_unreachable'      => 'np-badge--warning',
            'cancelled'                          => 'np-badge--muted',
            default                              => 'np-badge--draft',
        };
    }

    public function actionLabel(string $action): string
    {
        return match ($action) {
            'start_triage'       => 'بدء الفرز',
            'assign'             => 'إسناد لأخصائي',
            'reassign'           => 'تغيير الأخصائي',
            'start_work'         => 'بدء المتابعة',
            'hold'               => 'إيقاف مؤقت',
            'resume'             => 'استئناف المتابعة',
            'close_completed'    => 'إغلاق — اكتمل الدعم',
            'close_referred'     => 'إغلاق — إحالة لجهة أخرى',
            'close_unreachable'  => 'إغلاق — تعذّر التواصل',
            'reopen'             => 'إعادة فتح الحالة',
            'cancel'             => 'إلغاء الطلب',
            default              => $action,
        };
    }

    // ─────────────────── داخلي | Internals ───────────────────

    private function sideMayPerform(string $action, string $side): bool
    {
        if (in_array($action, self::CENTER_ONLY, true)) {
            return $side === BdsCaseRepository::SIDE_CENTER;
        }

        // الإلغاء متاح للطرفين: المشروع يسحب طلبه والمركز يعتذر عنه
        return in_array($side, [BdsCaseRepository::SIDE_CENTER, BdsCaseRepository::SIDE_ORGANIZATION], true);
    }

    /** @param array<string,mixed> $case */
    private function assertActorBelongs(array $case, string $side, int $actorOrganizationId): void
    {
        $expected = $side === BdsCaseRepository::SIDE_CENTER
            ? (int) $case['center_organization_id']
            : (int) $case['organization_id'];

        if ($actorOrganizationId !== $expected) {
            throw new HttpException(404, 'حالة الدعم غير موجودة.');
        }
    }

    /** @param array<string,mixed> $extra */
    private function applyStatus(
        int $caseId,
        string $action,
        string $to,
        ?int $actorUserId,
        int $actorOrganizationId,
        ?string $note,
        array $extra,
    ): void {
        if (in_array($action, ['assign', 'reassign'], true)) {
            $specialistId = $this->nullableInt($extra['assigned_to'] ?? null);

            if ($specialistId === null) {
                throw new HttpException(422, 'اختر الأخصائي المسؤول.');
            }

            // الأخصائي يجب أن يكون عضواً نشطاً في المركز نفسه
            $isMember = Database::scalar(
                "SELECT 1 FROM organization_members
                  WHERE user_id = ? AND organization_id = ? AND status = 'active' LIMIT 1",
                [$specialistId, $actorOrganizationId],
            );

            if ($isMember === null) {
                throw new HttpException(422, 'الأخصائي المختار ليس عضواً نشطاً في المركز.');
            }

            Database::statement(
                'UPDATE bds_cases
                    SET status = ?, assigned_to = ?, assigned_at = NOW(), assigned_by = ?
                  WHERE id = ?',
                [$to, $specialistId, $actorUserId, $caseId],
            );

            return;
        }

        if (in_array($action, self::CLOSING, true)) {
            Database::statement(
                'UPDATE bds_cases
                    SET status = ?, closed_at = NOW(), closed_by = ?, outcome_summary_ar = ?
                  WHERE id = ?',
                [$to, $actorUserId, mb_substr((string) $note, 0, 2000), $caseId],
            );

            return;
        }

        if ($action === 'reopen') {
            // إعادة الفتح تمحو ختم الإغلاق: حالة مفتوحة تحمل تاريخ إغلاق
            // تُربك التقارير وتبدو مغلقة لأي استعلام يعتمد على العمود.
            Database::statement(
                'UPDATE bds_cases
                    SET status = ?, closed_at = NULL, closed_by = NULL
                  WHERE id = ?',
                [$to, $caseId],
            );

            return;
        }

        Database::statement('UPDATE bds_cases SET status = ? WHERE id = ?', [$to, $caseId]);
    }

    private function recordHistory(
        int $caseId,
        ?string $from,
        string $to,
        ?int $actorUserId,
        string $side,
        ?string $note,
    ): void {
        Database::statement(
            'INSERT INTO bds_case_history
                (case_id, from_status, to_status, actor_user_id, actor_side, note_ar)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $caseId,
                $from,
                $to,
                $actorUserId,
                match ($side) {
                    BdsCaseRepository::SIDE_CENTER       => 'center',
                    BdsCaseRepository::SIDE_ORGANIZATION => 'organization',
                    'platform'                           => 'platform',
                    default                              => 'system',
                },
                $note === null ? null : mb_substr($note, 0, 1000),
            ],
        );
    }

    /** @param array<string,mixed> $case */
    private function notifyTransition(array $case, string $to, ?string $note): void
    {
        // المشروع يُشعَر بما يخصّه فقط؛ الفرز الداخلي للمركز لا يعنيه
        if (!in_array($to, ['assigned', 'in_progress', 'on_hold', 'closed_completed',
            'closed_referred', 'closed_unreachable', 'cancelled'], true)) {
            return;
        }

        $this->notifications->notifyOrganizationMembers(
            organizationId: (int) $case['organization_id'],
            type: 'bds_case.' . $to,
            title: 'تحديث على حالة الدعم ' . $case['case_number'],
            body: $this->transitionMessage($to)
                . ($note !== null && trim($note) !== '' ? ' — ' . $note : ''),
            severity: match ($to) {
                'closed_completed'                      => 'success',
                'closed_unreachable', 'cancelled'       => 'warning',
                default                                 => 'info',
            },
            actionUrl: url('/app/bds/my-cases/' . $case['id']),
            actionLabel: 'عرض الحالة',
            entityType: 'bds_case',
            entityId: (int) $case['id'],
        );
    }

    private function transitionMessage(string $to): string
    {
        return match ($to) {
            'assigned'           => 'أُسندت حالتك إلى أخصائي تطوير أعمال.',
            'in_progress'        => 'بدأت متابعة حالتك.',
            'on_hold'            => 'أُوقفت متابعة حالتك مؤقتاً.',
            'closed_completed'   => 'أُغلقت حالتك بعد اكتمال الدعم.',
            'closed_referred'    => 'أُغلقت حالتك بإحالتك إلى جهة أخرى.',
            'closed_unreachable' => 'أُغلقت حالتك لتعذّر التواصل.',
            'cancelled'          => 'أُلغي طلب الدعم.',
            default              => 'تغيّرت حالة طلب الدعم.',
        };
    }

    /** @return array<string,mixed> */
    private function ownedPlan(int $planId, int $centerOrganizationId): array
    {
        $plan = Database::selectOne(
            'SELECT * FROM bds_action_plans WHERE id = ? AND center_organization_id = ? LIMIT 1',
            [$planId, $centerOrganizationId],
        );

        if ($plan === null) {
            throw new HttpException(404, 'خطة العمل غير موجودة.');
        }

        return $plan;
    }

    private function focusAreas(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        $allowed = array_keys(AssessmentService::SECTIONS);
        $areas   = array_values(array_intersect(
            array_map(static fn ($v): string => (string) $v, $value),
            $allowed,
        ));

        return $areas === [] ? null : implode(',', $areas);
    }

    private function nullableText(mixed $value, int $length): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    private function nullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (int) $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        $date = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }
}
