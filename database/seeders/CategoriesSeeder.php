<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * تصنيفات السوق | Marketplace categories (§4.4).
 *
 * بيانات مرجعية تشغيلية: شجرة تصنيف من مستويين تُستخدم في تصنيف الأصناف
 * وتصفية البحث. الإدارة تعدّلها لاحقاً من لوحة التحكم، فهي ليست ثوابت كود.
 * Operational reference data: a two-level tree used to classify listings and
 * filter search. The admin edits it later from the dashboard.
 */
final class CategoriesSeeder extends Seeder
{
    public function order(): int
    {
        return 14;
    }

    public function run(): void
    {
        $count = $this->seedTree('listing', $this->tree());
        $count += $this->seedFlat('financial', $this->financialCategories());
        $count += $this->seedFlat('service', $this->serviceCategories());
        $count += $this->seedFlat('article', $this->articleCategories());

        $this->info("{$count} تصنيفاً للسوق والتمويل والخدمات والمحتوى.");
    }

    /**
     * تصنيفات مسطّحة | A flat category list (financing and services need no tree).
     *
     * @param array<int,array{0:string,1:string}> $items
     */
    private function seedFlat(string $type, array $items): int
    {
        foreach ($items as $index => [$code, $nameAr]) {
            $this->upsert('categories', [
                'parent_id'  => null,
                'type'       => $type,
                'code'       => $code,
                'name_ar'    => $nameAr,
                'is_active'  => 1,
                'sort_order' => ($index + 1) * 10,
            ], ['type', 'code']);
        }

        return count($items);
    }

    /**
     * تصنيفات مركز المعرفة | Knowledge-centre categories (§4.11).
     *
     * تتبع أقسام المنصة نفسها، فيجد صاحب المشروع المادة قرب الشاشة التي
     * تخصّها بدل تصنيف عامّ لا يدلّ على شيء.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function articleCategories(): array
    {
        return [
            ['art_start', 'بدء المشروع وتأسيسه'],
            ['art_formalize', 'التقنين والتراخيص'],
            ['art_finance', 'التمويل والجدارة الائتمانية'],
            ['art_sales', 'البيع والتسويق'],
            ['art_operations', 'التشغيل والمخزون'],
            ['art_accounting', 'الحسابات والفوترة'],
            ['art_digital', 'التحول الرقمي'],
            ['art_export', 'التصدير والأسواق الخارجية'],
        ];
    }

    /** @return array<int,array{0:string,1:string}> */
    private function financialCategories(): array
    {
        return [
            ['fin_startup', 'تمويل بدء النشاط'],
            ['fin_growth', 'تمويل التوسّع والنمو'],
            ['fin_equipment', 'تمويل المعدات والأصول'],
            ['fin_working_capital', 'تمويل رأس المال العامل'],
            ['fin_export', 'تمويل التصدير'],
            ['fin_green', 'تمويل التحوّل الأخضر وكفاءة الطاقة'],
            ['fin_women', 'برامج تمويل المرأة'],
            ['fin_youth', 'برامج تمويل الشباب'],
        ];
    }

    /** @return array<int,array{0:string,1:string}> */
    private function serviceCategories(): array
    {
        return [
            ['svc_business_planning', 'دراسات الجدوى وخطط العمل'],
            ['svc_financial_advisory', 'الاستشارات المالية والمحاسبية'],
            ['svc_legal_advisory', 'الاستشارات القانونية والتقنين'],
            ['svc_marketing', 'التسويق والعلامة التجارية'],
            ['svc_digital', 'التحوّل الرقمي والتجارة الإلكترونية'],
            ['svc_quality', 'الجودة والمواصفات والشهادات'],
            ['svc_production', 'تطوير الإنتاج وكفاءة التشغيل'],
            ['svc_export_readiness', 'جاهزية التصدير والأسواق الخارجية'],
            ['svc_training', 'التدريب وبناء القدرات'],
            ['svc_design', 'التصميم وتطوير المنتج'],
        ];
    }

