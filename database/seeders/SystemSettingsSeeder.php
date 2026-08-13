<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * إعدادات النظام الافتراضية | Default system settings (§3.1).
 *
 * إعدادات يمكن لمدير المنصة تعديلها دون نشر كود جديد.
 */
final class SystemSettingsSeeder extends Seeder
{
    public function order(): int
    {
        return 30;
    }

    public function run(): void
    {
        $settings = [
            // --- عام | General ---
            ['general', 'platform_name', 'منصة رواد النيل لتمكين المشروعات', 'string', 'اسم المنصة', 1],
            ['general', 'support_email', 'support@nilepreneurs.example', 'string', 'بريد الدعم الفني', 1],
            ['general', 'support_phone', '', 'string', 'هاتف الدعم الفني', 1],
            ['general', 'policy_version', '1.0', 'string', 'نسخة الشروط وسياسة الخصوصية الحالية', 1],
            ['general', 'maintenance_mode', '0', 'boolean', 'وضع الصيانة', 0],

            // --- السوق | Marketplace ---
            ['marketplace', 'require_verification_to_publish', '1', 'boolean', 'اشتراط توثيق المنشأة قبل نشر المنتجات', 0],
            ['marketplace', 'require_listing_moderation', '1', 'boolean', 'مراجعة الإدارة قبل نشر الإعلان', 0],
            ['marketplace', 'default_vat_rate', '14.00', 'decimal', 'نسبة ضريبة القيمة المضافة الافتراضية %', 0],
            ['marketplace', 'allow_guest_checkout', '1', 'boolean', 'السماح بإتمام الطلب دون حساب', 0],
            ['marketplace', 'review_requires_completed_order', '1', 'boolean', 'التقييم بعد إتمام الطلب فقط', 0],
            ['marketplace', 'listings_per_page', '12', 'integer', 'عدد الإعلانات في الصفحة', 0],

            // --- الخصوصية | Privacy ---
            ['privacy', 'document_retention_days', '1825', 'integer', 'مدة الاحتفاظ بوثائق المنشآت (بالأيام)', 0],
            ['privacy', 'audit_retention_days', '1095', 'integer', 'مدة الاحتفاظ بسجل التدقيق (بالأيام)', 0],
            ['privacy', 'mask_contact_on_public_page', '1', 'boolean', 'إخفاء بيانات الاتصال جزئياً على الصفحة العامة', 0],
            ['privacy', 'data_request_sla_days', '30', 'integer', 'المدة المستهدفة للرد على طلبات الخصوصية (بالأيام)', 0],

            // --- المطابقة | Matching (§11) ---
            ['matching', 'weight_sector', '30', 'integer', 'وزن تطابق القطاع في الترشيح', 0],
            ['matching', 'weight_governorate', '25', 'integer', 'وزن تطابق المحافظة في الترشيح', 0],
            ['matching', 'weight_size', '15', 'integer', 'وزن تطابق حجم المنشأة', 0],
            ['matching', 'weight_formalization', '15', 'integer', 'وزن الوضع القانوني للمنشأة', 0],
            ['matching', 'weight_amount_range', '15', 'integer', 'وزن تطابق نطاق مبلغ التمويل', 0],
            ['matching', 'min_score_to_recommend', '40', 'integer', 'أقل درجة لعرض الترشيح', 0],
            ['matching', 'max_recommendations', '6', 'integer', 'أقصى عدد ترشيحات معروضة', 0],
            ['matching', 'max_comparison_items', '3', 'integer', 'أقصى عدد منتجات في المقارنة', 0],

            // --- الإشعارات | Notifications ---
            ['notifications', 'digest_enabled', '0', 'boolean', 'تفعيل الملخّص الدوري بالبريد', 0],
            ['notifications', 'notify_on_new_order', '1', 'boolean', 'إشعار عند وصول طلب جديد', 0],
            ['notifications', 'notify_on_status_change', '1', 'boolean', 'إشعار عند تغيير حالة الطلبات والمعاملات', 0],
        ];

        foreach ($settings as [$group, $key, $value, $type, $label, $isPublic]) {
            $this->upsert('system_settings', [
                'group_key'   => $group,
                'setting_key' => $key,
                'value'       => $value,
                'value_type'  => $type,
                'label_ar'    => $label,
                'is_public'   => $isPublic,
            ], ['group_key', 'setting_key']);
        }

        $this->info(count($settings) . ' إعداداً افتراضياً.');
    }
}
