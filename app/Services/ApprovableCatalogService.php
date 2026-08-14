<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;

/**
 * أساس الكتالوجات الخاضعة للاعتماد | Base for approval-gated catalogues (§4.5, §4.6).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * المنتجات التمويلية وباقات الخدمات تشتركان في قاعدة واحدة: **المزوّد يُنشئ
 * ويرسل، والمنصة تعتمد.** لا يظهر أيٌّ منهما للعامة قبل قرار موثّق بفاعله
 * وتاريخه، والرفض يستوجب سبباً يصل للمزوّد.
 *
 * كُتبت الآلية مرة واحدة هنا بدل نسخها مرتين، لأن تكرار منطق الاعتماد يعني
 * أن أي تشديد مستقبلي (خطوة مراجعة إضافية، مهلة، تسجيل أدقّ) قد يُطبَّق على
 * كتالوج وينسى الآخر — وهو بالضبط نوع الخلل الذي يمرّ صامتاً.
 *
 * Financing products and service packages share one rule: the provider drafts
 * and submits, the platform approves. Written once rather than twice, because
 * duplicated approval logic is exactly the kind that gets tightened in one
 * place and silently forgotten in the other.
 * ═══════════════════════════════════════════════════════════════════════════
 */
abstract class ApprovableCatalogService
{
    public function __construct(
        protected readonly AuditLogger $audit = new AuditLogger(),
        protected readonly NotificationService $notifications = new NotificationService(),
    ) {
    }

    /** اسم الجدول | The catalogue table. */
    abstract protected function table(): string;

    /** نوع الكيان في سجل التدقيق | Entity type for the audit trail. */
    abstract protected function entityType(): string;

    /** التسمية العربية المفردة | Singular Arabic label, e.g. «المنتج التمويلي». */
    abstract protected function label(): string;

    /** رابط إدارة العنصر في مساحة عمل المزوّد | Provider-side URL. */
    abstract protected function providerUrl(int $id): string;

    /** رابط طابور المراجعة الإداري | The admin moderation queue URL. */
    abstract protected function moderationUrl(): string;

    /** الصلاحية التي تحدّد من يُشعَر بالطابور | Permission defining the reviewer audience. */
    abstract protected function moderationPermission(): string;

    /**
     * ما يجب اكتماله قبل الإرسال للاعتماد | Completeness gate before submission.
     *
     * @param  array<string,mixed> $row
     * @return array<int,string> أسماء الحقول الناقصة
     */
    abstract protected function missingForSubmission(array $row): array;

    // ═══════════════════ الإرسال للاعتماد | Submission ═══════════════════

    /**
     * إرسال العنصر للاعتماد | Submit for platform approval.
     *
     * لا يُنشر شيء هنا مهما كانت الإعدادات: الاعتماد شرط ثابت على المنتجات
     * التمويلية وباقات الخدمات، لأن كليهما التزام تجاري تجاه صاحب مشروع.
     */
    public function submitForApproval(
        int $id,
        int $organizationId,
        ?int $actorId,
        Request $request,
    ): void {
        $row = $this->findOwned($id, $organizationId);

        if (!in_array($row['status'], ['draft', 'rejected'], true)) {
            throw new HttpException(
                422,
                'لا يمكن إرسال ' . $this->label() . ' للاعتماد في حالته الحالية.',
            );
        }

        $this->assertProviderVerified($organizationId);

        $missing = $this->missingForSubmission($row);

        if ($missing !== []) {
            throw new HttpException(
                422,
                'أكمل الحقول التالية قبل الإرسال: ' . implode('، ', $missing) . '.',
            );
        }

        Database::transaction(function () use ($id, $organizationId, $row, $actorId, $request): void {
            Database::statement(
                "UPDATE `{$this->table()}`
                    SET status = 'pending_review', moderation_note = NULL
                  WHERE id = ? AND organization_id = ?",
                [$id, $organizationId],
            );

            $this->audit->setRequest($request);
            $this->audit->logStatusChange(
                $this->entityType(),
                $id,
                (string) $row['status'],
                'pending_review',
                AuditLogger::CATEGORY_RECORD,
                $organizationId,
            );
        });

        $this->notifications->notifyPlatformReviewers(
            type: $this->entityType() . '.pending_review',
            title: $this->label() . ' بانتظار الاعتماد',
            body: '«' . $row['name_ar'] . '» بانتظار مراجعة فريق المنصة قبل الظهور للعامة.',
            actionUrl: url($this->moderationUrl()),
            organizationId: $organizationId,
            entityType: $this->entityType(),
            entityId: $id,
            permission: $this->moderationPermission(),
        );
    }

