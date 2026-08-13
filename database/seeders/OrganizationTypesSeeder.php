<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * أنواع المنشآت | Organization types (§3).
 *
 * النوع يحدّد رحلة التسجيل، وملف التعريف المرتبط، والأدوار المتاحة داخل
 * المنشأة، والقوائم التي تظهر فيها.
 * The type drives the registration journey, the linked profile, the roles
 * available inside the organization, and where it appears in directories.
 */
final class OrganizationTypesSeeder extends Seeder
{
    public function order(): int
    {
        return 12;
    }

    public function run(): void
    {
        $types = [
            [
                'code'        => 'sme',
                'name_ar'     => 'مشروع صغير أو متوسط',
                'name_en'     => 'Small or Medium Enterprise',
                'description' => 'منشأة تجارية أو صناعية أو خدمية تسعى للنمو والوصول إلى الخدمات المالية وغير المالية.',
            ],
            [
                'code'        => 'bank',
                'name_ar'     => 'بنك أو مؤسسة مالية',
                'name_en'     => 'Bank or Financial Institution',
                'description' => 'جهة مرخّصة تقدّم منتجات تمويلية وخدمات مصرفية للمشروعات.',
            ],
            [
                'code'        => 'ngo',
                'name_ar'     => 'منظمة أهلية أو شريك تنموي',
                'name_en'     => 'NGO or Development Partner',
                'description' => 'جهة تقدّم برامج ومنحاً وتدريباً وإرشاداً وبناء قدرات للمشروعات.',
            ],
            [
                'code'        => 'service_provider',
                'name_ar'     => 'مقدّم خدمة متخصصة',
                'name_en'     => 'Service Provider',
                'description' => 'محاسب أو محامٍ أو شركة تسويق أو تقنية أو تدريب أو خدمات لوجستية أو استشارية.',
            ],
            [
                'code'        => 'bds_center',
                'name_ar'     => 'مركز خدمات تطوير الأعمال',
                'name_en'     => 'Business Development Services Centre',
                'description' => 'مركز يقدّم خدمات غير مالية وإرشاداً وتقييماً للاحتياجات ومتابعة لحالات الدعم.',
            ],
            [
                'code'        => 'government',
                'name_ar'     => 'جهة حكومية',
                'name_en'     => 'Government Entity',
                'description' => 'وزارة أو هيئة أو جهاز حكومي مشارك في منظومة دعم المشروعات.',
            ],
        ];

        foreach ($types as $index => $type) {
            $this->upsert('organization_types', [
                'code'                  => $type['code'],
                'name_ar'               => $type['name_ar'],
                'name_en'               => $type['name_en'],
                'description_ar'        => $type['description'],
                'requires_verification' => 1,
                'is_active'             => 1,
                'sort_order'            => $index + 1,
            ], ['code']);
        }

        $this->info('6 أنواع للمنشآت.');
    }
}
