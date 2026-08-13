<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * أنواع الوثائق المطلوبة | Required document types (§4.2).
 *
 * تُدير الإدارة هذه القائمة من لوحة التحكم، فمتطلبات المستندات لا تُكتب داخل
 * الكود ولا تحتاج نشر إصدار لتعديلها.
 * The admin manages this list, so document requirements are configuration
 * rather than code and need no release to change.
 *
 * ملاحظة: لا يُطلب الرقم القومي ولا صورة البطاقة الشخصية في هذه النسخة (§10).
 */
final class DocumentTypesSeeder extends Seeder
{
    public function order(): int
    {
        return 13;
    }

    public function run(): void
    {
        $types = [
            // --- مستندات المشروعات الصغيرة والمتوسطة ---
            ['sme_commercial_register', 'السجل التجاري', 'صورة واضحة من السجل التجاري ساري المفعول.', 'sme', 0, 1],
            ['sme_tax_card', 'البطاقة الضريبية', 'صورة من البطاقة الضريبية إن وُجدت.', 'sme', 0, 1],
            ['sme_industrial_register', 'السجل الصناعي', 'للمنشآت الصناعية فقط.', 'sme', 0, 1],
            ['sme_operating_license', 'ترخيص مزاولة النشاط', 'ترخيص التشغيل أو مزاولة النشاط من الجهة المختصة.', 'sme', 0, 1],
            ['sme_activity_proof', 'إثبات مزاولة النشاط', 'أي مستند يثبت مزاولة النشاط فعلياً: عقد إيجار المقر، فواتير موردين، أو صور من موقع العمل.', 'sme', 1, 0],

            // --- مستندات البنوك والمؤسسات المالية ---
            ['bank_license', 'ترخيص مزاولة النشاط المصرفي', 'ترخيص الجهة الرقابية المختصة.', 'bank', 1, 1],
            ['bank_authorization', 'تفويض ممثل الجهة', 'خطاب تفويض للمسؤول عن الحساب على المنصة.', 'bank', 1, 0],

            // --- مستندات المنظمات الأهلية ---
            ['ngo_registration', 'قيد المنظمة الأهلية', 'شهادة القيد لدى الجهة الإدارية المختصة.', 'ngo', 1, 1],
            ['ngo_board_decision', 'قرار مجلس الإدارة', 'قرار بتفويض ممثل المنظمة على المنصة.', 'ngo', 0, 0],

            // --- مستندات مقدّمي الخدمات ---
            ['provider_commercial_register', 'السجل التجاري', 'السجل التجاري لمقدّم الخدمة.', 'service_provider', 1, 1],
            ['provider_tax_card', 'البطاقة الضريبية', 'البطاقة الضريبية لمقدّم الخدمة.', 'service_provider', 1, 1],
            ['provider_professional_license', 'مزاولة المهنة', 'ترخيص أو قيد نقابي لمزاولة المهنة (للمحاسبين والمحامين وغيرهم).', 'service_provider', 0, 1],
            ['provider_portfolio', 'نماذج أعمال سابقة', 'ملف يوضّح الخبرات والأعمال السابقة.', 'service_provider', 0, 0],

            // --- مستندات مراكز تطوير الأعمال ---
            ['bds_accreditation', 'اعتماد المركز', 'مستند اعتماد المركز من الجهة المشرفة.', 'bds_center', 1, 1],
            ['bds_host_agreement', 'اتفاق الاستضافة', 'الاتفاق مع الجهة المستضيفة للمركز.', 'bds_center', 0, 0],

            // --- مستندات الجهات الحكومية ---
            ['gov_designation', 'خطاب تكليف', 'خطاب رسمي بتكليف الجهة أو ممثلها.', 'government', 1, 0],
        ];

        foreach ($types as $index => [$code, $name, $description, $appliesTo, $required, $requiresExpiry]) {
            $this->upsert('document_types', [
                'code'            => $code,
                'name_ar'         => $name,
                'description_ar'  => $description,
                'applies_to'      => $appliesTo,
                'is_required'     => $required,
                'requires_expiry' => $requiresExpiry,
                'is_active'       => 1,
                'sort_order'      => $index + 1,
            ], ['code']);
        }

        $this->info(count($types) . ' نوع مستند موزّعة على أنواع المنشآت.');
    }
}