    // ═══════════════════ قرار المنصة | The platform decision ═══════════════════

    /**
     * اعتماد أو رفض | Approve or reject.
     *
     * الرفض يستوجب سبباً: قرار بلا سبب يترك المزوّد بلا طريق للتصحيح، ويحوّل
     * المراجعة إلى بوابة غامضة.
     */
    public function moderate(
        int $id,
        string $decision,
        ?string $note,
        int $moderatorId,
        Request $request,
    ): void {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new HttpException(422, 'قرار المراجعة غير معروف.');
        }

        if ($decision === 'reject' && trim((string) $note) === '') {
            throw new HttpException(422, 'يجب توضيح سبب الرفض.');
        }

        $row = $this->findAnywhere($id);

        if ($row['status'] !== 'pending_review') {
            throw new HttpException(
                422,
                'هذا العنصر ليس بانتظار الاعتماد، فلا قرار مطلوب عليه.',
            );
        }

        $organizationId = (int) $row['organization_id'];
        $target         = $decision === 'approve' ? 'published' : 'rejected';

        Database::transaction(function () use (
            $id, $target, $note, $moderatorId, $row, $organizationId, $request
        ): void {
            // `approved_by` و`approved_at` يُملآن عند الاعتماد فقط، ويُمسحان عند
            // الرفض: بقاء ختم اعتماد قديم على عنصر مرفوض يجعله يبدو معتمداً لأي
            // استعلام يعتمد على العمود.
            Database::statement(
                "UPDATE `{$this->table()}`
                    SET status = ?,
                        moderation_note = ?,
                        approved_by = CASE WHEN ? = 'published' THEN ? ELSE NULL END,
                        approved_at = CASE WHEN ? = 'published' THEN NOW() ELSE NULL END,
                        published_at = CASE WHEN ? = 'published' THEN NOW() ELSE NULL END
                  WHERE id = ?",
                [
                    $target,
                    $note === null ? null : mb_substr($note, 0, 1000),
                    $target, $moderatorId,
                    $target,
                    $target,
                    $id,
                ],
            );

            $this->audit->setRequest($request);
            $this->audit->log(
                action: $this->entityType() . '.' . $target,
                category: AuditLogger::CATEGORY_RECORD,
                entityType: $this->entityType(),
                entityId: $id,
                changes: ['before' => ['status' => $row['status']], 'after' => ['status' => $target]],
                description: ($target === 'published' ? 'اعتماد ' : 'رفض ') . $this->label()
                    . ': ' . $row['name_ar'],
                severity: $target === 'rejected' ? 'warning' : 'notice',
                userId: $moderatorId,
                organizationId: $organizationId,
            );
        });

