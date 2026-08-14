<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;

/**
 * تقييم احتياجات المشروع | SME needs assessment (§4.7).
 *
 * استبيان قصير يملؤه صاحب المشروع بنفسه، ينتج عنه درجة لكل مجال وأولويتان.
 * النتيجة **وصف لحالة معلنة ذاتياً**، لا تقييم ائتماني ولا تصنيف جدارة: كل
 * إجابة صرّح بها صاحب المشروع دون تحقّق مستندي، ولذلك تُستخدم للإرشاد
 * والاقتراح فقط (§14).
 *
 * A short self-completed questionnaire producing a score per area and two
 * priorities. It describes a self-declared position — never a credit score.
 */
final class AssessmentService
{
    /** مجالات التقييم | Assessment sections. */
    public const SECTIONS = [
        'finance'    => 'الإدارة المالية',
        'market'     => 'التسويق والمبيعات',
        'operations' => 'التشغيل والإنتاج',
        'digital'    => 'التحوّل الرقمي',
        'compliance' => 'التقنين والامتثال',
        'skills'     => 'المهارات والفريق',
    ];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * أسئلة الاستبيان مجمّعة بالمجال | Active questions grouped by section.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function questions(): array
    {
        $rows = Database::select(
            'SELECT * FROM assessment_questions WHERE is_active = 1 ORDER BY section ASC, sort_order ASC, id ASC',
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row['section']][] = $row;
        }

        // الترتيب يتبع ترتيب المجالات المعرّف أعلاه لا ترتيب قاعدة البيانات
        $ordered = [];
        foreach (array_keys(self::SECTIONS) as $section) {
            if (isset($grouped[$section])) {
                $ordered[$section] = $grouped[$section];
            }
        }

