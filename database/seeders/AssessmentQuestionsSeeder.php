<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * أسئلة تقييم الاحتياجات | Needs assessment questions (§4.7).
 *
 * بيانات تشغيلية لا كود: الإدارة تعدّل الأسئلة والأوزان من لوحة التحكم، فتغيير
 * الاستبيان لا يحتاج نشر إصدار.
 *
 * ملاحظة على الصياغة: كل سؤال يقيس **ممارسة** لا نيّة — «هل تُعدّ قائمة دخل
 * شهرية؟» لا «هل تهتم بالمالية؟». السؤال عن النيّة يجمع إجابات مجاملة لا
 * تصلح أساساً لاقتراح.
 *
 * Each question measures a practice, not an intention: asking about intent
 * collects polite answers that make a poor basis for a suggestion.
 */
final class AssessmentQuestionsSeeder extends Seeder
{
    public function order(): int
    {
        return 16;
    }

    public function run(): void
    {
        foreach ($this->questions() as $index => $question) {
            $this->upsert('assessment_questions', [
                'code'        => $question['code'],
                'section'     => $question['section'],
                'question_ar' => $question['question'],
                'help_ar'     => $question['help'] ?? null,
                'answer_type' => $question['type'],
                'options_ar'  => isset($question['options']) ? implode("\n", $question['options']) : null,
                'is_required' => 1,
                'weight'      => $question['weight'] ?? 1,
                'is_active'   => 1,
                'sort_order'  => ($index + 1) * 10,
            ], ['code']);
        }

        $this->info(count($this->questions()) . ' سؤالاً في استبيان تقييم الاحتياجات.');
    }

