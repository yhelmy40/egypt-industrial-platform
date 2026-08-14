<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\PipelineRepository;

/**
 * خدمة المهتمّين والفرص والأنشطة | Lead, opportunity and activity service (§4.9).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ثلاث قواعد تحكم هذه الوحدة:
 *
 *  1. **المهتمّ ليس عميلاً حتى يُحوَّل.** خلطهما يجعل «عدد العملاء» رقماً بلا
 *     معنى، وهو رقم يبني عليه صاحب المشروع قراره بالتوسّع أو الانكماش.
 *  2. **التحويل مرة واحدة.** مهتمّ حُوِّل مرتين يُنتج عميلين لجهة واحدة،
 *     فتنقسم مستحقّاته وتاريخه بين ملفّين.
 *  3. **الخسارة تستوجب سبباً.** فرصة تُغلق خاسرة بلا سبب لا تُعلّم صاحب
 *     المشروع شيئاً، وتقرير «أسباب الخسارة» يصير قائمة فارغة.
 *
 * والقيمة المتوقّعة والاحتمال **تقديرات صاحب المشروع**: المنصة لا تتنبّأ ولا
 * تحسب احتمالاً نيابةً عنه.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class PipelineService
{
    /** انتقالات مراحل الفرصة | Opportunity stage transitions. */
    private const STAGE_FLOW = [
        'new'         => ['qualified', 'lost'],
        'qualified'   => ['proposal', 'lost'],
        'proposal'    => ['negotiation', 'won', 'lost'],
        'negotiation' => ['won', 'lost'],
        'won'         => [],
        'lost'        => ['new'],
    ];

    public function __construct(
        private readonly PipelineRepository $pipeline = new PipelineRepository(),
        private readonly CustomerService $customers = new CustomerService(),
    ) {
    }

    // ═══════════════════ المهتمّون | Leads ═══════════════════

    /** @param array<string,mixed> $data */
    public function createLead(int $organizationId, array $data, ?int $actorUserId): int
    {
        $name = trim((string) ($data['name_ar'] ?? ''));

        if ($name === '') {
            throw new HttpException(422, 'اكتب اسم المهتمّ.');
        }

        $source = (string) ($data['source'] ?? 'other');

        if (!in_array($source, ['marketplace', 'referral', 'walk_in', 'social', 'event', 'other'], true)) {
            $source = 'other';
        }

        return Database::insert(
            'INSERT INTO crm_leads
                (organization_id, name_ar, phone, email, interest_ar, source,
                 source_enquiry_id, status, assigned_to)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $organizationId,
                mb_substr($name, 0, 200),
                $this->text($data['phone'] ?? null, 30),
                $this->text($data['email'] ?? null, 190),
                $this->text($data['interest_ar'] ?? null, 1000),
                $source,
                $this->resolveEnquiry($organizationId, $data['source_enquiry_id'] ?? null),
                'new',
                $this->resolveMember($organizationId, $data['assigned_to'] ?? $actorUserId),
            ],
        );
    }

    /** تحديث حالة المهتمّ | Update a lead's status. */
    public function updateLeadStatus(int $leadId, int $organizationId, string $status, ?string $reason): void
    {
        if (!in_array($status, ['new', 'contacted', 'qualified', 'lost'], true)) {
            throw new HttpException(
                422,
                $status === 'converted'
                    ? 'التحويل يتمّ بإنشاء عميل من المهتمّ، لا بتغيير حالته يدوياً.'
                    : 'حالة غير معروفة.',
            );
        }

        $lead = $this->requireLead($leadId, $organizationId);

        if ((string) $lead['status'] === 'converted') {
            throw new HttpException(422, 'هذا المهتمّ حُوِّل إلى عميل بالفعل.');
        }

        if ($status === 'lost' && trim((string) $reason) === '') {
            throw new HttpException(422, 'اكتب سبب فقدان المهتمّ.');
        }

        Database::statement(
            'UPDATE crm_leads SET status = ?, lost_reason_ar = ?, updated_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [
                $status,
                $status === 'lost' ? mb_substr(trim((string) $reason), 0, 500) : null,
                $leadId,
                $organizationId,
            ],
        );
    }

    /**
     * تحويل مهتمّ إلى عميل | Convert a lead into a customer.
     *
     * ينشئ العميل ويربطه بالمهتمّ في معاملة واحدة، فلا يبقى مهتمّ «محوَّل» بلا
     * عميل ولا عميل بلا أثر لمصدره.
     *
     * @param  array<string,mixed> $overrides
     * @return int معرّف العميل | the new customer's id
     */
    public function convertLead(int $leadId, int $organizationId, array $overrides = []): int
    {
        $lead = $this->requireLead($leadId, $organizationId);

        if ((string) $lead['status'] === 'converted') {
            throw new HttpException(
                422,
                'هذا المهتمّ حُوِّل إلى عميل بالفعل. التحويل مرة أخرى يُنتج ملفّين لجهة واحدة.',
            );
        }

        if ((string) $lead['status'] === 'lost') {
            throw new HttpException(422, 'المهتمّ مسجَّل كمفقود. أعد حالته أولاً إن عاد التواصل.');
        }

        return Database::transaction(function () use ($lead, $leadId, $organizationId, $overrides): int {
            $customerId = $this->customers->create($organizationId, array_merge([
                'name_ar'  => (string) $lead['name_ar'],
                'phone'    => $lead['phone'],
                'email'    => $lead['email'],
                'source'   => (string) $lead['source'],
                'notes_ar' => $lead['interest_ar'],
            ], $overrides));

            Database::statement(
                "UPDATE crm_leads
                    SET status = 'converted', converted_customer_id = ?, converted_at = NOW(),
                        updated_at = NOW()
                  WHERE id = ? AND organization_id = ?",
                [$customerId, $leadId, $organizationId],
            );

            // الأنشطة تنتقل مع العلاقة: تاريخ التواصل لا يبدأ من الصفر عند البيع
            Database::statement(
                'UPDATE crm_activities SET customer_id = ?
                  WHERE lead_id = ? AND organization_id = ? AND customer_id IS NULL',
                [$customerId, $leadId, $organizationId],
            );

            return $customerId;
        });
    }

    // ═══════════════════ الفرص | Opportunities ═══════════════════

    /** @param array<string,mixed> $data */
    public function createOpportunity(int $organizationId, array $data, ?int $actorUserId): int
    {
        $customerId = $this->requireCustomerId($organizationId, $data['customer_id'] ?? null);
        $title      = trim((string) ($data['title_ar'] ?? ''));

        if ($title === '') {
            throw new HttpException(422, 'اكتب عنوان الفرصة.');
        }

        return Database::insert(
            'INSERT INTO crm_opportunities
                (organization_id, customer_id, title_ar, description_ar, stage,
                 expected_value, probability, expected_close_date, assigned_to)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $organizationId,
                $customerId,
                mb_substr($title, 0, 200),
                $this->text($data['description_ar'] ?? null, 2000),
                'new',
                $this->decimal($data['expected_value'] ?? null),
                $this->probability($data['probability'] ?? null),
                $this->date($data['expected_close_date'] ?? null),
                $this->resolveMember($organizationId, $data['assigned_to'] ?? $actorUserId),
            ],
        );
    }

    /** @param array<string,mixed> $data */
    public function updateOpportunity(int $opportunityId, int $organizationId, array $data): void
    {
        $opportunity = $this->requireOpportunity($opportunityId, $organizationId);

        if (in_array((string) $opportunity['stage'], ['won', 'lost'], true)) {
            throw new HttpException(
                422,
                'الفرصة مغلقة. أعد فتحها قبل تعديل بياناتها.',
            );
        }

        $title = trim((string) ($data['title_ar'] ?? ''));

        if ($title === '') {
            throw new HttpException(422, 'اكتب عنوان الفرصة.');
        }

        Database::statement(
            'UPDATE crm_opportunities
                SET title_ar = ?, description_ar = ?, expected_value = ?, probability = ?,
                    expected_close_date = ?, assigned_to = ?, updated_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [
                mb_substr($title, 0, 200),
                $this->text($data['description_ar'] ?? null, 2000),
                $this->decimal($data['expected_value'] ?? null),
                $this->probability($data['probability'] ?? null),
                $this->date($data['expected_close_date'] ?? null),
                $this->resolveMember($organizationId, $data['assigned_to'] ?? null),
                $opportunityId,
                $organizationId,
            ],
        );
    }

    /**
     * نقل الفرصة لمرحلة | Move an opportunity to a stage.
     *
     * الانتقالات محكومة: القفز من «جديدة» إلى «مكسوبة» يجعل خطّ الفرص عديم
     * الدلالة، ويُخفي أين تتعثّر الصفقات فعلاً.
     */
    public function moveStage(
        int $opportunityId,
        int $organizationId,
        string $stage,
        ?string $reason = null,
    ): void {
        if (!in_array($stage, PipelineRepository::STAGES, true)) {
            throw new HttpException(422, 'مرحلة غير معروفة.');
        }

        $opportunity = $this->requireOpportunity($opportunityId, $organizationId);
        $from        = (string) $opportunity['stage'];

        if ($from === $stage) {
            return;
        }

        if (!in_array($stage, self::STAGE_FLOW[$from] ?? [], true)) {
            throw new HttpException(
                422,
                'لا يمكن نقل الفرصة من «' . $this->stageLabel($from) . '» إلى «'
                . $this->stageLabel($stage) . '» مباشرةً.',
            );
        }

        if ($stage === 'lost' && trim((string) $reason) === '') {
            throw new HttpException(
                422,
                'اكتب سبب الخسارة. فرصة تُغلق بلا سبب لا تُعلّمك شيئاً للمرة القادمة.',
            );
        }

        $closing = in_array($stage, ['won', 'lost'], true);

        Database::statement(
            'UPDATE crm_opportunities
                SET stage = ?,
                    close_reason_ar = ?,
                    closed_at = ' . ($closing ? 'NOW()' : 'NULL') . ',
                    updated_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [
                $stage,
                $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500),
                $opportunityId,
                $organizationId,
            ],
        );
    }

    // ═══════════════════ الأنشطة والمهام | Activities and tasks ═══════════════════

    /**
     * تسجيل نشاط أو مهمة | Log an activity or plan a task.
     *
     * النشاط المنجز يحمل `occurred_at`، والمهمة المخطَّطة تحمل `due_at`. مهمة
     * بلا موعد لا تُذكّر بشيء فتُرفض.
     *
     * @param array<string,mixed> $data
     */
    public function logActivity(int $organizationId, array $data, ?int $actorUserId): int
    {
        $subject = trim((string) ($data['subject_ar'] ?? ''));

        if ($subject === '') {
            throw new HttpException(422, 'اكتب موضوع النشاط.');
        }

        $type = (string) ($data['activity_type'] ?? 'note');

        if (!in_array($type, ['call', 'visit', 'meeting', 'email', 'message', 'note', 'task'], true)) {
            $type = 'note';
        }

        $status = (string) ($data['status'] ?? 'done');

        if (!in_array($status, ['planned', 'done', 'cancelled'], true)) {
            $status = 'done';
        }

        $dueAt = $this->timestamp($data['due_at'] ?? null);

        if ($status === 'planned' && $dueAt === null) {
            throw new HttpException(422, 'حدّد موعد المهمة. مهمة بلا موعد لا تذكّر بشيء.');
        }

        $links = $this->resolveLinks($organizationId, $data);

        if ($links['customer_id'] === null && $links['lead_id'] === null && $links['opportunity_id'] === null) {
            throw new HttpException(422, 'اربط النشاط بعميل أو مهتمّ أو فرصة.');
        }

        return Database::insert(
            'INSERT INTO crm_activities
                (organization_id, customer_id, lead_id, opportunity_id, activity_type,
                 subject_ar, body_ar, occurred_at, due_at, status, completed_at,
                 assigned_to, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $organizationId,
                $links['customer_id'],
                $links['lead_id'],
                $links['opportunity_id'],
                $type,
                mb_substr($subject, 0, 200),
                $this->text($data['body_ar'] ?? null, 2000),
                $status === 'done'
                    ? ($this->timestamp($data['occurred_at'] ?? null) ?? date('Y-m-d H:i:s'))
                    : null,
                $dueAt,
                $status,
                $status === 'done' ? date('Y-m-d H:i:s') : null,
                $this->resolveMember($organizationId, $data['assigned_to'] ?? $actorUserId),
                $actorUserId,
            ],
        );
    }

    /** إنهاء مهمة | Complete a planned task. */
    public function completeActivity(int $activityId, int $organizationId, ?string $outcome): void
    {
        $activity = $this->pipeline->findActivity($activityId, $organizationId);

        if ($activity === null) {
            throw new HttpException(404, 'النشاط غير موجود.');
        }

        if ((string) $activity['status'] !== 'planned') {
            throw new HttpException(422, 'هذه المهمة ليست قيد التنفيذ.');
        }

        Database::statement(
            "UPDATE crm_activities
                SET status = 'done', completed_at = NOW(), occurred_at = NOW(),
                    body_ar = COALESCE(NULLIF(?, ''), body_ar), updated_at = NOW()
              WHERE id = ? AND organization_id = ?",
            [trim((string) $outcome), $activityId, $organizationId],
        );
    }

    /** إلغاء مهمة | Cancel a planned task. */
    public function cancelActivity(int $activityId, int $organizationId): void
    {
        $affected = Database::affectingStatement(
            "UPDATE crm_activities SET status = 'cancelled', updated_at = NOW()
              WHERE id = ? AND organization_id = ? AND status = 'planned' AND deleted_at IS NULL",
            [$activityId, $organizationId],
        );

        if ($affected === 0) {
            throw new HttpException(404, 'المهمة غير موجودة أو ليست قيد التنفيذ.');
        }
    }

    // ═══════════════════ أدوات | Helpers ═══════════════════

    /**
     * @param  array<string,mixed> $data
     * @return array{customer_id:?int,lead_id:?int,opportunity_id:?int}
     */
    private function resolveLinks(int $organizationId, array $data): array
    {
        $customerId    = $this->nullableInt($data['customer_id'] ?? null);
        $leadId        = $this->nullableInt($data['lead_id'] ?? null);
        $opportunityId = $this->nullableInt($data['opportunity_id'] ?? null);

        if ($customerId !== null) {
            $this->requireCustomerId($organizationId, $customerId);
        }

        if ($leadId !== null) {
            $this->requireLead($leadId, $organizationId);
        }

        if ($opportunityId !== null) {
            $opportunity = $this->requireOpportunity($opportunityId, $organizationId);
            // النشاط على فرصة يخصّ عميلها ضمناً
            $customerId ??= (int) $opportunity['customer_id'];
        }

        return [
            'customer_id'    => $customerId,
            'lead_id'        => $leadId,
            'opportunity_id' => $opportunityId,
        ];
    }

    /** @return array<string,mixed> */
    private function requireLead(int $leadId, int $organizationId): array
    {
        $lead = $this->pipeline->findLead($leadId, $organizationId);

        if ($lead === null) {
            throw new HttpException(404, 'المهتمّ غير موجود.');
        }

        return $lead;
    }

    /** @return array<string,mixed> */
    private function requireOpportunity(int $opportunityId, int $organizationId): array
    {
        $opportunity = $this->pipeline->findOpportunity($opportunityId, $organizationId);

        if ($opportunity === null) {
            throw new HttpException(404, 'الفرصة غير موجودة.');
        }

        return $opportunity;
    }

    private function requireCustomerId(int $organizationId, mixed $customerId): int
    {
        $customerId = $this->nullableInt($customerId);

        if ($customerId === null) {
            throw new HttpException(422, 'اختر العميل.');
        }

        $exists = Database::scalar(
            'SELECT 1 FROM crm_customers
              WHERE id = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1',
            [$customerId, $organizationId],
        );

        if ($exists === null) {
            throw new HttpException(404, 'العميل غير موجود في مشروعك.');
        }

        return $customerId;
    }

    /** الإسناد لعضو في المنشأة نفسها | Assignment stays within the organization. */
    private function resolveMember(int $organizationId, mixed $userId): ?int
    {
        $userId = $this->nullableInt($userId);

        if ($userId === null) {
            return null;
        }

        $isMember = Database::scalar(
            "SELECT 1 FROM organization_members
              WHERE user_id = ? AND organization_id = ? AND status = 'active' LIMIT 1",
            [$userId, $organizationId],
        );

        return $isMember === null ? null : $userId;
    }

    private function resolveEnquiry(int $organizationId, mixed $enquiryId): ?int
    {
        $enquiryId = $this->nullableInt($enquiryId);

        if ($enquiryId === null) {
            return null;
        }

        $owns = Database::scalar(
            'SELECT 1 FROM customer_enquiries WHERE id = ? AND organization_id = ? LIMIT 1',
            [$enquiryId, $organizationId],
        );

        return $owns === null ? null : $enquiryId;
    }

    private function probability(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) $value));
    }

    private function decimal(mixed $value): ?float
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : round((float) $value, 2);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (int) $value;
    }

    private function text(mixed $value, int $length): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : date('Y-m-d', $time);
    }

    private function timestamp(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    // ═══════════════════ تسميات | Labels ═══════════════════

    public function stageLabel(string $stage): string
    {
        return match ($stage) {
            'new'         => 'جديدة',
            'qualified'   => 'مؤهَّلة',
            'proposal'    => 'عرض مقدَّم',
            'negotiation' => 'تفاوض',
            'won'         => 'مكسوبة',
            'lost'        => 'خاسرة',
            default       => $stage,
        };
    }

    public function stageBadgeClass(string $stage): string
    {
        return match ($stage) {
            'new'         => 'np-badge--draft',
            'qualified'   => 'np-badge--pending',
            'proposal'    => 'np-badge--review',
            'negotiation' => 'np-badge--info',
            'won'         => 'np-badge--success',
            'lost'        => 'np-badge--muted',
            default       => 'np-badge--draft',
        };
    }

    public function leadStatusLabel(string $status): string
    {
        return match ($status) {
            'new'       => 'جديد',
            'contacted' => 'جرى التواصل',
            'qualified' => 'مؤهَّل',
            'converted' => 'حُوِّل إلى عميل',
            'lost'      => 'مفقود',
            default     => $status,
        };
    }

    public function activityTypeLabel(string $type): string
    {
        return match ($type) {
            'call'    => 'مكالمة',
            'visit'   => 'زيارة',
            'meeting' => 'اجتماع',
            'email'   => 'بريد',
            'message' => 'رسالة',
            'task'    => 'مهمة',
            default   => 'ملاحظة',
        };
    }

    /** المراحل المتاحة من مرحلة | Stages reachable from a given stage. */
    public function nextStages(string $stage): array
    {
        return self::STAGE_FLOW[$stage] ?? [];
    }
}
