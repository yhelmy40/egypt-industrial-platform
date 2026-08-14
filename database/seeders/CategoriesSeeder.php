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
        $count = 0;

        foreach ($this->tree() as $index => [$code, $nameAr, $icon, $children]) {
            $parentId = $this->upsert('categories', [
                'parent_id'  => null,
                'type'       => 'listing',
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
                    'type'       => 'listing',
                    'code'       => $childCode,
                    'name_ar'    => $childName,
                    'is_active'  => 1,
                    'sort_order' => ($childIndex + 1) * 10,
                ], ['type', 'code']);

                $count++;
            }
        }

        $this->info("{$count} تصنيفاً للسوق.");
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
