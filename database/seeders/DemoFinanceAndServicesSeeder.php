<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;

/**
 * تمويل وخدمات العرض التوضيحي | Demonstration financing and services (§15).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠ **كل ما هنا بيانات تجريبية.** المؤسسات المالية والمنتجات والنِّسب والبرامج
 * والخدمات جميعها مُختلقة لعرض المنصة، وموسومة `is_demo = 1` فتظهر في الواجهة
 * بوسم «بيانات تجريبية».
 *
 * لا تمثّل أي بنك أو منظمة أو برنامج قائم، ولا تُعدّ عرضاً حقيقياً لأي تمويل.
 * أي منتج تمويلي حقيقي يجب أن تُدخله المؤسسة المالية بنفسها ويعتمده فريق
 * المنصة، ببيانات رسمية موثّقة.
 *
 * Every institution, product, rate and programme here is fabricated for
 * demonstration and flagged is_demo. None represents a real offer.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class DemoFinanceAndServicesSeeder extends Seeder
{
    public function isDemo(): bool
    {
        return true;
    }

    public function order(): int
    {
        return 75;
    }

    public function run(): void
    {
        $this->guardProduction();

        $products  = $this->seedFinancingProducts();
        $offerings = $this->seedServiceOfferings();

        $this->info("{$products} منتجاً تمويلياً و{$offerings} باقة خدمة — كلها بيانات تجريبية.");
    }

    // ─────────────────── المنتجات التمويلية | Financing products ───────────────────

    private function seedFinancingProducts(): int
    {
        $bankId = $this->demoOrganizationId('بنك-التنمية-التجريبي');

        if ($bankId === null) {
            return 0;
        }

        $approverId = $this->platformApproverId();
        $count      = 0;

        foreach ($this->products() as $product) {
            $categoryId = Database::scalar(
                "SELECT id FROM categories WHERE type = 'financial' AND code = ? LIMIT 1",
                [$product['category']],
            );

            // المعتمد منها يحمل ختم اعتماد حقيقياً؛ والباقي متروك في الطابور
            // ليجد المُراجِع شيئاً يتخذ عليه قراراً.
            $approved = $product['status'] === 'published';

            $this->upsert('financing_products', [
                'organization_id'              => $bankId,
                'category_id'                  => $categoryId === null ? null : (int) $categoryId,
                'name_ar'                      => $product['name'],
                'slug'                         => $this->slug($product['name']),
                'short_description'            => $product['short'],
                'description'                  => $product['description'],
                'financing_type'               => $product['type'],
                'min_amount'                   => $product['min'],
                'max_amount'                   => $product['max'],
                'currency_code'                => 'EGP',
                'min_tenor_months'             => $product['min_tenor'],
                'max_tenor_months'             => $product['max_tenor'],
                'rate_note_ar'                 => $product['rate'],
                'fees_note_ar'                 => $product['fees'] ?? null,
                'eligibility_summary_ar'       => $product['eligibility'],
                'required_documents_ar'        => $product['documents'],
                'min_years_in_business'        => $product['min_years'] ?? null,
                'requires_formal_registration' => $product['formal'] ?? 0,
                'status'                       => $product['status'],
                'approved_by'                  => $approved ? $approverId : null,
                'approved_at'                  => $approved ? date('Y-m-d H:i:s') : null,
                'published_at'                 => $approved ? date('Y-m-d H:i:s') : null,
                'is_demo'                      => 1,
            ], ['slug']);

            $count++;
        }

        return $count;
    }

    /** @return array<int,array<string,mixed>> */
    private function products(): array
    {
        $disclaimer = "\n\nهذا منتج تجريبي أُنشئ لعرض المنصة. الشروط والنِّسب المذكورة "
            . 'أرقام توضيحية لا تمثّل عرضاً من أي مؤسسة مالية قائمة. أي تمويل فعلي '
            . 'يخضع لقواعد الأهلية ودراسة الجدارة الائتمانية لدى المؤسسة المموّلة.';

        return [
            [
                'name'        => 'تمويل رأس مال عامل للمشروعات الصغيرة (تجريبي)',
                'category'    => 'fin_working_capital',
                'type'        => 'working_capital',
                'short'       => 'تمويل قصير الأجل لتغطية دورة التشغيل وشراء الخامات.',
                'description' => 'منتج تجريبي موجّه للمشروعات القائمة التي تحتاج سيولة لدورة '
                    . 'تشغيل قصيرة.' . $disclaimer,
                'min'         => 50000.00,
                'max'         => 500000.00,
                'min_tenor'   => 6,
                'max_tenor'   => 24,
                'rate'        => 'عائد متناقص يُحتسب وفق سياسة المؤسسة وقت التعاقد. الرقم النهائي '
                    . 'يُحدَّد بعد دراسة الطلب ولا تحتسبه المنصة.',
                'fees'        => 'مصروفات إدارية تُوضَّح في عقد التمويل.',
                'eligibility' => "مشروع قائم منذ سنة على الأقل.\nنشاط مقنَّن بسجل تجاري ساري.\n"
                    . 'حساب بنكي باسم المشروع.',
                'documents'   => "السجل التجاري.\nالبطاقة الضريبية.\nكشف حساب بنكي لآخر ستة أشهر.\n"
                    . 'بيان بالإيرادات والمصروفات.',
                'min_years'   => 1,
                'formal'      => 1,
                'status'      => 'published',
            ],
            [
                'name'        => 'تمويل شراء معدات وخطوط إنتاج (تجريبي)',
                'category'    => 'fin_equipment',
                'type'        => 'asset_finance',
                'short'       => 'تمويل متوسط الأجل لشراء آلات ومعدات إنتاجية.',
                'description' => 'منتج تجريبي لتمويل الأصول الإنتاجية بضمان الأصل الممول.' . $disclaimer,
                'min'         => 100000.00,
                'max'         => 3000000.00,
                'min_tenor'   => 12,
                'max_tenor'   => 60,
                'rate'        => 'عائد يُحدَّد حسب مدة السداد ونوع الأصل، ويُبلَّغ به مقدّم الطلب '
                    . 'قبل التعاقد.',
                'eligibility' => "مشروع قائم منذ سنتين على الأقل.\nعرض سعر رسمي للمعدات.\n"
                    . 'نشاط مقنَّن.',
                'documents'   => "السجل التجاري والبطاقة الضريبية.\nعرض سعر المورّد.\n"
                    . 'القوائم المالية لآخر سنتين.',
                'min_years'   => 2,
                'formal'      => 1,
                'status'      => 'published',
            ],
            [
                'name'        => 'تمويل متناهي الصغر للحرفيين (تجريبي)',
                'category'    => 'fin_startup',
                'type'        => 'microfinance',
                'short'       => 'مبالغ صغيرة للحرفيين وأصحاب المشروعات المنزلية، بلا اشتراط تقنين مسبق.',
                'description' => 'منتج تجريبي موجّه للحرفيين والمشروعات متناهية الصغر، '
                    . 'ويقبل المشروعات في طور التقنين.' . $disclaimer,
                'min'         => 5000.00,
                'max'         => 50000.00,
                'min_tenor'   => 6,
                'max_tenor'   => 18,
                'rate'        => 'عائد بسيط يُوضَّح في العقد. لا توجد رسوم خفية.',
                'eligibility' => "ممارسة النشاط فعلياً بما يثبته أي مستند.\nالإقامة في نطاق فرع المؤسسة.",
                'documents'   => "إثبات مزاولة النشاط (عقد إيجار المقر أو فواتير مورّدين).\n"
                    . 'صور من موقع العمل.',
                'formal'      => 0,
                'status'      => 'published',
            ],
            [
                'name'        => 'برنامج تمويل التحوّل الأخضر (تجريبي — بانتظار الاعتماد)',
                'category'    => 'fin_green',
                'type'        => 'asset_finance',
                'short'       => 'تمويل مشروعات كفاءة الطاقة والألواح الشمسية.',
                'description' => 'منتج تجريبي متروك عمداً في حالة «بانتظار الاعتماد» ليظهر في '
                    . 'طابور المراجعة الإداري.' . $disclaimer,
                'min'         => 75000.00,
                'max'         => 1500000.00,
                'min_tenor'   => 24,
                'max_tenor'   => 84,
                'rate'        => 'عائد مخفّض ضمن برنامج تجريبي لدعم كفاءة الطاقة.',
                'eligibility' => 'مشروع صناعي أو خدمي قائم برغبة في خفض استهلاك الطاقة.',
                'documents'   => "دراسة استهلاك الطاقة.\nعرض سعر المورّد.",
                'min_years'   => 1,
                'formal'      => 1,
                'status'      => 'pending_review',
            ],
        ];
    }

    // ─────────────────── باقات الخدمات | Service offerings ───────────────────

    private function seedServiceOfferings(): int
    {
        $approverId = $this->platformApproverId();
        $count      = 0;

        foreach ($this->offerings() as $offering) {
            $organizationId = $this->demoOrganizationId($offering['organization']);

            if ($organizationId === null) {
                continue;
            }

            $categoryId = Database::scalar(
                "SELECT id FROM categories WHERE type = 'service' AND code = ? LIMIT 1",
                [$offering['category']],
            );

            $approved = $offering['status'] === 'published';

            $this->upsert('service_offerings', [
                'organization_id'         => $organizationId,
                'category_id'             => $categoryId === null ? null : (int) $categoryId,
                'name_ar'                 => $offering['name'],
                'slug'                    => $this->slug($offering['name']),
                'short_description'       => $offering['short'],
                'description'             => $offering['description'],
                'service_type'            => $offering['type'],
                'delivery_mode'           => $offering['delivery'],
                'duration_note_ar'        => $offering['duration'],
                'pricing_mode'            => $offering['pricing'],
                'price_from'              => $offering['price_from'] ?? null,
                'price_to'                => $offering['price_to'] ?? null,
                'currency_code'           => 'EGP',
                'funded_by_ar'            => $offering['funded_by'] ?? null,
                'target_audience_ar'      => $offering['audience'],
                'deliverables_ar'         => $offering['deliverables'],
                'covers_all_governorates' => $offering['all_governorates'] ?? 1,
                'status'                  => $offering['status'],
                'approved_by'             => $approved ? $approverId : null,
                'approved_at'             => $approved ? date('Y-m-d H:i:s') : null,
                'published_at'            => $approved ? date('Y-m-d H:i:s') : null,
                'is_demo'                 => 1,
            ], ['slug']);

            $count++;
        }

        return $count;
    }

    /** @return array<int,array<string,mixed>> */
    private function offerings(): array
    {
        $disclaimer = "\n\nباقة تجريبية أُنشئت لعرض المنصة، ولا تمثّل عرضاً من جهة قائمة.";

        return [
            [
                'organization' => 'بيت-الخبرة-للاستشارات',
                'name'         => 'إعداد دراسة جدوى اقتصادية (تجريبي)',
                'category'     => 'svc_business_planning',
                'type'         => 'consulting',
                'delivery'     => 'hybrid',
                'duration'     => 'من ثلاثة إلى أربعة أسابيع.',
                'pricing'      => 'range',
                'price_from'   => 8000.00,
                'price_to'     => 25000.00,
                'audience'     => 'المشروعات المقبلة على التوسّع أو طلب تمويل.',
                'deliverables' => "دراسة سوق مختصرة.\nنموذج مالي بثلاث سيناريوهات.\n"
                    . 'ملخّص تنفيذي صالح للتقديم لجهات التمويل.',
                'short'        => 'دراسة جدوى تشمل تحليل السوق ونموذجاً مالياً وملخّصاً تنفيذياً.',
                'description'  => 'خدمة تجريبية تعرض ما قد تقدّمه بيوت الخبرة على المنصة. '
                    . 'أي مخرجات محاسبية أو مالية تحتاج مراجعة محاسب قانوني مؤهّل قبل '
                    . 'الاعتماد عليها.' . $disclaimer,
                'status'       => 'published',
            ],
            [
                'organization' => 'بيت-الخبرة-للاستشارات',
                'name'         => 'تنظيم الدورة المستندية والحسابات (تجريبي)',
                'category'     => 'svc_financial_advisory',
                'type'         => 'accounting',
                'delivery'     => 'onsite',
                'duration'     => 'ثلاثة أسابيع.',
                'pricing'      => 'fixed',
                'price_from'   => 12000.00,
                'audience'     => 'المشروعات التي تسجّل حساباتها يدوياً أو بلا نظام.',
                'deliverables' => "خريطة الدورة المستندية.\nنماذج مستندات جاهزة.\n"
                    . 'تدريب موظف على التسجيل اليومي.',
                'short'        => 'ترتيب مستندات المشتريات والمبيعات والمخزون في دورة واضحة.',
                'description'  => 'خدمة تجريبية. التقارير الناتجة تقارير إدارية لا مخرجات نظام '
                    . 'محاسبي معتمد، وتحتاج مراجعة محاسب قانوني.' . $disclaimer,
                'status'       => 'published',
            ],
            [
                'organization' => 'مؤسسة-تمكين-للتنمية',
                'name'         => 'برنامج تدريب إدارة المشروعات الصغيرة (تجريبي — مجاني)',
                'category'     => 'svc_training',
                'type'         => 'training',
                'delivery'     => 'onsite',
                'duration'     => 'خمسة أيام تدريبية.',
                'pricing'      => 'free',
                'funded_by'    => 'برنامج تنموي تجريبي',
                'audience'     => 'أصحاب المشروعات متناهية الصغر والصغيرة.',
                'deliverables' => "خمس جلسات تدريبية.\nدليل مطبوع.\nشهادة حضور.",
                'short'        => 'تدريب مجاني على أساسيات الإدارة والتسعير وخدمة العملاء.',
                'description'  => 'برنامج تجريبي مجاني ضمن مبادرة تنموية افتراضية. المنصة لا '
                    . 'تتحمّل تكلفة أي خدمة مجانية؛ الجهة الممولة مذكورة أعلاه.' . $disclaimer,
                'status'       => 'published',
            ],
            [
                'organization' => 'مؤسسة-تمكين-للتنمية',
                'name'         => 'إرشاد التحوّل الرقمي للمشروعات (تجريبي)',
                'category'     => 'svc_digital',
                'type'         => 'digital',
                'delivery'     => 'remote',
                'duration'     => 'ستّ جلسات على مدى شهر.',
                'pricing'      => 'quote',
                'audience'     => 'المشروعات التي تبيع بالطرق التقليدية وتريد البيع الإلكتروني.',
                'deliverables' => "تقييم الحضور الرقمي الحالي.\nخطة تحوّل عملية.\n"
                    . 'متابعة التنفيذ لجلستين.',
                'short'        => 'إرشاد عملي للانتقال إلى البيع والتسويق الإلكتروني.',
                'description'  => 'خدمة تجريبية تُنفَّذ عن بُعد، فلا يقيّدها موقع المشروع.' . $disclaimer,
                'status'       => 'published',
            ],
            [
                'organization' => 'بيت-الخبرة-للاستشارات',
                'name'         => 'تأهيل للحصول على شهادة الجودة (تجريبي — بانتظار الاعتماد)',
                'category'     => 'svc_quality',
                'type'         => 'certification',
                'delivery'     => 'onsite',
                'duration'     => 'من شهرين إلى أربعة.',
                'pricing'      => 'quote',
                'audience'     => 'المصانع الصغيرة والمتوسطة المستعدّة للتصدير.',
                'deliverables' => "فحص فجوات.\nتوثيق الإجراءات.\nتأهيل لزيارة جهة المنح.",
                'short'        => 'تأهيل المنشأة لمتطلّبات شهادات الجودة قبل تقديمها لجهة المنح.',
                'description'  => 'باقة تجريبية متروكة في حالة «بانتظار الاعتماد» ليجد المُراجِع '
                    . 'عنصراً يتخذ عليه قراراً.' . $disclaimer,
                'status'       => 'pending_review',
            ],
        ];
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function demoOrganizationId(string $slug): ?int
    {
        $id = Database::scalar(
            'SELECT id FROM organizations WHERE slug = ? AND is_demo = 1 AND deleted_at IS NULL LIMIT 1',
            [$slug],
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * مستخدم يمثّل اعتماد المنصة | The platform user recorded as approver.
     *
     * ختم الاعتماد يشير إلى مستخدم حقيقي في بيانات العرض أيضاً: عنصر «معتمد»
     * بلا معتمِد يناقض القاعدة التي تحرسها بقية المنصة.
     */
    private function platformApproverId(): ?int
    {
        $id = Database::scalar(
            "SELECT u.id FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id
              WHERE r.code IN ('super_admin', 'ops_officer')
                AND u.deleted_at IS NULL
              ORDER BY FIELD(r.code, 'ops_officer', 'super_admin')
              LIMIT 1",
        );

        return $id === null ? null : (int) $id;
    }

    private function slug(string $name): string
    {
        $slug = preg_replace('/[^\p{Arabic}\p{L}\p{N}]+/u', '-', $name) ?? '';

        return trim(mb_substr($slug, 0, 200), '-');
    }
}
