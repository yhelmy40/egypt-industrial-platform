<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;

/**
 * حالات دعم تجريبية | Demonstration BDS cases (§15).
 *
 * ⚠ بيانات تجريبية بالكامل: المشروعات والمراكز والملاحظات كلها مُختلقة لعرض
 * المنصة. لا تمثّل حالة دعم حقيقية ولا رأي أخصائي عن منشأة قائمة.
 *
 * الغرض: أن يجد المُراجِع طابوراً فيه حالة تنتظر الفرز، وحالة نشطة لها جلسة
 * وخطة عمل وملاحظة داخلية، وحالة مغلقة بنتيجة — فيرى الحدّ بين ما يراه المركز
 * وما يراه المشروع عملياً لا وصفاً.
 */
final class DemoBdsCasesSeeder extends Seeder
{
    public function isDemo(): bool
    {
        return true;
    }

    public function order(): int
    {
        return 80;
    }

    public function run(): void
    {
        $this->guardProduction();

        $centerId = $this->organizationId('مركز-تطوير-الأعمال-القاهرة');
        $smeId    = $this->organizationId('مصنع-النيل-للصناعات-الغذائية');

        if ($centerId === null || $smeId === null) {
            $this->info('تخطّي حالات الدعم التجريبية: المنشآت المطلوبة غير موجودة.');

            return;
        }

        $specialistId = $this->centerMemberId($centerId);
        $created      = 0;

        // ─── حالة تنتظر الفرز ───
        $created += $this->seedCase([
            'number'   => 'BDS-DEMO-0001',
            'sme'      => $smeId,
            'center'   => $centerId,
            'title'    => 'تنظيم الحسابات وإعداد تقارير شهرية (تجريبي)',
            'details'  => 'نسجّل المبيعات في دفتر ورقي ولا نعرف ربحية كل صنف. نحتاج '
                . 'مساعدة في ترتيب الحسابات وإعداد تقرير شهري يمكن الاعتماد عليه.',
            'focus'    => 'finance,operations',
            'status'   => 'requested',
        ]);

        // ─── حالة نشطة: أخصائي + جلسة + خطة + ملاحظتان ───
        $activeId = $this->seedCaseAndReturnId([
            'number'   => 'BDS-DEMO-0002',
            'sme'      => $smeId,
            'center'   => $centerId,
            'title'    => 'الاستعداد للتصدير وفتح سوق خارجي (تجريبي)',
            'details'  => 'لدينا طلبات من خارج مصر ولا نعرف متطلبات التصدير ولا كيف نسعّر '
                . 'للسوق الخارجي. نحتاج إرشاداً عملياً بخطوات واضحة.',
            'focus'    => 'market,compliance',
            'status'   => 'in_progress',
            'assigned' => $specialistId,
        ]);

        if ($activeId !== null) {
            $created++;
            $this->seedActiveCaseContent($activeId, $smeId, $centerId, $specialistId);
        }

        // ─── حالة مغلقة بنتيجة وتقييم ───
        $created += $this->seedCase([
            'number'   => 'BDS-DEMO-0003',
            'sme'      => $smeId,
            'center'   => $centerId,
            'title'    => 'تحسين تغليف المنتج وهويته (تجريبي)',
            'details'  => 'التغليف الحالي لا يجذب في نقاط البيع ونحتاج تطويره.',
            'focus'    => 'market',
            'status'   => 'closed_completed',
            'assigned' => $specialistId,
            'outcome'  => 'اكتمل الدعم: أُعيد تصميم التغليف بمساعدة مقدّم خدمة مُحال إليه، '
                . 'وارتفعت مبيعات الصنف في نقاط البيع خلال شهرين. (نتيجة تجريبية للعرض.)',
            'rating'   => 5,
        ]);

        $this->info("{$created} حالة دعم تجريبية بجلساتها وخططها.");
    }

    // ─────────────────── إنشاء الحالات | Case creation ───────────────────

    /** @param array<string,mixed> $definition */
    private function seedCase(array $definition): int
    {
        return $this->seedCaseAndReturnId($definition) === null ? 0 : 1;
    }

