<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\FinancingProductRepository;
use App\Repositories\ServiceOfferingRepository;

/**
 * المطابقة بين المشروع والعروض | Matching SMEs to offers (§4.7).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ما تفعله هذه الخدمة: ترتّب المنتجات التمويلية وباقات الخدمات **المعتمدة**
 * حسب مدى ملاءمتها لبيانات المشروع المعلنة ونتيجة تقييمه، وتكتب مع كل اقتراح
 * الأسباب التي أنتجته.
 *
 * ما لا تفعله، عمداً وبنصّ المواصفة (§14):
 *  - لا تُنتج تقييماً ائتمانياً ولا درجة جدارة.
 *  - لا تقبل ولا ترفض ولا تُرجّح قبول جهة لطلب.
 *  - لا تستبعد مشروعاً من رؤية أي منتج: درجة المطابقة ترتّب ولا تحجب. المشروع
 *    الذي لا تنطبق عليه شروط منتج يراه ويرى سبب عدم انطباقه، فيعرف ما ينقصه.
 *
 * السبب في تخزين `reasons_ar`: اقتراح بلا تفسير صندوق أسود. صاحب المشروع من
 * حقه أن يعرف لماذا ظهر له هذا المنتج، وفريق المنصة من حقه أن يراجع المنطق
 * بعد شهور دون قراءة الكود.
 *
 * Matching ranks, it never gates: a business that fails a product's stated
 * conditions still sees the product and sees why it did not match, so it knows
 * what is missing. Reasons are stored because an unexplained suggestion is a
 * black box.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class MatchingService
{
    /** الحد الأدنى لعرض اقتراح | Suggestions below this score are not stored. */
    private const MIN_SCORE = 25;

    public function __construct(
        private readonly FinancingProductRepository $products = new FinancingProductRepository(),
        private readonly ServiceOfferingRepository $offerings = new ServiceOfferingRepository(),
        private readonly AssessmentService $assessments = new AssessmentService(),
    ) {
    }

    /**
     * توليد الاقتراحات وحفظها | Generate and persist suggestions for an organization.
     *
     * @return array{financing:array<int,array<string,mixed>>,services:array<int,array<string,mixed>>}
     */
    public function refreshFor(int $organizationId): array
    {
        $profile    = $this->organizationProfile($organizationId);
        $assessment = $this->assessments->latestCompleted($organizationId);

        $financing = $this->rankFinancing($profile);
        $services  = $this->rankServices($profile, $assessment);

        $this->persist($organizationId, $assessment, $financing, $services);

        return ['financing' => $financing, 'services' => $services];
    }

    /**
     * الاقتراحات المحفوظة | Stored suggestions, richest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function storedFor(int $organizationId, string $targetType, int $limit = 6): array
    {
        $limit = min(20, max(1, $limit));

        $table  = $targetType === 'financing_product' ? 'financing_products' : 'service_offerings';
        $column = $targetType === 'financing_product' ? 'financing_type' : 'service_type';

        return Database::select(
            "SELECT m.*, t.name_ar, t.slug, t.short_description, t.{$column} AS type_code,
                    o.legal_name, o.trading_name, o.slug AS provider_slug
               FROM match_suggestions m
               JOIN `{$table}` t ON t.id = m.target_id
               JOIN organizations o ON o.id = t.organization_id
              WHERE m.organization_id = ?
                AND m.target_type = ?
                AND m.status != 'dismissed'
                AND t.status = 'published'
                AND t.deleted_at IS NULL
              ORDER BY m.score DESC, m.id DESC
              LIMIT {$limit}",
            [$organizationId, $targetType],
        );
    }

    /** إخفاء اقتراح | Dismiss a suggestion the owner does not want. */
    public function dismiss(int $suggestionId, int $organizationId, ?string $reason): void
    {
        Database::statement(
            "UPDATE match_suggestions
                SET status = 'dismissed', dismissed_reason_ar = ?
              WHERE id = ? AND organization_id = ?",
            [
                $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 300),
                $suggestionId,
                $organizationId,
            ],
        );
    }

    // ═══════════════════ ترتيب التمويل | Ranking financing ═══════════════════

    /**
     * @param  array<string,mixed> $profile
     * @return array<int,array<string,mixed>>
     */
    public function rankFinancing(array $profile): array
    {
        $ranked = [];

        foreach ($this->products->publishedForMatching() as $product) {
            [$score, $reasons, $gaps] = $this->scoreFinancing($product, $profile);

            if ($score < self::MIN_SCORE) {
                continue;
            }

            $ranked[] = [
                'target'  => $product,
                'score'   => $score,
                'reasons' => $reasons,
                'gaps'    => $gaps,
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $ranked;
    }

    /**
     * @param  array<string,mixed> $product
     * @param  array<string,mixed> $profile
     * @return array{0:int,1:array<int,string>,2:array<int,string>}
     */
    private function scoreFinancing(array $product, array $profile): array
    {
        $score   = 50; // نقطة بداية محايدة: منتج معتمد من جهة موثّقة
        $reasons = [];
        $gaps    = [];

        // المحافظة | Governorate coverage
        if ($this->listCovers($product['eligible_governorate_ids'], $profile['governorate_id'])) {
            if (!empty($product['eligible_governorate_ids'])) {
                $score += 10;
                $reasons[] = 'المنتج متاح في محافظتك.';
            }
        } else {
            $score -= 25;
            $gaps[] = 'هذا المنتج غير متاح في محافظتك حالياً.';
        }

        // القطاع | Sector
        if ($this->listCovers($product['eligible_sector_ids'], $profile['sector_id'])) {
            if (!empty($product['eligible_sector_ids'])) {
                $score += 10;
                $reasons[] = 'المنتج موجّه لقطاع نشاطك.';
            }
        } else {
            $score -= 20;
            $gaps[] = 'المنتج موجّه لقطاعات أخرى.';
        }

        // سنوات النشاط | Years in business
        if ($product['min_years_in_business'] !== null) {
            $years = $profile['years_in_business'];

            if ($years === null) {
                $gaps[] = 'المنتج يشترط ' . number_ar((int) $product['min_years_in_business'])
                    . ' سنة نشاط على الأقل، ولم تُسجَّل سنة تأسيس مشروعك بعد.';
            } elseif ($years >= (int) $product['min_years_in_business']) {
                $score += 10;
                $reasons[] = 'مدة نشاط مشروعك تستوفي شرط المنتج.';
            } else {
                $score -= 20;
                $gaps[] = 'المنتج يشترط ' . number_ar((int) $product['min_years_in_business'])
                    . ' سنة نشاط على الأقل.';
            }
        }

        // التقنين | Formal registration
        if ((int) $product['requires_formal_registration'] === 1) {
            if (!empty($profile['is_formal'])) {
                $score += 10;
                $reasons[] = 'وضعك القانوني يستوفي شرط التقنين.';
            } else {
                $score -= 25;
                $gaps[] = 'المنتج متاح للمنشآت المقنَّنة فقط. تقنين النشاط يفتح لك هذا الخيار.';
            }
        } elseif (empty($profile['is_formal'])) {
            $score += 10;
            $reasons[] = 'المنتج لا يشترط تقنين النشاط.';
        }

        // الإيراد السنوي | Annual revenue
        if ($product['min_annual_revenue'] !== null && $profile['annual_revenue'] !== null) {
            if ((float) $profile['annual_revenue'] >= (float) $product['min_annual_revenue']) {
                $score += 5;
                $reasons[] = 'إيرادك المعلن يستوفي الحد الأدنى للمنتج.';
            } else {
                $score -= 15;
                $gaps[] = 'المنتج يشترط إيراداً سنوياً لا يقل عن '
                    . money((float) $product['min_annual_revenue']) . '.';
            }
        }

        if ($reasons === []) {
            $reasons[] = 'منتج معتمد من مؤسسة موثّقة على المنصة.';
        }

        return [$this->clamp($score), $reasons, $gaps];
    }

    // ═══════════════════ ترتيب الخدمات | Ranking services ═══════════════════

    /**
     * @param  array<string,mixed>      $profile
     * @param  array<string,mixed>|null $assessment
     * @return array<int,array<string,mixed>>
     */
    public function rankServices(array $profile, ?array $assessment): array
    {
        // المجالات ذات الأولوية من التقييم تُترجَم إلى أنواع خدمات مقابلة
        $priorities = $assessment !== null && !empty($assessment['priority_sections'])
            ? explode(',', (string) $assessment['priority_sections'])
            : [];

        $wantedTypes = [];
        foreach ($priorities as $section) {
            foreach ($this->sectionServiceTypes(trim($section)) as $type) {
                $wantedTypes[$type] = trim($section);
            }
        }

        $ranked = [];

        foreach ($this->offerings->publishedForMatching() as $offering) {
            [$score, $reasons, $gaps] = $this->scoreService($offering, $profile, $wantedTypes);

            if ($score < self::MIN_SCORE) {
                continue;
            }

            $ranked[] = [
                'target'  => $offering,
                'score'   => $score,
                'reasons' => $reasons,
                'gaps'    => $gaps,
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $ranked;
    }

    /**
     * @param  array<string,mixed>  $offering
     * @param  array<string,mixed>  $profile
     * @param  array<string,string> $wantedTypes نوع الخدمة => المجال الذي طلبها
     * @return array{0:int,1:array<int,string>,2:array<int,string>}
     */
    private function scoreService(array $offering, array $profile, array $wantedTypes): array
    {
        $score   = 45;
        $reasons = [];
        $gaps    = [];

        $type = (string) $offering['service_type'];

        if (isset($wantedTypes[$type])) {
            $score += 30;
            $reasons[] = 'تقييمك أظهر أن «' . $this->assessments->sectionLabel($wantedTypes[$type])
                . '» من أولوياتك، وهذه الخدمة تعالجه.';
        }

        // التغطية الجغرافية | Geographic coverage
        if ((int) $offering['covers_all_governorates'] === 1) {
            $score += 5;
            $reasons[] = 'الخدمة متاحة في كل المحافظات.';
        } elseif ($this->listContains($offering['governorate_ids'], $profile['governorate_id'])) {
            $score += 12;
            $reasons[] = 'مقدّم الخدمة يغطي محافظتك.';
        } else {
            $score -= 20;
            $gaps[] = 'التغطية المعلنة لا تشمل محافظتك؛ قد يكون التنفيذ عن بُعد ممكناً.';
        }

        if ($this->listCovers($offering['sector_ids'], $profile['sector_id'])) {
            if (!empty($offering['sector_ids'])) {
                $score += 8;
                $reasons[] = 'الخدمة موجّهة لقطاع نشاطك.';
            }
        } else {
            $score -= 10;
            $gaps[] = 'الخدمة موجّهة لقطاعات أخرى.';
        }

        // المجانية ضمن برنامج تنموي رفعٌ حقيقي لفرصة الاستفادة
        if ((string) $offering['pricing_mode'] === 'free') {
            $score += 10;
            $reasons[] = 'الخدمة مجانية'
                . (($offering['funded_by_ar'] ?? '') !== ''
                    ? ' بتمويل ' . (string) $offering['funded_by_ar'] : '') . '.';
        }

        if ((string) $offering['delivery_mode'] === 'remote') {
            $reasons[] = 'تُنفَّذ عن بُعد، فلا يقيّدها موقعك.';
        }

        if ($reasons === []) {
            $reasons[] = 'باقة معتمدة من مقدّم خدمة موثّق على المنصة.';
        }

        return [$this->clamp($score), $reasons, $gaps];
    }

    /**
     * ترجمة مجال التقييم إلى أنواع خدمات | Map an assessment section to service types.
     *
     * @return array<int,string>
     */
    private function sectionServiceTypes(string $section): array
    {
        return match ($section) {
            'finance'    => ['accounting', 'consulting'],
            'market'     => ['marketing', 'design'],
            'operations' => ['technical', 'consulting'],
            'digital'    => ['digital', 'technical'],
            'compliance' => ['legal', 'certification'],
            'skills'     => ['training'],
            default      => [],
        };
    }

    // ─────────────────── الحفظ والملف | Persistence and profile ───────────────────

    /**
     * @param array<string,mixed>|null              $assessment
     * @param array<int,array<string,mixed>>        $financing
     * @param array<int,array<string,mixed>>        $services
     */
    private function persist(int $organizationId, ?array $assessment, array $financing, array $services): void
    {
        Database::transaction(function () use ($organizationId, $assessment, $financing, $services): void {
            $assessmentId = $assessment === null ? null : (int) $assessment['id'];

            foreach ([['financing_product', $financing], ['service_offering', $services]] as [$type, $items]) {
                foreach ($items as $item) {
                    // الأسباب والفجوات معاً: المشروع يرى لماذا نُوصي وماذا ينقص
                    $lines = array_merge($item['reasons'], array_map(
                        static fn (string $gap): string => '⚠ ' . $gap,
                        $item['gaps'],
                    ));

                    Database::statement(
                        'INSERT INTO match_suggestions
                            (organization_id, assessment_id, target_type, target_id, score, reasons_ar)
                         VALUES (?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            assessment_id = VALUES(assessment_id),
                            score = VALUES(score),
                            reasons_ar = VALUES(reasons_ar),
                            -- الاقتراح الذي أخفاه صاحب المشروع يبقى مخفياً:
                            -- إعادة إظهاره مع كل تحديث تجاهُلٌ لقراره.
                            status = CASE WHEN status = \'dismissed\' THEN \'dismissed\' ELSE status END',
                        [
                            $organizationId,
                            $assessmentId,
                            $type,
                            (int) $item['target']['id'],
                            $item['score'],
                            mb_substr(implode("\n", $lines), 0, 1000),
                        ],
                    );
                }
            }
        });
    }

    /**
     * ملف المشروع المستخدم في المطابقة | The profile fields matching relies on.
     *
     * @return array<string,mixed>
     */
    public function organizationProfile(int $organizationId): array
    {
        $row = Database::selectOne(
            'SELECT o.id, o.governorate_id, o.sector_id, o.status,
                    p.formalization_status, p.establishment_date, p.annual_revenue_range
               FROM organizations o
               LEFT JOIN sme_profiles p ON p.organization_id = o.id
              WHERE o.id = ? AND o.deleted_at IS NULL
              LIMIT 1',
            [$organizationId],
        );

        if ($row === null) {
            return [
                'governorate_id' => null, 'sector_id' => null,
                'years_in_business' => null, 'is_formal' => false, 'annual_revenue' => null,
            ];
        }

        $years = null;

        if (!empty($row['establishment_date'])) {
            $established = strtotime((string) $row['establishment_date']);

            if ($established !== false) {
                $years = max(0, (int) floor((time() - $established) / (365.25 * 86400)));
            }
        }

        // «غير محدَّد» ليس «غير رسمي»: القيمة الفارغة تعني أن السؤال لم يُجَب بعد
        $formalisation = $row['formalization_status'];

        return [
            'governorate_id'    => $row['governorate_id'] === null ? null : (int) $row['governorate_id'],
            'sector_id'         => $row['sector_id'] === null ? null : (int) $row['sector_id'],
            'years_in_business' => $years,
            'is_formal'         => $formalisation !== null && $formalisation !== 'informal',
            'annual_revenue'    => $this->revenueFloor($row['annual_revenue_range'] ?? null),
        ];
    }

    /**
     * الحد الأدنى لنطاق الإيراد | The floor of a declared revenue band.
     *
     * الإيراد يُجمع كنطاق لا كرقم (§10): سؤال المشروع عن رقم دقيق تحصيلٌ لبيانات
     * مالية لا يحتاجها الاقتراح. المقارنة تستخدم حدّ النطاق الأدنى، وهو التقدير
     * المحافظ: مشروع في نطاق «١–٥ مليون» يُعامَل كأنه عند المليون، فلا يُقترح
     * عليه منتج قد لا يستوفي شرطه.
     *
     * «أفضّل عدم الإفصاح» تُعامَل كمجهول لا كصفر: الامتناع عن الإجابة ليس إقراراً
     * بانخفاض الإيراد، ومعاملته كذلك تعاقب من مارس حقه في عدم الإفصاح.
     */
    private function revenueFloor(?string $range): ?float
    {
        return match ($range) {
            'under_250k' => 0.0,
            '250k_1m'    => 250_000.0,
            '1m_5m'      => 1_000_000.0,
            '5m_20m'     => 5_000_000.0,
            '20m_50m'    => 20_000_000.0,
            'over_50m'   => 50_000_000.0,
            default      => null,
        };
    }

    /**
     * قائمة فارغة تعني «الكل» | An empty list means "everyone".
     */
    private function listCovers(mixed $list, ?int $id): bool
    {
        $value = trim((string) $list);

        if ($value === '') {
            return true;
        }

        return $this->listContains($list, $id);
    }

    private function listContains(mixed $list, ?int $id): bool
    {
        if ($id === null) {
            return false;
        }

        $value = trim((string) $list);

        if ($value === '') {
            return false;
        }

        return in_array($id, array_map('intval', explode(',', $value)), true);
    }

    private function clamp(int $score): int
    {
        return max(0, min(100, $score));
    }
}
