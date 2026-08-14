<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Core\Request;

/**
 * طلبات الخدمات غير المالية | Non-financial service requests (§4.6).
 *
 * المسار: طلب المشروع ← دراسة المزوّد ← عرض ← قبول أو رفض المشروع ← تنفيذ
 * بمراحل ← تسليم ← إغلاق. كما في التمويل، القبول قرار صاحبه: **المزوّد لا
 * يقبل عرضه نيابةً عن المشروع، والمشروع لا يعلن التسليم نيابةً عن المزوّد.**
 *
 * As with financing, each side owns its own decision: the provider cannot
 * accept its own proposal, and the applicant cannot mark delivery for it.
 */
final class ServiceRequestService
{
    /** @var array<string,array<int,string>> */
    private const TRANSITIONS = [
        'submitted'       => ['start_review', 'cancel'],
        'provider_review' => ['propose', 'decline', 'cancel'],
        'proposed'        => ['accept', 'reject_proposal', 'cancel'],
        'accepted'        => ['start_work', 'cancel'],
        'in_progress'     => ['deliver', 'cancel'],
        'delivered'       => ['complete'],
        'completed'       => [],
        'declined'        => [],
        'cancelled'       => [],
    ];

    /** @var array<string,string> */
    private const RESULTING_STATUS = [
        'start_review'    => 'provider_review',
        'propose'         => 'proposed',
        'decline'         => 'declined',
        'accept'          => 'accepted',
        'reject_proposal' => 'declined',
        'start_work'      => 'in_progress',
        'deliver'         => 'delivered',
        'complete'        => 'completed',
        'cancel'          => 'cancelled',
    ];

    /** إجراءات المزوّد وحده | Provider-only actions. */
    private const PROVIDER_ONLY = ['start_review', 'propose', 'decline', 'start_work', 'deliver'];

    /** إجراءات المشروع وحده | Applicant-only actions. */
    private const APPLICANT_ONLY = ['accept', 'reject_proposal', 'complete'];

    /** إجراءات تستوجب سبباً | Actions requiring a reason. */
    private const REASON_REQUIRED = ['decline', 'reject_proposal', 'cancel'];

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