    /** @param array<string,mixed> $definition */
    private function seedCaseAndReturnId(array $definition): ?int
    {
        $existing = Database::selectOne(
            'SELECT id FROM bds_cases WHERE case_number = ? LIMIT 1',
            [$definition['number']],
        );

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $isClosed = str_starts_with((string) $definition['status'], 'closed_');

        $caseId = Database::insert(
            'INSERT INTO bds_cases
                (case_number, organization_id, center_organization_id, assigned_to, assigned_at,
                 title_ar, request_details_ar, focus_areas, status, priority,
                 closed_at, outcome_summary_ar, satisfaction_rating)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $definition['number'],
                $definition['sme'],
                $definition['center'],
                $definition['assigned'] ?? null,
                isset($definition['assigned']) ? date('Y-m-d H:i:s') : null,
                $definition['title'],
                $definition['details'],
                $definition['focus'],
                $definition['status'],
                'normal',
                $isClosed ? date('Y-m-d H:i:s') : null,
                $definition['outcome'] ?? null,
                $definition['rating'] ?? null,
            ],
        );

        Database::statement(
            "INSERT INTO bds_case_history
                (case_id, from_status, to_status, actor_side, note_ar)
             VALUES (?, NULL, 'requested', 'organization', 'طلب دعم تجريبي')",
            [$caseId],
        );

        if ($definition['status'] !== 'requested') {
            Database::statement(
                "INSERT INTO bds_case_history
                    (case_id, from_status, to_status, actor_side, note_ar)
                 VALUES (?, 'requested', ?, 'center', 'انتقال تجريبي')",
                [$caseId, $definition['status']],
            );
        }

