<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Services\AssessmentService;
use App\Services\MatchingService;
use Tests\TestCase;

/**
 * التقييم والمطابقة | Needs assessment and matching (§4.7).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * القاعدة المحفوظة: **المطابقة ترتّب ولا تحجب، وتفسّر ولا تقرّر.** أي سلوك
 * يقترب من التقييم الائتماني أو من منع مشروع من رؤية منتج هو خرق للمواصفة
 * (§14)، وهذه الاختبارات تمنعه من التسلّل مع أي تعديل مستقبلي.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class AssessmentAndMatchingTest extends TestCase
{
    private AssessmentService $assessments;

    private MatchingService $matching;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assessments = new AssessmentService();
        $this->matching    = new MatchingService();
    }

    // ═══════════════════ التقييم | Assessment ═══════════════════

    public function test_a_draft_is_created_once_and_reused(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);

        $first  = $this->assessments->openDraft($organizationId);
        $second = $this->assessments->openDraft($organizationId);

        $this->assertSame((int) $first['id'], (int) $second['id']);
        $this->assertSame(1, $this->countRows('needs_assessments', 'organization_id = ?', [$organizationId]));
    }

    public function test_an_incomplete_assessment_is_refused(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $draft          = $this->assessments->openDraft($organizationId);

        $this->expectException(HttpException::class);

        $this->assessments->complete(
            (int) $draft['id'],
            $organizationId,
            [],
            $this->createUser(),
            $this->request('POST', '/submit'),
        );
    }

    public function test_completing_the_assessment_scores_every_section(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $draft          = $this->assessments->openDraft($organizationId);

        $result = $this->assessments->complete(
            (int) $draft['id'],
            $organizationId,
            $this->answers(strong: true),
            $this->createUser(),
            $this->request('POST', '/submit'),
        );

        $this->assertSame('completed', $result['status']);
        $this->assertNotNull($result['completed_at']);

        foreach (array_keys(AssessmentService::SECTIONS) as $section) {
            $this->assertNotNull(
                $result['score_' . $section],
                "المجال {$section} يجب أن يحصل على درجة.",
            );
        }

        $this->assertGreaterThan(70, (int) $result['score_overall']);
    }

    /** الإجابات الضعيفة تنتج درجات منخفضة | Weak answers produce low scores. */
    public function test_weak_answers_produce_low_scores_and_two_priorities(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $draft          = $this->assessments->openDraft($organizationId);

        $result = $this->assessments->complete(
            (int) $draft['id'],
            $organizationId,
            $this->answers(strong: false),
            $this->createUser(),
            $this->request('POST', '/submit'),
        );

        $this->assertLessThan(30, (int) $result['score_overall']);
        $this->assertCount(2, explode(',', (string) $result['priority_sections']));
    }

    public function test_a_completed_assessment_cannot_be_completed_again(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $draft          = $this->assessments->openDraft($organizationId);

        $this->assessments->complete(
            (int) $draft['id'],
            $organizationId,
            $this->answers(strong: true),
            $this->createUser(),
            $this->request('POST', '/submit'),
        );

        $this->expectException(HttpException::class);

        $this->assessments->complete(
            (int) $draft['id'],
            $organizationId,
            $this->answers(strong: true),
            $this->createUser(),
            $this->request('POST', '/submit'),
        );
    }

    public function test_another_organization_cannot_complete_the_assessment(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $intruderId     = $this->createOrganization(['status' => 'verified']);
        $draft          = $this->assessments->openDraft($organizationId);

        $this->expectException(HttpException::class);

        $this->assessments->complete(
            (int) $draft['id'],
            $intruderId,
            $this->answers(strong: true),
            $this->createUser(),
            $this->request('POST', '/submit'),
        );
    }

    // ═══════════════════ المطابقة | Matching ═══════════════════

    /**
     * المطابقة لا تحجب | Matching never gates.
     *
     * مشروع لا يستوفي شرطاً يجب أن يظل قادراً على رؤية المنتج والتقديم عليه،
     * وأن يقرأ السبب. حجب المنتج عنه يحوّل الاقتراح إلى قرار.
     */
    public function test_a_failing_profile_still_sees_the_product_with_the_gap_explained(): void
    {
        $bankId = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $this->createFinancingProduct($bankId, [
            'name_ar'                      => 'منتج يشترط التقنين',
            'requires_formal_registration' => 1,
            'min_years_in_business'        => 5,
        ]);

        // مشروع غير مقنَّن وبلا تاريخ تأسيس: لا يستوفي أي شرط
        $smeId = $this->createOrganization(['status' => 'verified']);

        $ranked = $this->matching->rankFinancing($this->matching->organizationProfile($smeId));

        $this->assertCount(1, $ranked, 'المنتج يجب أن يظهر رغم عدم استيفاء شروطه.');
        $this->assertNotSame([], $ranked[0]['gaps'], 'يجب شرح ما ينقص المشروع.');

        $gapText = implode(' ', $ranked[0]['gaps']);
        $this->assertStringContainsString('المقنَّنة', $gapText);
    }

    public function test_a_matching_profile_scores_higher_than_a_failing_one(): void
    {
        $bankId = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $this->createFinancingProduct($bankId, ['requires_formal_registration' => 1]);

        $strongSme = $this->createOrganization(['status' => 'verified']);
        $weakSme   = $this->createOrganization(['status' => 'verified']);

        $this->attachProfile($strongSme, ['formalization_status' => 'llc']);
        $this->attachProfile($weakSme, ['formalization_status' => 'informal']);

        $strongScore = $this->matching->rankFinancing($this->matching->organizationProfile($strongSme))[0]['score'];
        $weakScore   = $this->matching->rankFinancing($this->matching->organizationProfile($weakSme))[0]['score'];

        $this->assertGreaterThan($weakScore, $strongScore);
    }

    public function test_matching_only_considers_approved_offers_from_verified_providers(): void
    {
        $verifiedBank   = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $unverifiedBank = $this->createOrganization(['type_code' => 'bank', 'status' => 'submitted']);

        $this->createFinancingProduct($verifiedBank, ['name_ar' => 'منتج معتمد']);
        $this->createFinancingProduct($verifiedBank, [
            'name_ar' => 'منتج مسودة', 'status' => 'draft', 'published_at' => null,
        ]);
        $this->createFinancingProduct($unverifiedBank, ['name_ar' => 'منتج جهة غير موثّقة']);

        $smeId  = $this->createOrganization(['status' => 'verified']);
        $ranked = $this->matching->rankFinancing($this->matching->organizationProfile($smeId));

        $this->assertCount(1, $ranked);
        $this->assertSame('منتج معتمد', $ranked[0]['target']['name_ar']);
    }

    /** الأولويات تُترجَم إلى خدمات | Priorities steer service suggestions. */
    public function test_assessment_priorities_raise_matching_service_types(): void
    {
        $providerId = $this->createOrganization(['type_code' => 'service_provider', 'status' => 'verified']);

        $this->createServiceOffering($providerId, [
            'name_ar' => 'خدمة محاسبية', 'service_type' => 'accounting',
        ]);
        $this->createServiceOffering($providerId, [
            'name_ar' => 'خدمة تصميم', 'service_type' => 'design',
        ]);

        $smeId = $this->createOrganization(['status' => 'verified']);

        // تقييم أولويته «الإدارة المالية» يجب أن يرفع الخدمة المحاسبية
        $assessment = ['id' => null, 'priority_sections' => 'finance'];

        $ranked = $this->matching->rankServices(
            $this->matching->organizationProfile($smeId),
            $assessment,
        );

        $this->assertSame('خدمة محاسبية', $ranked[0]['target']['name_ar']);
        $this->assertStringContainsString(
            'الإدارة المالية',
            implode(' ', $ranked[0]['reasons']),
            'يجب أن يُفسَّر الاقتراح بالمجال الذي رفعه.',
        );
    }

    public function test_every_suggestion_carries_a_reason(): void
    {
        $bankId     = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $providerId = $this->createOrganization(['type_code' => 'service_provider', 'status' => 'verified']);
        $this->createFinancingProduct($bankId);
        $this->createServiceOffering($providerId);

        $smeId = $this->createOrganization(['status' => 'verified']);
        $this->matching->refreshFor($smeId);

        $rows = Database::select(
            'SELECT reasons_ar FROM match_suggestions WHERE organization_id = ?',
            [$smeId],
        );

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertNotSame(
                '',
                trim((string) $row['reasons_ar']),
                'اقتراح بلا تفسير صندوق أسود.',
            );
        }
    }

    public function test_refreshing_suggestions_is_idempotent(): void
    {
        $bankId = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $this->createFinancingProduct($bankId);

        $smeId = $this->createOrganization(['status' => 'verified']);

        $this->matching->refreshFor($smeId);
        $first = $this->countRows('match_suggestions', 'organization_id = ?', [$smeId]);

        $this->matching->refreshFor($smeId);
        $second = $this->countRows('match_suggestions', 'organization_id = ?', [$smeId]);

        $this->assertSame($first, $second, 'إعادة التوليد لا تكرّر الاقتراحات.');
    }

    /** الاقتراح المخفي يبقى مخفياً | A dismissed suggestion stays dismissed. */
    public function test_a_dismissed_suggestion_is_not_resurrected_by_a_refresh(): void
    {
        $bankId = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $this->createFinancingProduct($bankId);

        $smeId = $this->createOrganization(['status' => 'verified']);
        $this->matching->refreshFor($smeId);

        $suggestionId = (int) Database::scalar(
            'SELECT id FROM match_suggestions WHERE organization_id = ? LIMIT 1',
            [$smeId],
        );

        $this->matching->dismiss($suggestionId, $smeId, 'غير مناسب لنا.');
        $this->matching->refreshFor($smeId);

        $this->assertDatabaseHas('match_suggestions', ['id' => $suggestionId, 'status' => 'dismissed']);
        $this->assertSame([], $this->matching->storedFor($smeId, 'financing_product'));
    }

    public function test_another_organization_cannot_dismiss_a_suggestion(): void
    {
        $bankId = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $this->createFinancingProduct($bankId);

        $smeId = $this->createOrganization(['status' => 'verified']);
        $this->matching->refreshFor($smeId);

        $suggestionId = (int) Database::scalar(
            'SELECT id FROM match_suggestions WHERE organization_id = ? LIMIT 1',
            [$smeId],
        );

        $intruder = $this->createOrganization(['status' => 'verified']);
        $this->matching->dismiss($suggestionId, $intruder, 'محاولة');

        $this->assertDatabaseHas('match_suggestions', ['id' => $suggestionId, 'status' => 'suggested']);
    }

    public function test_stored_suggestions_never_cross_organizations(): void
    {
        $bankId = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $this->createFinancingProduct($bankId);

        $first  = $this->createOrganization(['status' => 'verified']);
        $second = $this->createOrganization(['status' => 'verified']);

        $this->matching->refreshFor($first);

        $this->assertNotSame([], $this->matching->storedFor($first, 'financing_product'));
        $this->assertSame([], $this->matching->storedFor($second, 'financing_product'));
    }

    /**
     * الامتناع عن الإفصاح ليس إقراراً بالانخفاض | "Prefer not to say" is unknown, not zero.
     *
     * معاملته كصفر تعاقب من مارس حقه في عدم الإفصاح، وتُسقطه من منتجات قد يستوفيها.
     */
    public function test_undisclosed_revenue_is_treated_as_unknown_not_as_zero(): void
    {
        $bankId = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $this->createFinancingProduct($bankId, ['min_annual_revenue' => 1_000_000.00]);

        $silentSme = $this->createOrganization(['status' => 'verified']);
        $lowSme    = $this->createOrganization(['status' => 'verified']);

        $this->attachProfile($silentSme, ['annual_revenue_range' => 'prefer_not_say']);
        $this->attachProfile($lowSme, ['annual_revenue_range' => 'under_250k']);

        $silentScore = $this->matching->rankFinancing($this->matching->organizationProfile($silentSme))[0]['score'];
        $lowScore    = $this->matching->rankFinancing($this->matching->organizationProfile($lowSme))[0]['score'];

        $this->assertGreaterThan(
            $lowScore,
            $silentScore,
            'الامتناع عن الإفصاح لا يجوز أن يُعامَل كإيراد منخفض.',
        );
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /**
     * إجابات كاملة على كل الأسئلة | A complete answer set.
     *
     * @return array<int,string>
     */
    private function answers(bool $strong): array
    {
        $questions = Database::select('SELECT * FROM assessment_questions WHERE is_active = 1');
        $answers   = [];

        foreach ($questions as $question) {
            $answers[(int) $question['id']] = match ((string) $question['answer_type']) {
                'scale'   => $strong ? '5' : '1',
                'boolean' => $strong ? '1' : '0',
                'choice'  => $this->choiceAnswer($question, $strong),
                'number'  => '10',
                default   => 'إجابة نصّية',
            };
        }

        return $answers;
    }

    /** @param array<string,mixed> $question */
    private function choiceAnswer(array $question, bool $strong): string
    {
        $options = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', (string) $question['options_ar']) ?: []),
            static fn (string $o): bool => $o !== '',
        ));

        if ($options === []) {
            return '';
        }

        return $strong ? $options[count($options) - 1] : $options[0];
    }

    /** @param array<string,mixed> $attributes */
    private function attachProfile(int $organizationId, array $attributes = []): void
    {
        $data    = array_merge(['organization_id' => $organizationId], $attributes);
        $columns = array_keys($data);

        Database::insert(
            'INSERT INTO sme_profiles (`' . implode('`, `', $columns) . '`) VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')',
            array_values($data),
        );
    }
}
