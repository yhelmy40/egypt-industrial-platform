<?php
/**
 * ChallengeMatch.php
 * نموذج الربط + محرك المطابقة القائم على القواعد.
 * Challenge–researcher matches + a simple rule-based matching engine.
 *
 * منطق المطابقة | Matching logic (no AI, deterministic rules):
 *   - استخراج الكلمات المفتاحية من التحدي (الخبرة المطلوبة + العنوان + الوصف).
 *   - استخراج كلمات الخبرة + التخصص من ملف الباحث/الخبير.
 *   - حساب التقاطع بين الكلمتين => عدد الكلمات المشتركة.
 *   - مكافأة إضافية إذا كان القطاع مذكوراً ضمن خبرات الباحث.
 *   - الدرجة النهائية = (الكلمات المشتركة × 10) + مكافأة القطاع.
 */
class ChallengeMatch extends Model
{
    protected string $table = 'challenge_matches';

    /** الكلمات الشائعة التي تُستبعد من المطابقة | Arabic/English stop words */
    private const STOP_WORDS = [
        'في', 'من', 'على', 'عن', 'إلى', 'مع', 'هذا', 'هذه', 'التي', 'الذي',
        'و', 'أو', 'تحسين', 'تطوير', 'تقليل', 'استخدام', 'عبر', 'خلال',
        'the', 'a', 'an', 'of', 'in', 'on', 'and', 'or', 'to', 'for', 'using', 'with',
    ];

    /**
     * حساب أفضل المطابقات لتحدٍّ معيّن.
     * Compute ranked matches for a given challenge against all profiles.
     *
     * @param array $challenge صف التحدي (يتضمن needed_expertise/title/description/sector)
     * @param array $profiles  كل ملفات الباحثين/الخبراء
     * @param string $sectorName اسم القطاع العربي (اختياري لمكافأة القطاع)
     * @return array قائمة مرتّبة تنازلياً: [profile, score, matched_keywords]
     */
    public function computeMatches(array $challenge, array $profiles, string $sectorName = ''): array
    {
        $challengeText = implode(' ', [
            $challenge['needed_expertise'] ?? '',
            $challenge['title'] ?? '',
            $challenge['description'] ?? '',
        ]);
        $challengeKeywords = $this->tokenize($challengeText);

        $results = [];
        foreach ($profiles as $p) {
            $profileText = implode(' ', [
                $p['expertise_keywords'] ?? '',
                $p['specialization'] ?? '',
                $p['previous_projects'] ?? '',
            ]);
            $profileKeywords = $this->tokenize($profileText);

            $matched = array_values(array_intersect($challengeKeywords, $profileKeywords));
            $score = count($matched) * 10;

            // مكافأة القطاع | Sector bonus
            if ($sectorName !== '') {
                $sectorTokens = $this->tokenize($sectorName);
                if (array_intersect($sectorTokens, $profileKeywords)) {
                    $score += 15;
                }
            }

            if ($score > 0) {
                $results[] = [
                    'profile'         => $p,
                    'score'           => $score,
                    'matched_keywords'=> $matched,
                ];
            }
        }

        // ترتيب تنازلي حسب الدرجة | Sort by score descending
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return $results;
    }

    /** تقطيع النص إلى كلمات مفتاحية منظّفة | Tokenize text into clean keywords */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        // استبدال الفواصل والرموز بمسافات | normalize separators
        $text = preg_replace('/[،,;|\/\.\-_()]+/u', ' ', $text);
        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $parts = array_filter($parts, function ($w) {
            return mb_strlen($w) >= 2 && !in_array($w, self::STOP_WORDS, true);
        });
        return array_values(array_unique($parts));
    }

    // ---- persistence ----

    public function deleteForChallenge(int $challengeId): bool
    {
        return $this->execute("DELETE FROM challenge_matches WHERE challenge_id = ?", [$challengeId]);
    }

    public function add(int $challengeId, int $profileId, int $score, string $keywords): int
    {
        return $this->insert([
            'challenge_id'    => $challengeId,
            'profile_id'      => $profileId,
            'match_score'     => $score,
            'matched_keywords'=> $keywords,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    /** المطابقات المحفوظة لتحدٍّ مع بيانات الملف | Stored matches with profile data */
    public function savedForChallenge(int $challengeId): array
    {
        return $this->query(
            "SELECT cm.*, rp.name AS profile_name, rp.organization, rp.specialization, rp.profile_type
             FROM challenge_matches cm
             JOIN researcher_profiles rp ON rp.id = cm.profile_id
             WHERE cm.challenge_id = ?
             ORDER BY cm.match_score DESC",
            [$challengeId]
        );
    }
}