        $this->notifications->notifyOrganizationMembers(
            organizationId: $organizationId,
            type: $this->entityType() . '.' . $target,
            title: $target === 'published'
                ? 'تم اعتماد ' . $this->label()
                : 'لم يُعتمد ' . $this->label(),
            body: $target === 'published'
                ? '«' . $row['name_ar'] . '» أصبح ظاهراً للمشروعات.'
                : '«' . $row['name_ar'] . '» لم يُعتمد. السبب: ' . (string) $note,
            severity: $target === 'published' ? 'success' : 'warning',
            actionUrl: url($this->providerUrl($id)),
            entityType: $this->entityType(),
            entityId: $id,
        );
    }

    /** سحب عنصر منشور | Withdraw a published item. */
    public function archive(int $id, int $organizationId, ?int $actorId, Request $request): void
    {
        $row = $this->findOwned($id, $organizationId);

        Database::statement(
            "UPDATE `{$this->table()}` SET status = 'archived' WHERE id = ? AND organization_id = ?",
            [$id, $organizationId],
        );

        $this->audit->setRequest($request);
        $this->audit->logStatusChange(
            $this->entityType(),
            $id,
            (string) $row['status'],
            'archived',
            AuditLogger::CATEGORY_RECORD,
            $organizationId,
        );
    }

    // ═══════════════════ تسميات | Labels ═══════════════════

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'          => 'مسودة',
            'pending_review' => 'بانتظار الاعتماد',
            'published'      => 'معتمد ومنشور',
            'rejected'       => 'مرفوض',
            'archived'       => 'مسحوب',
            default          => $status,
        };
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'pending_review' => 'np-badge--pending',
            'published'      => 'np-badge--success',
            'rejected'       => 'np-badge--danger',
            'archived'       => 'np-badge--muted',
            default          => 'np-badge--draft',
        };
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /**
     * جلب عنصر مملوك للمنشأة | Fetch an item owned by this organization.
     *
     * القيد على `organization_id` في نفس الاستعلام، والنتيجة الفارغة تعني 404 لا
     * 403: منشأة أخرى تخمّن المعرّف لا يجوز أن تتأكد من وجوده.
     *
     * @return array<string,mixed>
     */
    protected function findOwned(int $id, int $organizationId): array
    {
        $row = Database::selectOne(
            "SELECT * FROM `{$this->table()}`
              WHERE id = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1",
            [$id, $organizationId],
        );

        if ($row === null) {
            throw new HttpException(404, $this->label() . ' المطلوب غير موجود.');
        }

        return $row;
    }

    /**
     * جلب عنصر بلا قيد منشأة | Fetch across organizations.
     *
     * مقصور على مسار المراجعة الإدارية، وهو عابر للمنشآت بحكم وظيفته.
     *
     * @return array<string,mixed>
     */
    protected function findAnywhere(int $id): array
    {
        $row = Database::selectOne(
            "SELECT * FROM `{$this->table()}` WHERE id = ? AND deleted_at IS NULL LIMIT 1",
            [$id],
        );

        if ($row === null) {
            throw new HttpException(404, $this->label() . ' المطلوب غير موجود.');
        }

        return $row;
    }

    /**
     * المزوّد نفسه يجب أن يكون موثّقاً | The provider must itself be verified.
     *
     * منتج تمويلي أو باقة خدمة من جهة لم تُوثَّق يمنحها ظهوراً وثقة لم تُراجَع،
     * وهو ما تحديداً يجب ألّا تمنحه المنصة.
     */
    protected function assertProviderVerified(int $organizationId): void
    {
        $status = Database::scalar(
            'SELECT status FROM organizations WHERE id = ? AND deleted_at IS NULL',
            [$organizationId],
        );

        if ($status !== 'verified') {
            throw new HttpException(
                422,
                'لا يمكن عرض ' . $this->label() . ' قبل توثيق حساب الجهة. أكمل ملف التوثيق أولاً.',
            );
        }
    }

    /** رابط فريد مشتقّ من الاسم | A unique slug derived from the name. */
    protected function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = trim(preg_replace('/[^\p{Arabic}\p{L}\p{N}]+/u', '-', $name) ?? '', '-');
        $base = $base === '' ? 'item' : mb_substr($base, 0, 190);

        $slug     = $base;
        $attempt  = 1;

        while (true) {
            $sql      = "SELECT id FROM `{$this->table()}` WHERE slug = ?";
            $bindings = [$slug];

            if ($ignoreId !== null) {
                $sql       .= ' AND id != ?';
                $bindings[] = $ignoreId;
            }

            if (Database::selectOne($sql . ' LIMIT 1', $bindings) === null) {
                return $slug;
            }

            $attempt++;
            $slug = $base . '-' . $attempt;
        }
    }
}