        return $ordered;
    }

    /** آخر تقييم مكتمل للمنشأة | The organization's latest completed assessment. */
    public function latestCompleted(int $organizationId): ?array
    {
        return Database::selectOne(
            "SELECT * FROM needs_assessments
              WHERE organization_id = ? AND status = 'completed' AND deleted_at IS NULL
              ORDER BY completed_at DESC, id DESC LIMIT 1",
            [$organizationId],
        );
    }

    /** مسودة التقييم الجارية أو واحدة جديدة | The open draft, or a new one. */
    public function openDraft(int $organizationId): array
    {
        $draft = Database::selectOne(
            "SELECT * FROM needs_assessments
              WHERE organization_id = ? AND status = 'draft' AND deleted_at IS NULL
              ORDER BY id DESC LIMIT 1",
            [$organizationId],
        );

        if ($draft !== null) {
            return $draft;
        }

        $id = Database::insert(
            "INSERT INTO needs_assessments (organization_id, status) VALUES (?, 'draft')",
            [$organizationId],
        );

        return Database::selectOne('SELECT * FROM needs_assessments WHERE id = ?', [$id]) ?? [];
    }

    /**
     * إجابات تقييم | The answers of an assessment, keyed by question id.
     *
     * @return array<int,array<string,mixed>>
     */
    public function answers(int $assessmentId): array
    {
        $rows = Database::select(
            'SELECT * FROM assessment_answers WHERE assessment_id = ?',
            [$assessmentId],
        );

        $byQuestion = [];
        foreach ($rows as $row) {
            $byQuestion[(int) $row['question_id']] = $row;
        }

        return $byQuestion;
    }

    /**
     * حفظ الإجابات وإكمال التقييم | Save answers and complete the assessment.
     *
     * @param  array<int|string,mixed> $input معرّف السؤال => الإجابة
     * @return array<string,mixed> التقييم بعد الاحتساب
     */
    public function complete(
        int $assessmentId,
        int $organizationId,
        array $input,
        ?int $actorId,
        Request $request,
    ): array {
        $assessment = Database::selectOne(
            'SELECT * FROM needs_assessments
              WHERE id = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1',
            [$assessmentId, $organizationId],
        );

        if ($assessment === null) {
            throw new HttpException(404, 'التقييم غير موجود.');
        }

        if ($assessment['status'] === 'completed') {
            throw new HttpException(422, 'هذا التقييم مكتمل. ابدأ تقييماً جديداً للتحديث.');
        }

        $questions = Database::select('SELECT * FROM assessment_questions WHERE is_active = 1');

        if ($questions === []) {
            throw new HttpException(422, 'لم تُضبط أسئلة التقييم بعد. تواصل مع إدارة المنصة.');
        }

        $missing = [];

        foreach ($questions as $question) {
            $id    = (int) $question['id'];
            $value = $input[$id] ?? null;

            if ((int) $question['is_required'] === 1 && ($value === null || trim((string) $value) === '')) {
                $missing[] = (string) $question['question_ar'];
            }
        }

        if ($missing !== []) {
            throw new HttpException(
                422,
                'أجب عن كل الأسئلة المطلوبة. الناقص: ' . implode('، ', array_slice($missing, 0, 3))
                . (count($missing) > 3 ? ' وغيرها.' : ''),
            );
        }

        return Database::transaction(function () use (
            $assessmentId, $organizationId, $questions, $input, $actorId, $request
        ): array {
            /** @var array<string,array<int,array{score:int,weight:int}>> $bySection */
            $bySection = [];

            foreach ($questions as $question) {
                $id      = (int) $question['id'];
                $raw     = $input[$id] ?? null;
                $score   = $this->normalise($question, $raw);
                $section = (string) $question['section'];

                Database::statement(
                    'INSERT INTO assessment_answers
                        (assessment_id, organization_id, question_id, answer_value, answer_score)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE answer_value = VALUES(answer_value),
                                             answer_score = VALUES(answer_score)',
                    [
                        $assessmentId,
                        $organizationId,
                        $id,
                        $raw === null ? null : mb_substr((string) $raw, 0, 500),
                        $score,
                    ],
                );

                if ($score !== null) {
                    $bySection[$section][] = ['score' => $score, 'weight' => max(1, (int) $question['weight'])];
                }
            }

            $scores = $this->sectionScores($bySection);

            // الأولويتان هما الأضعف: هما ما يستحقّ اقتراحاً وتدخّلاً
            $priorities = $this->weakestSections($scores);

            Database::statement(
                "UPDATE needs_assessments
                    SET status = 'completed', completed_at = NOW(), completed_by = ?,
                        score_finance = ?, score_market = ?, score_operations = ?,
                        score_digital = ?, score_compliance = ?, score_skills = ?,
                        score_overall = ?, priority_sections = ?
                  WHERE id = ?",
                [
                    $actorId,
                    $scores['finance'] ?? null,
                    $scores['market'] ?? null,
                    $scores['operations'] ?? null,
                    $scores['digital'] ?? null,
                    $scores['compliance'] ?? null,
                    $scores['skills'] ?? null,
                    $this->overall($scores),
                    $priorities === [] ? null : implode(',', $priorities),
                    $assessmentId,
                ],
            );

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'needs_assessment.completed',
                category: AuditLogger::CATEGORY_RECORD,
                entityType: 'needs_assessment',
                entityId: $assessmentId,
                description: 'إكمال تقييم احتياجات المشروع',
                userId: $actorId,
                organizationId: $organizationId,
            );

            return Database::selectOne('SELECT * FROM needs_assessments WHERE id = ?', [$assessmentId]) ?? [];
        });
    }

    public function sectionLabel(string $section): string
    {
        return self::SECTIONS[$section] ?? $section;
    }

    /** وصف الدرجة بكلمات | A plain-language reading of a score. */
    public function scoreLabel(?int $score): string
    {
        if ($score === null) {
            return 'غير مُقاس';
        }

        return match (true) {
            $score >= 75 => 'جيد',
            $score >= 50 => 'مقبول',
            $score >= 25 => 'يحتاج تطويراً',
            default      => 'أولوية للتدخّل',
        };
    }

    public function scoreBadgeClass(?int $score): string
    {
        if ($score === null) {
            return 'np-badge--muted';
        }

        return match (true) {
            $score >= 75 => 'np-badge--success',
            $score >= 50 => 'np-badge--info',
            $score >= 25 => 'np-badge--pending',
            default      => 'np-badge--danger',
        };
    }

    // ─────────────────── الاحتساب | Scoring ───────────────────

    /**
     * تطبيع الإجابة إلى 0-100 | Normalise an answer onto a 0–100 scale.
     *
     * @param array<string,mixed> $question
     */
    private function normalise(array $question, mixed $raw): ?int
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return match ((string) $question['answer_type']) {
            // مقياس من 1 إلى 5 | A 1–5 scale
            'scale'   => (int) round((max(1, min(5, (int) $raw)) - 1) / 4 * 100),
            'boolean' => in_array((string) $raw, ['1', 'yes', 'true'], true) ? 100 : 0,
            // الاختيار: ترتيب الخيار يحدّد درجته، الأول أضعفها
            'choice'  => $this->choiceScore($question, (string) $raw),
            // الأرقام والنصوص لا تُسجَّل درجةً: تُجمع للسياق لا للقياس
            default   => null,
        };
    }

    /** @param array<string,mixed> $question */
    private function choiceScore(array $question, string $value): ?int
    {
        $options = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', (string) $question['options_ar']) ?: []),
            static fn (string $o): bool => $o !== '',
        ));

        if (count($options) < 2) {
            return null;
        }

        $index = array_search($value, $options, true);

        if ($index === false) {
            return null;
        }

        return (int) round((int) $index / (count($options) - 1) * 100);
    }

    /**
     * درجة كل مجال | Weighted score per section.
     *
     * @param  array<string,array<int,array{score:int,weight:int}>> $bySection
     * @return array<string,int>
     */
    private function sectionScores(array $bySection): array
    {
        $scores = [];

        foreach ($bySection as $section => $entries) {
            $weightSum = 0;
            $total     = 0;

            foreach ($entries as $entry) {
                $total     += $entry['score'] * $entry['weight'];
                $weightSum += $entry['weight'];
            }

            if ($weightSum > 0) {
                $scores[$section] = (int) round($total / $weightSum);
            }
        }

        return $scores;
    }

    /** @param array<string,int> $scores */
    private function overall(array $scores): ?int
    {
        return $scores === [] ? null : (int) round(array_sum($scores) / count($scores));
    }

    /**
     * أضعف مجالين | The two weakest sections.
     *
     * @param  array<string,int> $scores
     * @return array<int,string>
     */
    private function weakestSections(array $scores): array
    {
        if ($scores === []) {
            return [];
        }

        asort($scores);

        return array_slice(array_keys($scores), 0, 2);
    }
}
