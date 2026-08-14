<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;

/**
 * المنتجات التمويلية | Financing products (§4.5).
 *
 * المؤسسة المالية تُدخل منتجها وتُرسله، والمنصة تعتمده قبل ظهوره لأي مشروع.
 * آلية الاعتماد نفسها مشتركة مع باقات الخدمات في `ApprovableCatalogService`.
 *
 * ما لا تفعله هذه الخدمة، عمداً: لا تحسب قسطاً ولا جدول سداد ولا تكلفة فعلية.
 * أي رقم من هذا النوع التزام مالي، ومصدره الوحيد المشروع هو المؤسسة المالية.
 * Deliberately absent: instalment or amortisation maths. Any such number is a
 * financial commitment, and its only legitimate source is the institution.
 */
final class FinancingProductService extends ApprovableCatalogService
{
    /** أنواع التمويل | Financing types. */
    public const TYPES = [
        'working_capital' => 'تمويل رأس مال عامل',
        'asset_finance'   => 'تمويل أصول ومعدات',
        'microfinance'    => 'تمويل متناهي الصغر',
        'trade_finance'   => 'تمويل تجاري',
        'leasing'         => 'تأجير تمويلي',
        'grant'           => 'منحة',
        'equity'          => 'مساهمة في رأس المال',
        'other'           => 'أخرى',
    ];

    protected function table(): string
    {
        return 'financing_products';
    }

    protected function entityType(): string
    {
        return 'financing_product';
    }

    protected function label(): string
    {
        return 'المنتج التمويلي';
    }

    protected function providerUrl(int $id): string
    {
        return '/app/finance/products/' . $id;
    }

    protected function moderationUrl(): string
    {
        return '/admin/finance/products';
    }

    protected function moderationPermission(): string
    {
        return 'finance.product.moderate';
    }

    /** @param array<string,mixed> $row */
    protected function missingForSubmission(array $row): array
    {
        $missing = [];

        if (trim((string) $row['short_description']) === '') {
            $missing[] = 'الوصف المختصر';
        }

        if ($row['min_amount'] === null || $row['max_amount'] === null) {
            $missing[] = 'شريحة مبلغ التمويل';
        }

        if (trim((string) $row['eligibility_summary_ar']) === '') {
            $missing[] = 'ملخّص شروط الأهلية';
        }

        if (trim((string) $row['required_documents_ar']) === '') {
            $missing[] = 'المستندات المطلوبة';
        }

        // التكلفة ليست اختيارية: عرض تمويل بلا بيان تكلفة يضلّل صاحب المشروع
        if (trim((string) $row['rate_note_ar']) === '') {
            $missing[] = 'بيان التكلفة أو العائد';
        }

        return $missing;
    }

    // ═══════════════════ الإنشاء والتعديل | Create and update ═══════════════════