        return $caseId;
    }

    /**
     * محتوى الحالة النشطة | The active case's sessions, plan and notes.
     *
     * الملاحظتان مقصودتان: واحدة داخلية وواحدة مشتركة، ليظهر الفرق بين ما يراه
     * المركز وما يراه المشروع في نفس الحالة.
     */
    private function seedActiveCaseContent(int $caseId, int $smeId, int $centerId, ?int $specialistId): void
    {
        if (Database::selectOne('SELECT id FROM bds_case_notes WHERE case_id = ? LIMIT 1', [$caseId]) !== null) {
            return;
        }

        // ملاحظة داخلية — لا يراها المشروع
        Database::statement(
            "INSERT INTO bds_case_notes
                (case_id, organization_id, center_organization_id, visibility, body_ar,
                 author_user_id, author_side)
             VALUES (?, ?, ?, 'internal', ?, ?, 'center')",
            [
                $caseId, $smeId, $centerId,
                'ملاحظة داخلية تجريبية: الملف المالي للمشروع غير مكتمل، ويُفضَّل التركيز '
                . 'على التقنين قبل الحديث عن التصدير. لا تُذكر هذه الملاحظة للمشروع بهذه الصيغة.',
                $specialistId,
            ],
        );

        // ملاحظة مشتركة — يراها الطرفان
        Database::statement(
            "INSERT INTO bds_case_notes
                (case_id, organization_id, center_organization_id, visibility, body_ar,
                 author_user_id, author_side)
             VALUES (?, ?, ?, 'shared', ?, ?, 'center')",
            [
                $caseId, $smeId, $centerId,
                'رجاءً جهّز قبل الجلسة القادمة: بيان الإنتاج الشهري، وقائمة الأسواق المستهدفة، '
                . 'وصور المنتج بتغليفه الحالي.',
                $specialistId,
            ],
        );

        // جلسة قادمة
        Database::statement(
            "INSERT INTO bds_consultations
                (case_id, organization_id, center_organization_id, title_ar, scheduled_at,
                 duration_minutes, mode, location_ar, status, specialist_user_id)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), 90, 'onsite', ?, 'scheduled', ?)",
            [
                $caseId, $smeId, $centerId,
                'جلسة تشخيص جاهزية التصدير (تجريبية)',
                'مقر المركز — القاهرة',
                $specialistId,
            ],
        );

        // جلسة سابقة مكتملة، بملخّص وملاحظة داخلية
        Database::statement(
            "INSERT INTO bds_consultations
                (case_id, organization_id, center_organization_id, title_ar, scheduled_at,
                 duration_minutes, mode, status, summary_ar, internal_note_ar,
                 specialist_user_id, completed_at)
             VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL 10 DAY), 60, 'phone', 'completed', ?, ?, ?, DATE_SUB(NOW(), INTERVAL 10 DAY))",
            [
                $caseId, $smeId, $centerId,
                'جلسة تعارف أولى (تجريبية)',
                'ملخّص تجريبي: استعرضنا وضع المشروع الحالي واتفقنا على البدء بتشخيص '
                . 'جاهزية التصدير قبل أي خطوة تسويقية.',
                'ملاحظة داخلية تجريبية: تحمّس صاحب المشروع للتصدير أكبر من جاهزيته الفعلية؛ '
                . 'يحتاج تدرّجاً في التوقعات.',
                $specialistId,
            ],
        );

        // خطة عمل مشتركة بمهمتين
        $planId = Database::insert(
            "INSERT INTO bds_action_plans
                (case_id, organization_id, center_organization_id, title_ar, objective_ar,
                 starts_on, ends_on, status, shared_at, created_by)
             VALUES (?, ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 60 DAY), 'active', NOW(), ?)",
            [
                $caseId, $smeId, $centerId,
                'خطة جاهزية التصدير — ٦٠ يوماً (تجريبية)',
                'الوصول إلى ملف تصديري مكتمل وعرض سعر جاهز لسوق واحد مستهدف.',
                $specialistId,
            ],
        );

        foreach ([
            ['حصر متطلبات التصدير للسوق المستهدف', 'center', 14, 'done'],
            ['تجهيز بطاقة منتج بالإنجليزية مع المواصفات', 'organization', 30, 'in_progress'],
            ['استخراج شهادة المنشأ وتحديث السجل', 'organization', 45, 'pending'],
        ] as $index => [$title, $owner, $days, $status]) {
            Database::statement(
                'INSERT INTO bds_plan_tasks
                    (plan_id, organization_id, center_organization_id, title_ar, owner_side,
                     due_date, status, sort_order, completed_at)
                 VALUES (?, ?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL ? DAY), ?, ?, ?)',
                [
                    $planId, $smeId, $centerId, $title, $owner, $days, $status,
                    ($index + 1) * 10,
                    $status === 'done' ? date('Y-m-d H:i:s') : null,
                ],
            );
        }

        // توصية بعرض معتمد إن وُجد
        $offer = Database::selectOne(
            "SELECT id, name_ar FROM service_offerings
              WHERE status = 'published' AND deleted_at IS NULL ORDER BY id ASC LIMIT 1",
        );

        if ($offer !== null) {
            Database::statement(
                "INSERT INTO bds_referrals
                    (case_id, organization_id, center_organization_id, target_type, target_id,
                     target_name_ar, reason_ar, status, referred_by)
                 VALUES (?, ?, ?, 'service_offering', ?, ?, ?, 'suggested', ?)",
                [
                    $caseId, $smeId, $centerId,
                    (int) $offer['id'],
                    (string) $offer['name_ar'],
                    'توصية تجريبية: الباقة تغطّي تجهيز المستندات والعرض التسويقي المطلوبَين '
                    . 'في خطة العمل. التقديم اختياري والقرار قرارك.',
                    $specialistId,
                ],
            );
        }
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function organizationId(string $slug): ?int
    {
        $id = Database::scalar(
            'SELECT id FROM organizations WHERE slug = ? AND deleted_at IS NULL LIMIT 1',
            [$slug],
        );

        return $id === null ? null : (int) $id;
    }

    /** عضو نشط في المركز ليكون الأخصائي | An active centre member to act as specialist. */
    private function centerMemberId(int $centerId): ?int
    {
        $id = Database::scalar(
            "SELECT user_id FROM organization_members
              WHERE organization_id = ? AND status = 'active'
              ORDER BY is_primary_contact DESC, id ASC LIMIT 1",
            [$centerId],
        );

        return $id === null ? null : (int) $id;
    }
}
