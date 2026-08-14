<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;

/**
 * باقات الخدمات غير المالية | Non-financial service offerings (§4.6).
 *
 * مقدّم الخدمة أو المنظمة الأهلية يُدخل الباقة ويُرسلها، ولا تظهر لأي مشروع
 * قبل اعتماد المنصة — نفس قاعدة المنتجات التمويلية، ومطبَّقة بنفس الآلية.
 */
final class ServiceOfferingService extends ApprovableCatalogService
{
    /** أنواع الخدمات | Service types. */
    public const TYPES = [
        'consulting'    => 'استشارات',
        'training'      => 'تدريب وتأهيل',
        'technical'     => 'دعم فني وتقني',
        'marketing'     => 'تسويق وترويج',
        'legal'         => 'خدمات قانونية',
        'accounting'    => 'محاسبة ومراجعة',
        'design'        => 'تصميم وهوية',
        'digital'       => 'تحوّل رقمي',
        'certification' => 'جودة وشهادات',
        'other'         => 'أخرى',
    ];

    /** أوضاع التنفيذ | Delivery modes. */
    public const DELIVERY_MODES = [
        'onsite' => 'في مقر المشروع',
        'remote' => 'عن بُعد',
        'hybrid' => 'مختلط',
    ];

    /** أوضاع التسعير | Pricing modes. */
    public const PRICING_MODES = [
        'fixed' => 'سعر ثابت',
        'range' => 'شريحة سعرية',
        'quote' => 'حسب الطلب',
        'free'  => 'مجانية ضمن برنامج',
    ];

    protected function table(): string
    {
        return 'service_offerings';
    }

    protected function entityType(): string
    {
        return 'service_offering';
    }

    protected function label(): string
    {
        return 'باقة الخدمة';
    }

    protected function providerUrl(int $id): string
    {
        return '/app/services/offerings/' . $id;
    }

    protected function moderationUrl(): string
    {
        return '/admin/services/offerings';
    }

    protected function moderationPermission(): string
    {
        return 'services.offering.moderate';
    }

    /** @param array<string,mixed> $row */
    protected function missingForSubmission(array $row): array
    {
        $missing = [];

        if (trim((string) $row['short_description']) === '') {
            $missing[] = 'الوصف المختصر';
        }

        if (trim((string) $row['deliverables_ar']) === '') {
            $missing[] = 'مخرجات الخدمة';
        }

        if (trim((string) $row['target_audience_ar']) === '') {
            $missing[] = 'الفئة المستهدفة';
        }

        if (in_array($row['pricing_mode'], ['fixed', 'range'], true) && $row['price_from'] === null) {
            $missing[] = 'السعر';
        }

        // الخدمة المجانية يجب أن تُنسب لممولها: «مجاني» بلا مصدر يوحي بأن
        // المنصة هي من تتحمّل التكلفة، وهي لا تفعل.
        if ($row['pricing_mode'] === 'free' && trim((string) $row['funded_by_ar']) === '') {
            $missing[] = 'الجهة الممولة للخدمة المجانية';
        }

        return $missing;
    }

    // ═══════════════════ الإنشاء والتعديل | Create and update ═══════════════════

    /** @param array<string,mixed> $data */
    public function create(int $organizationId, array $data, ?int $actorId, Request $request): int
    {
        $this->assertValidPricing($data);

        $name = trim((string) ($data['name_ar'] ?? ''));

        $id = Database::insert(
            'INSERT INTO service_offerings
                (organization_id, category_id, name_ar, slug, short_description, description,
                 service_type, delivery_mode, duration_note_ar,
                 pricing_mode, price_from, price_to, currency_code, funded_by_ar,
                 target_audience_ar, deliverables_ar,
                 covers_all_governorates, governorate_ids, sector_ids, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $organizationId,
                $this->nullableInt($data['category_id'] ?? null),
                $name,
                $this->generateUniqueSlug($name),
                $this->nullableText($data['short_description'] ?? null, 500),
                $this->nullableText($data['description'] ?? null, 20000),
                $this->enumValue($data['service_type'] ?? null, self::TYPES, 'consulting'),
                $this->enumValue($data['delivery_mode'] ?? null, self::DELIVERY_MODES, 'hybrid'),
                $this->nullableText($data['duration_note_ar'] ?? null, 300),
                $this->enumValue($data['pricing_mode'] ?? null, self::PRICING_MODES, 'quote'),
                $this->nullableDecimal($data['price_from'] ?? null),
                $this->nullableDecimal($data['price_to'] ?? null),
                'EGP',
                $this->nullableText($data['funded_by_ar'] ?? null, 200),
                $this->nullableText($data['target_audience_ar'] ?? null, 1000),
                $this->nullableText($data['deliverables_ar'] ?? null, 2000),
                empty($data['governorate_ids']) ? 1 : 0,
                $this->idList($data['governorate_ids'] ?? null),
                $this->idList($data['sector_ids'] ?? null),
                'draft',
            ],
        );

        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'service_offering.created',
            category: AuditLogger::CATEGORY_RECORD,
            entityType: 'service_offering',
            entityId: $id,
            description: 'إنشاء باقة خدمة: ' . $name,
            userId: $actorId,
            organizationId: $organizationId,
        );