    /** @return array<int,string> */
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
     * تقديم طلب خدمة | Submit a service request.
     *
     * @param  array<string,mixed> $data
     * @return array{id:int,number:string,token:string}
     */
    public function submit(
        int $organizationId,
        int $offeringId,
        array $data,
        ?int $actorId,
        Request $request,
        ?int $referredByUserId = null,
        ?int $referredByOrganizationId = null,
    ): array {
        $offering = Database::selectOne(
            "SELECT s.*, o.status AS provider_status
               FROM service_offerings s
               JOIN organizations o ON o.id = s.organization_id
              WHERE s.id = ? AND s.status = 'published' AND s.deleted_at IS NULL
                AND o.status = 'verified' AND o.deleted_at IS NULL
              LIMIT 1",
            [$offeringId],
        );

        if ($offering === null) {
            throw new HttpException(404, 'الخدمة المطلوبة غير متاحة.');
        }

        $details = trim((string) ($data['details_ar'] ?? ''));

        if (mb_strlen($details) < 20) {
            throw new HttpException(422, 'اشرح احتياجك في عشرين حرفاً على الأقل ليتمكّن المزوّد من تقدير العمل.');
        }

        return Database::transaction(function () use (
            $organizationId, $offering, $offeringId, $data, $details, $actorId, $request,
            $referredByUserId, $referredByOrganizationId
        ): array {
            $number = 'SRV-' . date('ym') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $token  = bin2hex(random_bytes(24));

            $id = Database::insert(
                'INSERT INTO service_requests
                    (request_number, organization_id, provider_organization_id, service_offering_id,
                     offering_name_ar, details_ar, preferred_start_date, contact_person, contact_phone,
                     status, referred_by_user_id, referred_by_organization_id, tracking_token)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $number,
                    $organizationId,
                    (int) $offering['organization_id'],
                    $offeringId,
                    (string) $offering['name_ar'],
                    mb_substr($details, 0, 2000),
                    $this->nullableDate($data['preferred_start_date'] ?? null),
                    $this->nullableText($data['contact_person'] ?? null, 150),
                    $this->nullableText($data['contact_phone'] ?? null, 30),
                    'submitted',
                    $referredByUserId,
                    $referredByOrganizationId,
                    $token,
                ],
            );

            $this->recordHistory($id, null, 'submitted', $actorId, 'applicant', 'تقديم طلب الخدمة');

            Database::statement(
                'UPDATE service_offerings SET request_count = request_count + 1 WHERE id = ?',
                [$offeringId],
            );

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'service_request.submitted',
                category: AuditLogger::CATEGORY_APPLICATION,
                entityType: 'service_request',
                entityId: $id,
                description: 'طلب خدمة ' . $number . ' على «' . $offering['name_ar'] . '»',
                userId: $actorId,
                organizationId: $organizationId,
            );

            $this->notifications->notifyOrganizationMembers(
                organizationId: (int) $offering['organization_id'],
                type: 'service_request.submitted',
                title: 'طلب خدمة جديد',
                body: 'وصلكم طلب على «' . $offering['name_ar'] . '» برقم ' . $number . '.',
                severity: 'info',
                actionUrl: url('/app/services/requests/' . $id),
                actionLabel: 'فتح الطلب',
                entityType: 'service_request',
                entityId: $id,
            );

            return ['id' => $id, 'number' => $number, 'token' => $token];
        });
    }

    // ═══════════════════ الانتقالات | Transitions ═══════════════════

    /**
     * @param  array<string,mixed> $proposal
     * @return array{from:string,to:string,action:string}
     */
    public function transition(
        int $requestId,
        string $action,
        string $actorType,
        ?int $actorUserId,
        ?int $actorOrganizationId,
        Request $request,
        ?string $note = null,
        array $proposal = [],
    ): array {
        return Database::transaction(function () use (
            $requestId, $action, $actorType, $actorUserId, $actorOrganizationId, $request, $note, $proposal
        ): array {
            $serviceRequest = Database::selectOne(
                'SELECT * FROM service_requests WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
                [$requestId],
            );

            if ($serviceRequest === null) {
                throw new HttpException(404, 'طلب الخدمة غير موجود.');
            }

            $this->assertActorBelongs($serviceRequest, $actorType, $actorOrganizationId);

            $from = (string) $serviceRequest['status'];

            if (!$this->can($from, $action)) {
                throw new HttpException(
                    422,
                    'لا يمكن تنفيذ هذا الإجراء على طلب حالته: ' . $this->statusLabel($from) . '.',
                );
            }

            if (!$this->actorMayPerform($action, $actorType)) {
                throw new AuthorizationException(
                    in_array($action, self::PROVIDER_ONLY, true)
                        ? 'هذا الإجراء من اختصاص مقدّم الخدمة.'
                        : 'هذا الإجراء من حق المشروع طالب الخدمة.',
                    'services.request.' . $action,
                    'service_request',
                );
            }

            if ($this->requiresReason($action) && trim((string) $note) === '') {
                throw new HttpException(422, 'يجب توضيح السبب لتنفيذ هذا الإجراء.');
            }

            if ($action === 'propose') {
                $this->assertValidProposal($proposal);
            }

            $to = self::RESULTING_STATUS[$action];

            $this->applyStatus($requestId, $action, $to, $actorUserId, $note, $proposal);
            $this->recordHistory($requestId, $from, $to, $actorUserId, $actorType, $note);

            $this->audit->setRequest($request);
            $this->audit->logStatusChange(
                'service_request',
                $requestId,
                $from,
                $to,
                AuditLogger::CATEGORY_APPLICATION,
                (int) $serviceRequest['organization_id'],
            );

            $this->notifyTransition($serviceRequest, $to, $note);

            return ['from' => $from, 'to' => $to, 'action' => $action];
        });
    }

    // ═══════════════════ المراحل | Milestones ═══════════════════

    /** إضافة مرحلة تنفيذ | Add a delivery milestone. */
    public function addMilestone(
        int $requestId,
        int $providerOrganizationId,
        string $title,
        ?string $description,
        ?string $dueDate,
    ): int {
        $serviceRequest = $this->findForProvider($requestId, $providerOrganizationId);

        $title = trim($title);

        if ($title === '') {
            throw new HttpException(422, 'اكتب عنوان المرحلة.');
        }

        $order = (int) Database::scalar(
            'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM service_milestones WHERE service_request_id = ?',
            [$requestId],
        );

        return Database::insert(
            'INSERT INTO service_milestones
                (service_request_id, organization_id, provider_organization_id,
                 title_ar, description_ar, due_date, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $requestId,
                (int) $serviceRequest['organization_id'],
                $providerOrganizationId,
                mb_substr($title, 0, 200),
                $this->nullableText($description, 1000),
                $this->nullableDate($dueDate),
                $order,
            ],
        );
    }

    /** تحديث حالة مرحلة | Update a milestone's status. */
    public function updateMilestone(int $milestoneId, int $providerOrganizationId, string $status): void
    {
        if (!in_array($status, ['pending', 'in_progress', 'done', 'skipped'], true)) {
            throw new HttpException(422, 'حالة المرحلة غير معروفة.');
        }

        $affected = Database::affectingStatement(
            "UPDATE service_milestones
                SET status = ?, completed_at = CASE WHEN ? = 'done' THEN NOW() ELSE NULL END
              WHERE id = ? AND provider_organization_id = ?",
            [$status, $status, $milestoneId, $providerOrganizationId],
        );

        if ($affected === 0) {
            throw new HttpException(404, 'المرحلة غير موجودة.');
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function milestones(int $requestId): array
    {
        return Database::select(
            'SELECT * FROM service_milestones WHERE service_request_id = ? ORDER BY sort_order ASC, id ASC',
            [$requestId],
        );
    }

    // ═══════════════════ الوصول | Access ═══════════════════

    /** @return array<string,mixed> */
    public function findForApplicant(int $requestId, int $organizationId): array
    {
        $row = Database::selectOne(
            'SELECT r.*, o.legal_name AS provider_legal_name, o.trading_name AS provider_trading_name,
                    o.slug AS provider_slug, s.slug AS offering_slug
               FROM service_requests r
               JOIN organizations o ON o.id = r.provider_organization_id
               LEFT JOIN service_offerings s ON s.id = r.service_offering_id
              WHERE r.id = ? AND r.organization_id = ? AND r.deleted_at IS NULL
              LIMIT 1',
            [$requestId, $organizationId],
        );

        if ($row === null) {
            throw new HttpException(404, 'طلب الخدمة غير موجود.');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    public function findForProvider(int $requestId, int $providerOrganizationId): array
    {
        $row = Database::selectOne(
            'SELECT r.*, o.legal_name AS applicant_legal_name, o.trading_name AS applicant_trading_name,
                    o.slug AS applicant_slug,
                    g.name_ar AS applicant_governorate, s.name_ar AS applicant_sector
               FROM service_requests r
               JOIN organizations o ON o.id = r.organization_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN sectors s ON s.id = o.sector_id
              WHERE r.id = ? AND r.provider_organization_id = ? AND r.deleted_at IS NULL
              LIMIT 1',
            [$requestId, $providerOrganizationId],
        );

        if ($row === null) {
            throw new HttpException(404, 'طلب الخدمة غير موجود.');
        }

        return $row;
    }

    /**
     * طلبات جهة بعينها | One side's requests.
     *
     * `$side` يحدّد أي عمود يُقيَّد عليه، ولا قيمة افتراضية له: كل استدعاء
     * يصرّح بالجهة التي يقرأ نيابةً عنها.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listFor(string $side, int $organizationId, ?string $status = null, int $limit = 100): array
    {
        $column = $side === 'provider' ? 'provider_organization_id' : 'organization_id';
        $joinOn = $side === 'provider' ? 'r.organization_id' : 'r.provider_organization_id';

        $where    = ["r.{$column} = ?", 'r.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if ($status !== null && $status !== '') {
            $where[]    = 'r.status = ?';
            $bindings[] = $status;
        }

        $whereSql = implode(' AND ', $where);
        $limit    = min(200, max(1, $limit));

        return Database::select(
            "SELECT r.*, o.legal_name AS counterpart_legal_name, o.trading_name AS counterpart_trading_name
               FROM service_requests r
               JOIN organizations o ON o.id = {$joinOn}
              WHERE {$whereSql}
              ORDER BY FIELD(r.status,'submitted','provider_review','proposed','accepted',
                             'in_progress','delivered','completed','declined','cancelled'),
                       r.created_at DESC
              LIMIT {$limit}",
            $bindings,
        );
    }

    /** @return array<string,int> */
    public function countsByStatus(string $side, int $organizationId): array
    {
        $column = $side === 'provider' ? 'provider_organization_id' : 'organization_id';

        $rows = Database::select(
            "SELECT status, COUNT(*) AS total FROM service_requests
              WHERE {$column} = ? AND deleted_at IS NULL GROUP BY status",
            [$organizationId],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $requestId): array
    {
        return Database::select(
            'SELECT h.*, u.name AS actor_name
               FROM service_request_history h
               LEFT JOIN users u ON u.id = h.actor_user_id
              WHERE h.service_request_id = ?
              ORDER BY h.created_at ASC, h.id ASC',
            [$requestId],
        );
    }

    // ═══════════════════ تسميات | Labels ═══════════════════

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'submitted'       => 'مُقدَّم',
            'provider_review' => 'قيد الدراسة',
            'proposed'        => 'عرض مُقدَّم',
            'accepted'        => 'مقبول',
            'declined'        => 'غير مكتمل',
            'in_progress'     => 'قيد التنفيذ',
            'delivered'       => 'تم التسليم',
            'completed'       => 'مكتمل',
            'cancelled'       => 'ملغي',
            default           => $status,
        };
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'submitted'                    => 'np-badge--pending',
            'provider_review', 'proposed'  => 'np-badge--review',
            'accepted', 'in_progress'      => 'np-badge--info',
            'delivered', 'completed'       => 'np-badge--success',
            'declined'                     => 'np-badge--danger',
            'cancelled'                    => 'np-badge--muted',
            default                        => 'np-badge--draft',
        };
    }

    public function actionLabel(string $action): string
    {
        return match ($action) {
            'start_review'    => 'بدء الدراسة',
            'propose'         => 'إرسال العرض',
            'decline'         => 'الاعتذار عن الطلب',
            'accept'          => 'قبول العرض',
            'reject_proposal' => 'رفض العرض',
            'start_work'      => 'بدء التنفيذ',
            'deliver'         => 'تسجيل التسليم',
            'complete'        => 'تأكيد الاستلام وإغلاق الطلب',
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

        return in_array($actorType, ['applicant', 'platform'], true);
    }

    /** @param array<string,mixed> $serviceRequest */
    private function assertActorBelongs(array $serviceRequest, string $actorType, ?int $actorOrganizationId): void
    {
        if ($actorType === 'platform') {
            return;
        }

        $expected = $actorType === 'provider'
            ? (int) $serviceRequest['provider_organization_id']
            : (int) $serviceRequest['organization_id'];

        if ($actorOrganizationId !== $expected) {
            throw new HttpException(404, 'طلب الخدمة غير موجود.');
        }
    }

    /** @param array<string,mixed> $proposal */
    private function assertValidProposal(array $proposal): void
    {
        $price = $proposal['proposed_price'] ?? null;

        // السعر صفر مقبول (خدمة مجانية ضمن برنامج)، لكن غيابه أو سلبيته ليس كذلك
        if ($price === null || $price === '' || !is_numeric($price) || (float) $price < 0) {
            throw new HttpException(422, 'أدخل قيمة العرض. اكتب صفراً إن كانت الخدمة مجانية.');
        }

        if (trim((string) ($proposal['proposal_note_ar'] ?? '')) === '') {
            throw new HttpException(422, 'اشرح نطاق العمل في العرض حتى يعرف المشروع ما سيحصل عليه.');
        }
    }

    /** @param array<string,mixed> $proposal */
    private function applyStatus(
        int $requestId,
        string $action,
        string $to,
        ?int $actorUserId,
        ?string $note,
        array $proposal,
    ): void {
        if ($action === 'propose') {
            Database::statement(
                'UPDATE service_requests
                    SET status = ?, proposed_price = ?, proposed_duration_days = ?,
                        proposal_note_ar = ?, proposed_by = ?, proposed_at = NOW()
                  WHERE id = ?',
                [
                    $to,
                    number_format((float) $proposal['proposed_price'], 2, '.', ''),
                    $this->nullableInt($proposal['proposed_duration_days'] ?? null),
                    mb_substr(trim((string) $proposal['proposal_note_ar']), 0, 2000),
                    $actorUserId,
                    $requestId,
                ],
            );

            return;
        }

        if (in_array($action, ['accept', 'reject_proposal'], true)) {
            Database::statement(
                'UPDATE service_requests SET status = ?, responded_at = NOW() WHERE id = ?',
                [$to, $requestId],
            );

            return;
        }

        if ($action === 'complete') {
            Database::statement(
                'UPDATE service_requests SET status = ?, completed_at = NOW() WHERE id = ?',
                [$to, $requestId],
            );

            return;
        }

        Database::statement('UPDATE service_requests SET status = ? WHERE id = ?', [$to, $requestId]);
    }

    private function recordHistory(
        int $requestId,
        ?string $from,
        string $to,
        ?int $actorUserId,
        string $actorType,
        ?string $note = null,
    ): void {
        Database::statement(
            'INSERT INTO service_request_history
                (service_request_id, from_status, to_status, actor_user_id, actor_type, note_ar)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $requestId,
                $from,
                $to,
                $actorUserId,
                in_array($actorType, ['applicant', 'platform', 'provider', 'system'], true)
                    ? $actorType : 'system',
                $note === null ? null : mb_substr($note, 0, 1000),
            ],
        );
    }

    /** @param array<string,mixed> $serviceRequest */
    private function notifyTransition(array $serviceRequest, string $to, ?string $note): void
    {
        $number  = (string) $serviceRequest['request_number'];
        $suffix  = $note !== null && trim($note) !== '' ? ' — ' . $note : '';

        // الجهة المُشعَرة هي الطرف الآخر دائماً | Always notify the counterpart
        $toApplicant = in_array($to, ['provider_review', 'proposed', 'declined', 'in_progress', 'delivered'], true);

        $this->notifications->notifyOrganizationMembers(
            organizationId: $toApplicant
                ? (int) $serviceRequest['organization_id']
                : (int) $serviceRequest['provider_organization_id'],
            type: 'service_request.' . $to,
            title: 'تحديث على طلب الخدمة ' . $number,
            body: $this->transitionMessage($to) . $suffix,
            severity: match ($to) {
                'completed', 'delivered' => 'success',
                'declined', 'cancelled'  => 'warning',
                default                  => 'info',
            },
            actionUrl: url(($toApplicant ? '/app/services/my-requests/' : '/app/services/requests/')
                . $serviceRequest['id']),
            actionLabel: 'عرض الطلب',
            entityType: 'service_request',
            entityId: (int) $serviceRequest['id'],
        );
    }

    private function transitionMessage(string $to): string
    {
        return match ($to) {
            'provider_review' => 'بدأ مقدّم الخدمة دراسة طلبك.',
            'proposed'        => 'وصلك عرض من مقدّم الخدمة.',
            'declined'        => 'لم يكتمل الطلب.',
            'accepted'        => 'قبل المشروع العرض.',
            'in_progress'     => 'بدأ تنفيذ الخدمة.',
            'delivered'       => 'سجّل مقدّم الخدمة التسليم بانتظار تأكيدك.',
            'completed'       => 'أُغلق الطلب بعد تأكيد الاستلام.',
            'cancelled'       => 'أُلغي الطلب.',
            default           => 'تغيّرت حالة الطلب.',
        };
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