    /**
     * شجرة تصنيفات من مستويين | A two-level category tree.
     *
     * @param array<int,array{0:string,1:string,2:string,3:array<int,array{0:string,1:string}>}> $tree
     */
    private function seedTree(string $type, array $tree): int
    {
        $count = 0;

        foreach ($tree as $index => [$code, $nameAr, $icon, $children]) {
            $parentId = $this->upsert('categories', [
                'parent_id'  => null,
                'type'       => $type,
                'code'       => $code,
                'name_ar'    => $nameAr,
                'icon'       => $icon,
                'is_active'  => 1,
                'sort_order' => ($index + 1) * 10,
            ], ['type', 'code']);

            $count++;

            foreach ($children as $childIndex => [$childCode, $childName]) {
                $this->upsert('categories', [
                    'parent_id'  => $parentId,
                    'type'       => $type,
                    'code'       => $childCode,
                    'name_ar'    => $childName,
                    'is_active'  => 1,
                    'sort_order' => ($childIndex + 1) * 10,
                ], ['type', 'code']);

                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<int,array{0:string,1:string,2:string,3:array<int,array{0:string,1:string}>}>
     */
    private function tree(): array
    {
        return [
            ['food_beverage', 'الأغذية والمشروبات', '🍞', [
                ['food_packaged', 'منتجات غذائية معبّأة'],
                ['food_bakery', 'مخبوزات وحلويات'],
                ['food_dairy', 'ألبان ومنتجاتها'],
                ['food_agri', 'حاصلات زراعية'],
                ['food_processing_services', 'خدمات تصنيع غذائي'],
            ]],
            ['textiles_apparel', 'الغزل والنسيج والملابس', '🧵', [
                ['textiles_fabric', 'أقمشة وخيوط'],
                ['textiles_readymade', 'ملابس جاهزة'],
                ['textiles_home', 'مفروشات منزلية'],
                ['textiles_services', 'خدمات تفصيل وتطريز'],
            ]],
            ['furniture_wood', 'الأثاث والأخشاب', '🪑', [
                ['furniture_home', 'أثاث منزلي'],
                ['furniture_office', 'أثاث مكتبي'],
                ['wood_products', 'منتجات خشبية'],
                ['furniture_services', 'خدمات نجارة وتشطيب'],
            ]],
            ['engineering_metal', 'الصناعات الهندسية والمعدنية', '⚙️', [
                ['metal_products', 'منتجات معدنية'],
                ['machinery_parts', 'قطع غيار ومكوّنات'],
                ['electrical_equipment', 'معدات كهربائية'],
                ['engineering_services', 'خدمات تصنيع وتشغيل'],
            ]],
            ['chemicals_plastics', 'الكيماويات والبلاستيك', '🧪', [
                ['plastic_products', 'منتجات بلاستيكية'],
                ['detergents', 'منظفات ومطهّرات'],
                ['packaging', 'مواد تعبئة وتغليف'],
            ]],
            ['handicrafts', 'الحرف اليدوية والتراثية', '🧶', [
                ['handicraft_textile', 'منسوجات يدوية'],
                ['handicraft_pottery', 'خزف وفخار'],
                ['handicraft_leather', 'منتجات جلدية'],
                ['handicraft_accessories', 'إكسسوارات ومشغولات'],
            ]],
            ['building_materials', 'مواد البناء والتشييد', '🧱', [
                ['building_finishes', 'مواد تشطيب'],
                ['building_structural', 'مواد إنشائية'],
                ['construction_services', 'خدمات مقاولات وتشطيب'],
            ]],
            ['business_services', 'خدمات الأعمال', '💼', [
                ['service_accounting', 'محاسبة ومراجعة'],
                ['service_legal', 'خدمات قانونية'],
                ['service_marketing', 'تسويق وتصميم'],
                ['service_it', 'تقنية معلومات وبرمجيات'],
                ['service_logistics', 'نقل وشحن وتخزين'],
                ['service_training', 'تدريب وتأهيل'],
            ]],
        ];
    }
}