    /** @return array<int,array<string,mixed>> */
    private function questions(): array
    {
        return [
            // ─── الإدارة المالية | Financial management ───
            [
                'code' => 'fin_records', 'section' => 'finance', 'type' => 'choice', 'weight' => 2,
                'question' => 'كيف تُسجَّل إيرادات ومصروفات المشروع؟',
                'options' => [
                    'لا تُسجَّل بانتظام',
                    'دفتر ورقي أو ملاحظات',
                    'ملف إلكتروني (إكسل أو ما شابه)',
                    'برنامج محاسبي أو نظام إلكتروني',
                ],
            ],
            [
                'code' => 'fin_statements', 'section' => 'finance', 'type' => 'boolean', 'weight' => 2,
                'question' => 'هل تُعدّ قائمة دخل أو تقرير أرباح دوري (شهري أو ربع سنوي)؟',
            ],
            [
                'code' => 'fin_separation', 'section' => 'finance', 'type' => 'boolean',
                'question' => 'هل حساب المشروع منفصل عن الحساب الشخصي؟',
                'help' => 'الخلط بين الحسابين يصعّب إثبات التدفّق النقدي عند طلب التمويل.',
            ],
            [
                'code' => 'fin_cashflow', 'section' => 'finance', 'type' => 'scale',
                'question' => 'إلى أي مدى تستطيع توقّع احتياجك النقدي للشهر القادم؟',
                'help' => 'من ١ (لا أستطيع) إلى ٥ (بدقة).',
            ],

            // ─── التسويق والمبيعات | Marketing and sales ───
            [
                'code' => 'mkt_channels', 'section' => 'market', 'type' => 'choice', 'weight' => 2,
                'question' => 'كيف يصل عملاؤك إليك غالباً؟',
                'options' => [
                    'المعرفة الشخصية فقط',
                    'الموقع الجغرافي والمارّة',
                    'صفحات التواصل الاجتماعي',
                    'قنوات متعدّدة تشمل البيع الإلكتروني',
                ],
            ],
            [
                'code' => 'mkt_pricing', 'section' => 'market', 'type' => 'boolean',
                'question' => 'هل تحسب تكلفة المنتج أو الخدمة قبل تحديد سعرها؟',
            ],
            [
                'code' => 'mkt_customers', 'section' => 'market', 'type' => 'scale',
                'question' => 'إلى أي مدى تعرف من هم عملاؤك الأكثر ربحية؟',
            ],
            [
                'code' => 'mkt_export', 'section' => 'market', 'type' => 'choice',
                'question' => 'ما موقفك من التصدير؟',
                'options' => [
                    'غير مهتم حالياً',
                    'مهتم ولم أبدأ',
                    'أستعدّ للتصدير',
                    'أصدّر بالفعل',
                ],
            ],

            // ─── التشغيل والإنتاج | Operations ───
            [
                'code' => 'ops_capacity', 'section' => 'operations', 'type' => 'scale', 'weight' => 2,
                'question' => 'إلى أي مدى تستطيع تلبية طلب يزيد عن معدّلك الحالي بمقدار الضعف؟',
            ],
            [
                'code' => 'ops_inventory', 'section' => 'operations', 'type' => 'choice',
                'question' => 'كيف تتابع المخزون أو المستلزمات؟',
                'options' => [
                    'بالملاحظة دون تسجيل',
                    'جرد يدوي دوري',
                    'ملف إلكتروني',
                    'نظام يتابع الكميات آلياً',
                ],
            ],
            [
                'code' => 'ops_suppliers', 'section' => 'operations', 'type' => 'scale',
                'question' => 'إلى أي مدى لديك بدائل جاهزة لمورّديك الأساسيين؟',
            ],

            // ─── التحوّل الرقمي | Digital ───
            [
                'code' => 'dig_presence', 'section' => 'digital', 'type' => 'choice', 'weight' => 2,
                'question' => 'ما حضور مشروعك الرقمي؟',
                'options' => [
                    'لا حضور رقمي',
                    'صفحة على منصة تواصل',
                    'صفحة نشطة مع ردّ منتظم',
                    'موقع أو متجر إلكتروني عامل',
                ],
            ],
            [
                'code' => 'dig_payments', 'section' => 'digital', 'type' => 'boolean',
                'question' => 'هل تقبل وسيلة دفع إلكترونية واحدة على الأقل؟',
            ],
            [
                'code' => 'dig_skills', 'section' => 'digital', 'type' => 'scale',
                'question' => 'إلى أي مدى يستطيع فريقك استخدام الأدوات الرقمية دون مساعدة خارجية؟',
            ],

            // ─── التقنين والامتثال | Compliance ───
            [
                'code' => 'cmp_registration', 'section' => 'compliance', 'type' => 'choice', 'weight' => 2,
                'question' => 'ما وضع مشروعك القانوني؟',
                'options' => [
                    'غير مقنَّن',
                    'في إجراءات التقنين',
                    'مقنَّن بسجل تجاري',
                    'مقنَّن بسجل وبطاقة ضريبية سارية',
                ],
            ],
            [
                'code' => 'cmp_licenses', 'section' => 'compliance', 'type' => 'boolean',
                'question' => 'هل تراخيص مزاولة النشاط سارية؟',
            ],
            [
                'code' => 'cmp_contracts', 'section' => 'compliance', 'type' => 'scale',
                'question' => 'إلى أي مدى توثَّق تعاملاتك مع العملاء والمورّدين بعقود أو مستندات؟',
            ],

            // ─── المهارات والفريق | Skills and team ───
            [
                'code' => 'skl_training', 'section' => 'skills', 'type' => 'boolean',
                'question' => 'هل حصل أحد من فريقك على تدريب مهني خلال العام الماضي؟',
            ],
            [
                'code' => 'skl_delegation', 'section' => 'skills', 'type' => 'scale', 'weight' => 2,
                'question' => 'إلى أي مدى يستمرّ العمل بكفاءة في غيابك أسبوعاً كاملاً؟',
                'help' => 'مؤشّر على توزيع المهام واستقلال الفريق.',
            ],
            [
                'code' => 'skl_hiring', 'section' => 'skills', 'type' => 'scale',
                'question' => 'إلى أي مدى تجد العمالة الماهرة التي يحتاجها مشروعك؟',
            ],
        ];
    }
}