    /** @param array<string,mixed> $data */
    public function create(int $organizationId, array $data, ?int $actorId, Request $request): int
    {
        $this->assertValidAmounts($data);

        $name = trim((string) ($data['name_ar'] ?? ''));

        $id = Database::insert(
            'INSERT INTO financing_products
                (organization_id, category_id, name_ar, slug, short_description, description,
                 financing_type, min_amount, max_amount, currency_code,
                 min_tenor_months, max_tenor_months, rate_note_ar, fees_note_ar,
                 eligibility_summary_ar, required_documents_ar,
                 min_years_in_business, min_annual_revenue, requires_formal_registration,
                 eligible_governorate_ids, eligible_sector_ids, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $organizationId,
                $this->nullableInt($data['category_id'] ?? null),
                $name,
                $this->generateUniqueSlug($name),
                $this->nullableText($data['short_description'] ?? null, 500),
                $this->nullableText($data['description'] ?? null, 20000),
                $this->financingType($data['financing_type'] ?? null),
                $this->nullableDecimal($data['min_amount'] ?? null),
                $this->nullableDecimal($data['max_amount'] ?? null),
                'EGP',
                $this->nullableInt($data['min_tenor_months'] ?? null),
                $this->nullableInt($data['max_tenor_months'] ?? null),
                $this->nullableText($data['rate_note_ar'] ?? null, 500),
                $this->nullableText($data['fees_note_ar'] ?? null, 500),
                $this->nullableText($data['eligibility_summary_ar'] ?? null, 2000),
                $this->nullableText($data['required_documents_ar'] ?? null, 2000),
                $this->nullableInt($data['min_years_in_business'] ?? null),
                $this->nullableDecimal($data['min_annual_revenue'] ?? null),
                !empty($data['requires_formal_registration']) ? 1 : 0,
                $this->idList($data['eligible_governorate_ids'] ?? null),
                $this->idList($data['eligible_sector_ids'] ?? null),
                'draft',
            ],
        );

        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'financing_product.created',
            category: AuditLogger::CATEGORY_RECORD,
            entityType: 'financing_product',
            entityId: $id,
            description: 'إنشاء منتج تمويلي: ' . $name,
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
                'لا يمكن تعديل منتج قيد الاعتماد. انتظر قرار المنصة أو اسحب الطلب.',
            );
        }

        $this->assertValidAmounts($data);

        $name = trim((string) ($data['name_ar'] ?? $row['name_ar']));

        // التعديل بعد الاعتماد يُعيد المنتج إلى الاعتماد من جديد: تغيير الشريحة
        // أو الشروط بعد النشر يعني منتجاً مختلفاً لم يراجعه أحد.
        $target = $row['status'] === 'published' ? 'pending_review' : $row['status'];

        Database::statement(
            'UPDATE financing_products
                SET category_id = ?, name_ar = ?, slug = ?, short_description = ?, description = ?,
                    financing_type = ?, min_amount = ?, max_amount = ?,
                    min_tenor_months = ?, max_tenor_months = ?, rate_note_ar = ?, fees_note_ar = ?,
                    eligibility_summary_ar = ?, required_documents_ar = ?,
                    min_years_in_business = ?, min_annual_revenue = ?, requires_formal_registration = ?,
                    eligible_governorate_ids = ?, eligible_sector_ids = ?, status = ?
              WHERE id = ? AND organization_id = ?',
            [
                $this->nullableInt($data['category_id'] ?? null),
                $name,
                $this->generateUniqueSlug($name, $id),
                $this->nullableText($data['short_description'] ?? null, 500),
                $this->nullableText($data['description'] ?? null, 20000),
                $this->financingType($data['financing_type'] ?? null),
                $this->nullableDecimal($data['min_amount'] ?? null),
                $this->nullableDecimal($data['max_amount'] ?? null),
                $this->nullableInt($data['min_tenor_months'] ?? null),
                $this->nullableInt($data['max_tenor_months'] ?? null),
                $this->nullableText($data['rate_note_ar'] ?? null, 500),
                $this->nullableText($data['fees_note_ar'] ?? null, 500),
                $this->nullableText($data['eligibility_summary_ar'] ?? null, 2000),
                $this->nullableText($data['required_documents_ar'] ?? null, 2000),
                $this->nullableInt($data['min_years_in_business'] ?? null),
                $this->nullableDecimal($data['min_annual_revenue'] ?? null),
                !empty($data['requires_formal_registration']) ? 1 : 0,
                $this->idList($data['eligible_governorate_ids'] ?? null),
                $this->idList($data['eligible_sector_ids'] ?? null),
                $target,
                $id,
                $organizationId,
            ],
        );

        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'financing_product.updated',
            category: AuditLogger::CATEGORY_RECORD,
            entityType: 'financing_product',
            entityId: $id,
            description: 'تعديل منتج تمويلي: ' . $name
                . ($target === 'pending_review' && $row['status'] === 'published'
                    ? ' — أُعيد للاعتماد بعد التعديل' : ''),
            userId: $actorId,
            organizationId: $organizationId,
        );

        if ($target === 'pending_review' && $row['status'] === 'published') {
            $this->notifications->notifyPlatformReviewers(
                type: 'financing_product.pending_review',
                title: 'تعديل على منتج تمويلي معتمد',
                body: '«' . $name . '» عُدِّل بعد اعتماده ويحتاج مراجعة جديدة.',
                actionUrl: url($this->moderationUrl()),
                organizationId: $organizationId,
                entityType: 'financing_product',
                entityId: $id,
                permission: $this->moderationPermission(),
            );
        }
    }

    public function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }

    // ─────────────────── تحققات وتحويلات | Validation and casting ───────────────────

    /** @param array<string,mixed> $data */
    private function assertValidAmounts(array $data): void
    {
        $min = $this->nullableDecimal($data['min_amount'] ?? null);
        $max = $this->nullableDecimal($data['max_amount'] ?? null);

        if ($min !== null && $max !== null && (float) $min > (float) $max) {
            throw new HttpException(422, 'الحد الأدنى للتمويل لا يمكن أن يتجاوز الحد الأعلى.');
        }

        $minTenor = $this->nullableInt($data['min_tenor_months'] ?? null);
        $maxTenor = $this->nullableInt($data['max_tenor_months'] ?? null);

        if ($minTenor !== null && $maxTenor !== null && $minTenor > $maxTenor) {
            throw new HttpException(422, 'أقل مدة سداد لا يمكن أن تتجاوز أطول مدة.');
        }
    }

    private function financingType(mixed $value): string
    {
        $type = (string) $value;

        return array_key_exists($type, self::TYPES) ? $type : 'working_capital';
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

    /**
     * قائمة معرّفات نظيفة | A sanitised id list.
     *
     * القيمة تصل من نموذج، وتُخزَّن نصاً يُستخدم لاحقاً في المطابقة. تُصفّى إلى
     * أعداد صحيحة فقط حتى لا يتسلّل نص حرّ إلى عمود يُقرأ ويُقارن لاحقاً.
     */
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
