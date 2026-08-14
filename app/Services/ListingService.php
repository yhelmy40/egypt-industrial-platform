<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Repositories\ListingRepository;
use App\Repositories\OrganizationRepository;

/**
 * إدارة الإعلانات | Listing management (§4.4).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * دورة حياة الإعلان:
 *   draft ──submit──► pending_review ──approve──► published
 *                            │                        │
 *                            └──reject──► rejected    └──archive──► archived
 *
 * شرطان قبل النشر (§4.4، إعدادات المنصة):
 *  1. المنشأة موثّقة — منصة تعرض بضاعة منشأة غير موثّقة تُخاطر بثقة العميل.
 *  2. مراجعة الإدارة إن كانت مفعّلة في الإعدادات.
 * كلاهما قابل للتعطيل من إعدادات المنصة دون تعديل كود.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class ListingService
{
    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * إنشاء إعلان | Create a listing as a draft.
     *
     * @param array<string,mixed> $data
     */
    public function create(int $organizationId, array $data, ?int $actorId, Request $request): int
    {
        $this->assertValidPricing($data);

        $name = mb_substr(trim((string) ($data['name_ar'] ?? '')), 0, 200);

        $listingId = Database::transaction(function () use ($organizationId, $data, $name, $actorId, $request): int {
            $id = $this->listings->scopedToTenant($organizationId)->create([
                'category_id'        => $this->nullableInt($data['category_id'] ?? null),
                'listing_type'       => in_array($data['listing_type'] ?? '', ['product', 'service'], true)
                    ? $data['listing_type'] : 'product',
                'name_ar'            => $name,
                'name_en'            => $this->nullable($data['name_en'] ?? null, 200),
                'slug'               => $this->listings->generateUniqueSlug($name),
                'short_description'  => $this->nullable($data['short_description'] ?? null, 500),
                'description'        => $this->nullable($data['description'] ?? null, 20000),
                'pricing_mode'       => ($data['pricing_mode'] ?? 'fixed') === 'quote' ? 'quote' : 'fixed',
                'price'              => ($data['pricing_mode'] ?? 'fixed') === 'quote'
                    ? null : $this->nullableDecimal($data['price'] ?? null),
                'vat_included'       => !empty($data['vat_included']) ? 1 : 0,
                'vat_rate'           => (float) ($data['vat_rate'] ?? SettingsService::decimal('marketplace', 'default_vat_rate', 14.0)),
                'sku'                => $this->nullable($data['sku'] ?? null, 60),
                'unit_of_measure'    => $this->nullable($data['unit_of_measure'] ?? null, 40),
                'available_quantity' => $this->nullableDecimal($data['available_quantity'] ?? null),
                'track_inventory'    => !empty($data['track_inventory']) ? 1 : 0,
                'min_order_quantity' => max(0.001, (float) ($data['min_order_quantity'] ?? 1)),
                'lead_time_days'     => $this->nullableInt($data['lead_time_days'] ?? null),
                'delivery_area'      => $this->nullable($data['delivery_area'] ?? null, 300),
                'delivery_fee'       => $this->nullableDecimal($data['delivery_fee'] ?? null),
                'status'             => 'draft',
            ]);

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'listing.created',
                category: AuditLogger::CATEGORY_RECORD,
                entityType: 'listing',
                entityId: $id,
                description: 'إضافة إعلان: ' . $name,
                userId: $actorId,
                organizationId: $organizationId,
            );

            return $id;
        });

        return $listingId;
    }

    /** @param array<string,mixed> $data */
    public function update(int $listingId, int $organizationId, array $data, ?int $actorId, Request $request): void
    {
        $listing = $this->listings->scopedToTenant($organizationId)->findOrFail($listingId);

        $this->assertValidPricing($data);

        $name   = mb_substr(trim((string) ($data['name_ar'] ?? $listing['name_ar'])), 0, 200);
        $update = [
            'category_id'        => $this->nullableInt($data['category_id'] ?? null),
            'listing_type'       => in_array($data['listing_type'] ?? '', ['product', 'service'], true)
                ? $data['listing_type'] : $listing['listing_type'],
            'name_ar'            => $name,
            'name_en'            => $this->nullable($data['name_en'] ?? null, 200),
            'short_description'  => $this->nullable($data['short_description'] ?? null, 500),
            'description'        => $this->nullable($data['description'] ?? null, 20000),
            'pricing_mode'       => ($data['pricing_mode'] ?? 'fixed') === 'quote' ? 'quote' : 'fixed',
            'price'              => ($data['pricing_mode'] ?? 'fixed') === 'quote'
                ? null : $this->nullableDecimal($data['price'] ?? null),
            'vat_included'       => !empty($data['vat_included']) ? 1 : 0,
            'vat_rate'           => (float) ($data['vat_rate'] ?? $listing['vat_rate']),
            'sku'                => $this->nullable($data['sku'] ?? null, 60),
            'unit_of_measure'    => $this->nullable($data['unit_of_measure'] ?? null, 40),
            'available_quantity' => $this->nullableDecimal($data['available_quantity'] ?? null),
            'track_inventory'    => !empty($data['track_inventory']) ? 1 : 0,
            'min_order_quantity' => max(0.001, (float) ($data['min_order_quantity'] ?? 1)),
            'lead_time_days'     => $this->nullableInt($data['lead_time_days'] ?? null),
            'delivery_area'      => $this->nullable($data['delivery_area'] ?? null, 300),
            'delivery_fee'       => $this->nullableDecimal($data['delivery_fee'] ?? null),
        ];

        // تعديل إعلان منشور يُعيده للمراجعة إن كانت المراجعة مفعّلة — وإلا لأمكن
        // نشر محتوى مقبول ثم استبداله بآخر دون مراجعة.
        // Editing a published listing returns it to review when moderation is on;
        // otherwise approved content could be swapped for unreviewed content.
        if ($listing['status'] === 'published' && $this->moderationRequired()) {
            $update['status'] = 'pending_review';
        }

        Database::transaction(function () use ($listingId, $organizationId, $update, $listing, $actorId, $request): void {
            $this->listings->scopedToTenant($organizationId)->update($listingId, $update);

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'listing.updated',
                category: AuditLogger::CATEGORY_RECORD,
                entityType: 'listing',
                entityId: $listingId,
                changes: ['before' => array_intersect_key($listing, $update), 'after' => $update],
                description: 'تعديل إعلان: ' . $update['name_ar'],
                userId: $actorId,
                organizationId: $organizationId,
            );
        });
    }

    /**
     * إرسال الإعلان للنشر | Submit a listing for publication.
     *
     * @return string الحالة الناتجة
     */
    public function submitForPublication(int $listingId, int $organizationId, ?int $actorId, Request $request): string
    {
        $listing      = $this->listings->scopedToTenant($organizationId)->findOrFail($listingId);
        $organization = $this->organizations->find($organizationId);

        if ($organization === null) {
            throw new HttpException(404, 'المنشأة غير موجودة.');
        }

        // شرط التوثيق قابل للضبط من إعدادات المنصة
        if (SettingsService::bool('marketplace', 'require_verification_to_publish', true)
            && $organization['status'] !== 'verified'
        ) {
            throw new HttpException(
                422,
                'لا يمكن نشر المنتجات قبل توثيق المنشأة. أكمل ملف المنشأة وأرسله للمراجعة أولاً.',
            );
        }

        $this->assertReadyForPublication($listing);

        $target = $this->moderationRequired() ? 'pending_review' : 'published';

        Database::transaction(function () use ($listingId, $organizationId, $target, $listing, $actorId, $request): void {
            $this->listings->scopedToTenant($organizationId)->update($listingId, [
                'status'          => $target,
                'published_at'    => $target === 'published' ? date('Y-m-d H:i:s') : null,
                'moderation_note' => null,
            ]);

            $this->audit->setRequest($request);
            $this->audit->logStatusChange(
                'listing', $listingId, (string) $listing['status'], $target,
                AuditLogger::CATEGORY_RECORD, $organizationId,
            );
        });

        if ($target === 'pending_review') {
            $this->notifications->notifyPlatformReviewers(
                type: 'listing.pending_review',
                title: 'إعلان جديد بانتظار المراجعة',
                body: 'الإعلان «' . $listing['name_ar'] . '» بانتظار مراجعة النشر.',
                actionUrl: url('/admin/moderation'),
                organizationId: $organizationId,
                entityType: 'listing',
                entityId: $listingId,
            );
        }

        return $target;
    }

    /** إخفاء إعلان منشور | Unpublish (archive) a listing. */
    public function archive(int $listingId, int $organizationId, ?int $actorId, Request $request): void
    {
        $listing = $this->listings->scopedToTenant($organizationId)->findOrFail($listingId);

        $this->listings->scopedToTenant($organizationId)->update($listingId, ['status' => 'archived']);

        $this->audit->setRequest($request);
        $this->audit->logStatusChange(
            'listing', $listingId, (string) $listing['status'], 'archived',
            AuditLogger::CATEGORY_RECORD, $organizationId,
        );
    }

    /**
     * قرار المراجعة الإدارية | Admin moderation decision.
     * الرفض يستوجب سبباً حتى يعرف صاحب المنشأة ما يصحّحه.
     */
    public function moderate(
        int $listingId,
        string $decision,
        ?string $note,
        int $moderatorId,
        Request $request,
    ): void {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new HttpException(422, 'قرار المراجعة غير معروف.');
        }

        if ($decision === 'reject' && trim((string) $note) === '') {
            throw new HttpException(422, 'يجب توضيح سبب رفض الإعلان.');
        }

        // النطاق العام مبرَّر: المراجعة الإدارية تعمل عبر المنشآت بحكم دورها
        $listing = $this->listings->globalScope('مراجعة إدارية لإعلانات السوق')->find($listingId);

        if ($listing === null) {
            throw new HttpException(404, 'الإعلان المطلوب غير موجود.');
        }

        $organizationId = (int) $listing['organization_id'];
        $target         = $decision === 'approve' ? 'published' : 'rejected';

        Database::transaction(function () use (
            $listingId, $organizationId, $target, $note, $moderatorId, $listing, $request
        ): void {
            Database::statement(
                'UPDATE listings
                    SET status = ?, moderation_note = ?, moderated_by = ?, moderated_at = NOW(),
                        published_at = CASE WHEN ? = \'published\' THEN NOW() ELSE published_at END
                  WHERE id = ?',
                [$target, $note === null ? null : mb_substr($note, 0, 1000), $moderatorId, $target, $listingId],
            );

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'listing.' . $target,
                category: AuditLogger::CATEGORY_ORDER,
                entityType: 'listing',
                entityId: $listingId,
                changes: ['before' => ['status' => $listing['status']], 'after' => ['status' => $target]],
                description: ($target === 'published' ? 'اعتماد نشر إعلان: ' : 'رفض نشر إعلان: ') . $listing['name_ar'],
                severity: $target === 'rejected' ? 'warning' : 'notice',
                userId: $moderatorId,
                organizationId: $organizationId,
            );
        });

        $this->notifications->notifyOrganizationMembers(
            organizationId: $organizationId,
            type: 'listing.' . $target,
            title: $target === 'published' ? 'تم نشر إعلانك' : 'لم يُعتمد نشر إعلانك',
            body: $target === 'published'
                ? 'الإعلان «' . $listing['name_ar'] . '» أصبح ظاهراً في السوق.'
                : 'الإعلان «' . $listing['name_ar'] . '» لم يُعتمد. السبب: ' . (string) $note,
            severity: $target === 'published' ? 'success' : 'warning',
            actionUrl: url('/app/listings/' . $listingId),
            actionLabel: 'عرض الإعلان',
            entityType: 'listing',
            entityId: $listingId,
        );
    }

    // ─────────────────── تحققات | Validation helpers ───────────────────

    private function assertValidPricing(array $data): void
    {
        $mode = $data['pricing_mode'] ?? 'fixed';

        if ($mode === 'fixed') {
            $price = $data['price'] ?? null;

            if ($price === null || $price === '' || !is_numeric($price) || (float) $price < 0) {
                throw new HttpException(422, 'يجب إدخال سعر صحيح، أو اختيار «اطلب عرض سعر».');
            }
        }
    }

    /** شروط اكتمال الإعلان قبل النشر | Publication readiness. */
    private function assertReadyForPublication(array $listing): void
    {
        $missing = [];

        if (trim((string) $listing['short_description']) === '') {
            $missing[] = 'الوصف المختصر';
        }

        if ($listing['pricing_mode'] === 'fixed' && $listing['price'] === null) {
            $missing[] = 'السعر';
        }

        if ($listing['listing_type'] === 'product' && trim((string) $listing['unit_of_measure']) === '') {
            $missing[] = 'وحدة القياس';
        }

        if ($missing !== []) {
            throw new HttpException(422, 'لا يمكن النشر قبل استكمال: ' . implode('، ', $missing) . '.');
        }
    }

    private function moderationRequired(): bool
    {
        return SettingsService::bool('marketplace', 'require_listing_moderation', true);
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'          => 'مسودة',
            'pending_review' => 'بانتظار المراجعة',
            'published'      => 'منشور',
            'rejected'       => 'مرفوض',
            'archived'       => 'مؤرشف',
            default          => $status,
        };
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'published'      => 'np-badge--success',
            'pending_review' => 'np-badge--pending',
            'rejected'       => 'np-badge--danger',
            'archived'       => 'np-badge--muted',
            default          => 'np-badge--draft',
        };
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function nullable(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function nullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (int) $value;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        return ($value === null || $value === '' || !is_numeric($value))
            ? null
            : number_format((float) $value, 3, '.', '');
    }
}