        return $id;
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, int $organizationId, array $data, ?int $actorId, Request $request): void
    {
        $row = $this->findOwned($id, $organizationId);

        if ($row['status'] === 'pending_review') {
            throw new HttpException(
                422,
                'لا يمكن تعديل باقة قيد الاعتماد. انتظر قرار المنصة أو اسحب الطلب.',
            );
        }

        $this->assertValidPricing($data);

        $name = trim((string) ($data['name_ar'] ?? $row['name_ar']));

        // كما في المنتجات التمويلية: التعديل بعد الاعتماد يستوجب اعتماداً جديداً
        $target = $row['status'] === 'published' ? 'pending_review' : $row['status'];

        Database::statement(
            'UPDATE service_offerings
                SET category_id = ?, name_ar = ?, slug = ?, short_description = ?, description = ?,
                    service_type = ?, delivery_mode = ?, duration_note_ar = ?,
                    pricing_mode = ?, price_from = ?, price_to = ?, funded_by_ar = ?,
                    target_audience_ar = ?, deliverables_ar = ?,
                    covers_all_governorates = ?, governorate_ids = ?, sector_ids = ?, status = ?
              WHERE id = ? AND organization_id = ?',
            [
                $this->nullableInt($data['category_id'] ?? null),
                $name,
                $this->generateUniqueSlug($name, $id),
                $this->nullableText($data['short_description'] ?? null, 500),
                $this->nullableText($data['description'] ?? null, 20000),
                $this->enumValue($data['service_type'] ?? null, self::TYPES, 'consulting'),
                $this->enumValue($data['delivery_mode'] ?? null, self::DELIVERY_MODES, 'hybrid'),
                $this->nullableText($data['duration_note_ar'] ?? null, 300),
                $this->enumValue($data['pricing_mode'] ?? null, self::PRICING_MODES, 'quote'),
                $this->nullableDecimal($data['price_from'] ?? null),
                $this->nullableDecimal($data['price_to'] ?? null),
                $this->nullableText($data['funded_by_ar'] ?? null, 200),
                $this->nullableText($data['target_audience_ar'] ?? null, 1000),
                $this->nullableText($data['deliverables_ar'] ?? null, 2000),
                empty($data['governorate_ids']) ? 1 : 0,
                $this->idList($data['governorate_ids'] ?? null),
                $this->idList($data['sector_ids'] ?? null),
                $target,
                $id,
                $organizationId,
            ],
        );

        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'service_offering.updated',
            category: AuditLogger::CATEGORY_RECORD,
            entityType: 'service_offering',
            entityId: $id,
            description: 'تعديل باقة خدمة: ' . $name,
            userId: $actorId,
            organizationId: $organizationId,
        );

        if ($target === 'pending_review' && $row['status'] === 'published') {
            $this->notifications->notifyPlatformReviewers(
                type: 'service_offering.pending_review',
                title: 'تعديل على باقة خدمة معتمدة',
                body: '«' . $name . '» عُدِّلت بعد اعتمادها وتحتاج مراجعة جديدة.',
                actionUrl: url($this->moderationUrl()),
                organizationId: $organizationId,
                entityType: 'service_offering',
                entityId: $id,
                permission: $this->moderationPermission(),
            );
        }
    }

    public function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }

    public function priceLabel(array $offering): string
    {
        return match ((string) $offering['pricing_mode']) {
            'free'  => 'مجانية' . (($offering['funded_by_ar'] ?? '') !== ''
                ? ' — بتمويل ' . (string) $offering['funded_by_ar'] : ''),
            'fixed' => money((float) $offering['price_from']),
            'range' => money((float) $offering['price_from']) . ' – ' . money((float) $offering['price_to']),
            default => 'حسب الطلب',
        };
    }

    // ─────────────────── تحققات وتحويلات | Validation and casting ───────────────────

    /** @param array<string,mixed> $data */
    private function assertValidPricing(array $data): void
    {
        $mode = (string) ($data['pricing_mode'] ?? 'quote');
        $from = $this->nullableDecimal($data['price_from'] ?? null);
        $to   = $this->nullableDecimal($data['price_to'] ?? null);

        if (in_array($mode, ['fixed', 'range'], true) && $from === null) {
            throw new HttpException(422, 'أدخل السعر، أو اختر «حسب الطلب».');
        }

        if ($mode === 'range' && $to === null) {
            throw new HttpException(422, 'الشريحة السعرية تحتاج حداً أعلى.');
        }

        if ($from !== null && $to !== null && (float) $from > (float) $to) {
            throw new HttpException(422, 'الحد الأدنى للسعر لا يمكن أن يتجاوز الحد الأعلى.');
        }
    }

    /** @param array<string,string> $allowed */
    private function enumValue(mixed $value, array $allowed, string $default): string
    {
        $candidate = (string) $value;

        return array_key_exists($candidate, $allowed) ? $candidate : $default;
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

    private function nullableDecimal(mixed $value): ?string
    {
        return ($value === null || $value === '' || !is_numeric($value))
            ? null
            : number_format((float) $value, 2, '.', '');
    }

    private function idList(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($v): int => (int) $v, $value),
            static fn (int $v): bool => $v > 0,
        )));

        return $ids === [] ? null : implode(',', array_slice($ids, 0, 50));
    }
}
