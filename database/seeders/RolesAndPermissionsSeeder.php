<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;

/**
 * الأدوار والصلاحيات | Roles and permissions matrix (§3, §6).
 *
 * مصدر الحقيقة الوحيد لمصفوفة الصلاحيات. مدير المنصة يستطيع تعديلها لاحقاً من
 * لوحة الإدارة، لكن هذه هي الحالة الابتدائية الآمنة.
 * Single source of truth for the initial permission matrix. The super admin can
 * edit it later from the admin UI; this is the safe starting state.
 *
 * ملاحظة تصميمية: الصلاحيات تُعرَّف هنا لكل الوحدات بما فيها المراحل اللاحقة،
 * حتى تكون مصفوفة الصلاحيات كاملة وقابلة للتدقيق من ملف واحد منذ البداية.
 * Design note: permissions for later-phase modules are defined now so the whole
 * matrix is auditable from one file from day one.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public function order(): int
    {
        return 20;
    }

    public function run(): void
    {
        $permissionIds = $this->seedPermissions();
        $this->seedRoles($permissionIds);

        $this->info(count($permissionIds) . ' صلاحية موزّعة على الأدوار.');
    }

    /**
     * @return array<string,int> code => id
     */
    private function seedPermissions(): array
    {
        $ids = [];

        foreach ($this->permissions() as [$code, $module, $nameAr, $sensitive, $ownerAssignable]) {
            $ids[$code] = $this->upsert('permissions', [
                'code'                => $code,
                'module'              => $module,
                'name_ar'             => $nameAr,
                'is_sensitive'        => $sensitive ? 1 : 0,
                'assignable_by_owner' => $ownerAssignable ? 1 : 0,
            ], ['code']);
        }

        return $ids;
    }

    /** @param array<string,int> $permissionIds */
    private function seedRoles(array $permissionIds): void
    {
        foreach ($this->roles() as $index => $role) {
            $roleId = $this->upsert('roles', [
                'code'                   => $role['code'],
                'name_ar'                => $role['name_ar'],
                'name_en'                => $role['name_en'],
                'description_ar'         => $role['description'],
                'scope'                  => $role['scope'],
                'organization_type_code' => $role['org_type'] ?? null,
                'is_system'              => 1,
                'is_active'              => 1,
                'sort_order'             => $index + 1,
            ], ['code']);

            // إعادة بناء صلاحيات الدور بالكامل ليعكس التعريف أعلاه
            Database::statement('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);

            $codes = $role['permissions'] === '*'
                ? array_keys($permissionIds)
                : $this->expand($role['permissions'], array_keys($permissionIds));

            foreach ($codes as $code) {
                if (!isset($permissionIds[$code])) {
                    continue;
                }

                Database::statement(
                    'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                    [$roleId, $permissionIds[$code]],
                );
            }
        }
    }

    /**
     * توسيع أنماط الصلاحيات | Expand wildcard patterns such as "crm.*".
     *
     * @param  array<int,string> $patterns
     * @param  array<int,string> $allCodes
     * @return array<int,string>
     */
    private function expand(array $patterns, array $allCodes): array
    {
        $result = [];

        foreach ($patterns as $pattern) {
            if (!str_contains($pattern, '*')) {
                $result[] = $pattern;
                continue;
            }

            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';

            foreach ($allCodes as $code) {
                if (preg_match($regex, $code) === 1) {
                    $result[] = $code;
                }
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * كتالوج الصلاحيات | Permission catalogue.
     *
     * [code, module, name_ar, is_sensitive, assignable_by_owner]
     *
     * @return array<int,array{0:string,1:string,2:string,3:bool,4:bool}>
     */
    private function permissions(): array
    {
        return [
            // --- إدارة المنصة | Platform administration ---
            ['platform.settings.view',      'platform', 'عرض إعدادات المنصة', false, false],
            ['platform.settings.update',    'platform', 'تعديل إعدادات المنصة', true, false],
            ['platform.reference.manage',   'platform', 'إدارة البيانات المرجعية (المحافظات والقطاعات)', true, false],
            ['platform.role.view',          'platform', 'عرض الأدوار والصلاحيات', false, false],
            ['platform.role.manage',        'platform', 'إدارة الأدوار والصلاحيات', true, false],
            ['platform.user.view',          'platform', 'عرض المستخدمين', false, false],
            ['platform.user.manage',        'platform', 'إدارة المستخدمين', true, false],
            ['platform.user.suspend',       'platform', 'إيقاف حساب مستخدم', true, false],
            ['platform.audit.view',         'platform', 'عرض سجل التدقيق', true, false],
            ['platform.notification_template.manage', 'platform', 'إدارة قوالب الإشعارات', false, false],

            // --- المنشآت والتوثيق | Organizations & verification ---
            ['org.account.view_any',                'organization', 'عرض أي منشأة', false, false],
            ['org.account.verify',                  'organization', 'اعتماد أو رفض توثيق المنشآت', true, false],
            ['org.account.suspend',                 'organization', 'إيقاف منشأة', true, false],
            ['org.profile.view',            'organization', 'عرض ملف المنشأة', false, true],
            ['org.profile.update',          'organization', 'تعديل ملف المنشأة', false, true],
            ['org.document.upload',         'organization', 'رفع وثائق المنشأة', false, true],
            ['org.document.view',           'organization', 'عرض وثائق المنشأة', true, true],
            ['org.member.view',             'organization', 'عرض أعضاء المنشأة', false, true],
            ['org.member.manage',           'organization', 'دعوة وإدارة أعضاء المنشأة', true, false],
            ['org.page.manage',             'organization', 'إدارة الصفحة التعريفية العامة', false, true],
            ['org.page.publish',            'organization', 'نشر الصفحة التعريفية', false, false],

            // --- السوق | Marketplace ---
            ['marketplace.listing.view',    'marketplace', 'عرض المنتجات والخدمات', false, true],
            ['marketplace.listing.create',  'marketplace', 'إضافة منتج أو خدمة', false, true],
            ['marketplace.listing.update',  'marketplace', 'تعديل منتج أو خدمة', false, true],
            ['marketplace.listing.delete',  'marketplace', 'حذف منتج أو خدمة', false, true],
            ['marketplace.listing.publish', 'marketplace', 'نشر منتج أو خدمة', false, true],
            ['marketplace.listing.moderate', 'marketplace', 'مراجعة ونشر إعلانات المنشآت', true, false],
            ['marketplace.order.view',      'marketplace', 'عرض الطلبات', false, true],
            ['marketplace.order.update_status', 'marketplace', 'تغيير حالة الطلب', false, true],
            ['marketplace.order.cancel',    'marketplace', 'إلغاء طلب', false, true],
            ['marketplace.order.view_any',  'marketplace', 'عرض طلبات كل المنشآت', true, false],
            ['marketplace.enquiry.view',    'marketplace', 'عرض استفسارات العملاء', false, true],
            ['marketplace.enquiry.respond', 'marketplace', 'الرد على استفسارات العملاء', false, true],
            ['marketplace.quotation.manage', 'marketplace', 'إصدار عروض الأسعار', false, true],
            ['marketplace.review.moderate', 'marketplace', 'مراجعة التقييمات', true, false],
            ['marketplace.complaint.view',  'marketplace', 'عرض الشكاوى', false, true],
            ['marketplace.complaint.manage', 'marketplace', 'إدارة الشكاوى والنزاعات', true, false],

            // --- الخدمات المالية | Financial services ---
            ['finance.product.view',        'finance', 'عرض المنتجات التمويلية', false, true],
            ['finance.product.manage',      'finance', 'إدارة المنتجات التمويلية', false, false],
            ['finance.product.publish',     'finance', 'نشر منتج تمويلي', true, false],
            ['finance.application.submit',  'finance', 'تقديم طلب تمويل', false, true],
            ['finance.application.view',    'finance', 'عرض طلبات التمويل الخاصة بالمنشأة', false, true],
            ['finance.application.view_any', 'finance', 'عرض كل طلبات التمويل', true, false],
            ['finance.application.screen',  'finance', 'الفرز الأولي لطلبات التمويل', true, false],
            ['finance.application.review',  'finance', 'مراجعة طلبات التمويل لدى المزوّد', true, false],
            // القرار النهائي حصراً للمزوّد المسؤول (§4.5)
            ['finance.application.decide',  'finance', 'تسجيل قرار الموافقة أو الرفض', true, false],

            // --- الخدمات غير المالية | Non-financial services ---
            ['services.offering.view',      'services', 'عرض الخدمات المتاحة', false, true],
            ['services.offering.manage',    'services', 'إدارة باقات الخدمات', false, false],
            ['services.request.submit',     'services', 'طلب خدمة', false, true],
            ['services.request.view',       'services', 'عرض طلبات الخدمة', false, true],
            ['services.request.view_any',   'services', 'عرض كل طلبات الخدمة', true, false],
            ['services.request.assign',     'services', 'إسناد طلب خدمة', true, false],
            ['services.quotation.submit',   'services', 'تقديم عرض سعر للخدمة', false, false],
            ['services.milestone.manage',   'services', 'إدارة مراحل تنفيذ الخدمة', false, false],

            // --- تقييم الاحتياجات | Needs assessment ---
            ['assessment.needs.submit',           'assessment', 'إجراء تقييم احتياجات المشروع', false, true],
            ['assessment.needs.view',             'assessment', 'عرض نتائج التقييم', false, true],
            ['assessment.needs.view_any',         'assessment', 'عرض تقييمات كل المنشآت', true, false],
            ['assessment.template.manage',  'assessment', 'إدارة أسئلة وقواعد التقييم', true, false],

            // --- خدمات تطوير الأعمال | BDS case management ---
            ['bds.center.manage',           'bds', 'إدارة ملف مركز تطوير الأعمال', false, false],
            ['bds.case.view',               'bds', 'عرض حالات الدعم', false, false],
            ['bds.case.view_any',           'bds', 'عرض كل حالات الدعم', true, false],
            ['bds.case.manage',             'bds', 'إدارة حالة دعم', false, false],
            ['bds.case.assign',             'bds', 'إسناد حالة لأخصائي', true, false],
            ['bds.case.request',            'bds', 'طلب دعم من مركز تطوير الأعمال', false, true],
            // ملاحظات داخلية لا يراها صاحب المشروع مطلقاً (§4.10)
            ['bds.note.internal',           'bds', 'الاطلاع على الملاحظات الداخلية وكتابتها', true, false],
            ['bds.note.shared',             'bds', 'كتابة ملاحظات مشتركة مع المشروع', false, false],
            ['bds.consultation.manage',     'bds', 'إدارة الجلسات الاستشارية والمواعيد', false, false],
            ['bds.plan.manage',             'bds', 'إدارة خطط العمل', false, false],
            ['bds.referral.create',         'bds', 'إحالة المشروع إلى بنك أو مقدّم خدمة', true, false],

            // --- إدارة علاقات العملاء | CRM ---
            ['crm.contact.view',            'crm', 'عرض جهات الاتصال والعملاء', false, true],
            ['crm.contact.manage',          'crm', 'إدارة جهات الاتصال والعملاء', false, true],
            ['crm.lead.view',               'crm', 'عرض العملاء المحتملين', false, true],
            ['crm.lead.manage',             'crm', 'إدارة العملاء المحتملين', false, true],
            ['crm.opportunity.view',        'crm', 'عرض الفرص البيعية', false, true],
            ['crm.opportunity.manage',      'crm', 'إدارة الفرص البيعية ومراحل البيع', false, true],
            ['crm.activity.manage',         'crm', 'تسجيل الأنشطة والمتابعات', false, true],
            ['crm.task.manage',             'crm', 'إدارة المهام والتذكيرات', false, true],

            // --- الموارد والعمليات | ERP Lite ---
            ['erp.item.view',               'erp', 'عرض الأصناف والمخزون', false, true],
            ['erp.item.manage',             'erp', 'إدارة الأصناف', false, true],
            ['erp.stock.manage',            'erp', 'تسجيل حركات المخزون', false, true],
            ['erp.supplier.manage',         'erp', 'إدارة الموردين', false, true],
            ['erp.purchase_order.manage',   'erp', 'إدارة أوامر الشراء', false, true],
            ['erp.invoice.view',            'erp', 'عرض الفواتير', false, true],
            ['erp.invoice.manage',          'erp', 'إصدار وتعديل الفواتير', false, true],
            ['erp.expense.manage',          'erp', 'تسجيل المصروفات', false, true],
            ['erp.receipt.manage',          'erp', 'تسجيل المقبوضات والخزينة', false, true],
            ['erp.report.view',             'erp', 'عرض التقارير المالية الإدارية', false, true],

            // --- المحتوى | Content management ---
            ['content.article.view',        'content', 'عرض محتوى مركز المعرفة', false, false],
            ['content.article.manage',      'content', 'إدارة المقالات والأدلة', false, false],
            ['content.page.manage',         'content', 'إدارة الصفحات الثابتة', false, false],
            ['content.faq.manage',          'content', 'إدارة الأسئلة الشائعة', false, false],
            ['content.item.publish',             'content', 'نشر أو أرشفة المحتوى', true, false],

            // --- التقارير | Reporting ---
            ['reports.platform.view',       'reports', 'عرض تقارير المنصة', true, false],
            ['reports.organization.view',   'reports', 'عرض تقارير المنشأة', false, true],
            ['reports.provider.view',       'reports', 'عرض تقارير مقدّم الخدمة', false, false],
            ['reports.data.export',              'reports', 'تصدير التقارير بصيغة CSV', true, true],

            // --- الخصوصية | Privacy workflows ---
            ['privacy.request.view',        'privacy', 'عرض طلبات الخصوصية', true, false],
            ['privacy.request.manage',      'privacy', 'تنفيذ طلبات الوصول والتصحيح والحذف', true, false],

            // --- المراسلات | Messaging ---
            ['messaging.conversation.view', 'messaging', 'عرض المحادثات', false, true],
            ['messaging.message.send',      'messaging', 'إرسال رسائل', false, true],
        ];
    }

    /**
     * تعريف الأدوار | Role definitions.
     *
     * @return array<int,array<string,mixed>>
     */
    private function roles(): array
    {
        return [
            // ============ أدوار المنصة | Platform scope ============
            [
                'code'        => 'super_admin',
                'name_ar'     => 'مدير المنصة',
                'name_en'     => 'Platform Super Admin',
                'description' => 'يمثّل مبادرة رواد النيل ولديه صلاحية كاملة على المنصة.',
                'scope'       => 'platform',
                'permissions' => '*',
            ],
            [
                'code'        => 'ops_officer',
                'name_ar'     => 'مسؤول تشغيل',
                'name_en'     => 'Platform Operations Officer',
                'description' => 'يراجع التسجيلات والطلبات والإعلانات والشكاوى وفق الصلاحيات المسندة.',
                'scope'       => 'platform',
                'permissions' => [
                    'platform.settings.view', 'platform.user.view', 'platform.audit.view',
                    'org.account.view_any', 'org.account.verify', 'org.document.view',
                    'marketplace.listing.moderate', 'marketplace.order.view_any',
                    'marketplace.review.moderate', 'marketplace.complaint.view',
                    'marketplace.complaint.manage',
                    'finance.application.view_any', 'finance.application.screen',
                    'services.request.view_any', 'services.request.assign',
                    'assessment.needs.view_any', 'bds.case.view_any', 'bds.case.assign',
                    'content.article.view', 'reports.platform.view', 'reports.data.export',
                    'messaging.conversation.view', 'messaging.message.send',
                ],
            ],
            [
                'code'        => 'content_editor',
                'name_ar'     => 'محرّر المحتوى',
                'name_en'     => 'Content Editor',
                'description' => 'يدير مركز المعرفة والصفحات الثابتة والأسئلة الشائعة.',
                'scope'       => 'platform',
                'permissions' => ['content.*'],
            ],
            [
                'code'        => 'marketplace_customer',
                'name_ar'     => 'عميل السوق',
                'name_en'     => 'Marketplace Customer',
                'description' => 'يتصفّح المنشآت والمنتجات ويقدّم الاستفسارات وينشئ الطلبات ويتابعها.',
                'scope'       => 'platform',
                'permissions' => [
                    'marketplace.listing.view', 'content.article.view',
                    'messaging.conversation.view', 'messaging.message.send',
                ],
            ],

            // ============ أدوار المنشآت | Organization scope ============
            [
                'code'        => 'sme_owner',
                'name_ar'     => 'صاحب المشروع',
                'name_en'     => 'SME Owner',
                'description' => 'يملك المشروع ويدير ملفه وصفحته ومنتجاته وطلباته وفريقه.',
                'scope'       => 'organization',
                'org_type'    => 'sme',
                'permissions' => [
                    'org.profile.*', 'org.document.*', 'org.member.*', 'org.page.*',
                    // تُسرد صلاحيات الإعلانات صراحةً: النمط marketplace.listing.*
                    // كان يلتقط marketplace.listing.moderate — وهي صلاحية إشراف
                    // على إعلانات المنشآت الأخرى ولا يجوز أن يملكها صاحب مشروع.
                    // Listed explicitly: the marketplace.listing.* wildcard also
                    // matched marketplace.listing.moderate — a cross-organization
                    // moderation power an SME owner must never hold.
                    'marketplace.listing.view', 'marketplace.listing.create',
                    'marketplace.listing.update', 'marketplace.listing.delete',
                    'marketplace.listing.publish',
                    'marketplace.order.view',
                    'marketplace.order.update_status', 'marketplace.order.cancel',
                    'marketplace.enquiry.*', 'marketplace.quotation.manage',
                    'marketplace.complaint.view',
                    'finance.product.view', 'finance.application.submit', 'finance.application.view',
                    'services.offering.view', 'services.request.submit', 'services.request.view',
                    'assessment.needs.submit', 'assessment.needs.view',
                    'bds.case.request', 'bds.case.view',
                    'crm.*', 'erp.*',
                    'content.article.view',
                    'reports.organization.view', 'reports.data.export',
                    'messaging.conversation.view', 'messaging.message.send',
                ],
            ],
            [
                'code'        => 'sme_employee',
                'name_ar'     => 'موظف بالمشروع',
                'name_en'     => 'SME Employee',
                'description' => 'صلاحياته يحدّدها صاحب المشروع (مبيعات، عملاء، مخزون، محاسبة، تنفيذ الطلبات).',
                'scope'       => 'organization',
                'org_type'    => 'sme',
                // الحد الأدنى الآمن؛ يوسّعه المالك عبر organization_member_permissions
                // Safe minimum; the owner extends it per-member.
                'permissions' => [
                    'org.profile.view', 'marketplace.listing.view',
                    'marketplace.order.view', 'marketplace.enquiry.view',
                    'content.article.view',
                    'messaging.conversation.view', 'messaging.message.send',
                ],
            ],
            [
                'code'        => 'bank_admin',
                'name_ar'     => 'مدير حساب البنك',
                'name_en'     => 'Bank Administrator',
                'description' => 'يدير ملف المؤسسة المالية ومنتجاتها التمويلية وفريق المراجعة.',
                'scope'       => 'organization',
                'org_type'    => 'bank',
                'permissions' => [
                    'org.profile.*', 'org.document.*', 'org.member.*', 'org.page.*',
                    'finance.product.*', 'finance.application.view', 'finance.application.review',
                    'finance.application.decide',
                    'reports.provider.view', 'reports.data.export',
                    'content.article.view',
                    'messaging.conversation.view', 'messaging.message.send',
                ],
            ],
            [
                'code'        => 'bank_officer',
                'name_ar'     => 'مسؤول ائتمان',
                'name_en'     => 'Credit Officer',
                'description' => 'يراجع طلبات التمويل ويطلب المستندات ويسجّل القرار.',
                'scope'       => 'organization',
                'org_type'    => 'bank',
                'permissions' => [
                    'org.profile.view', 'finance.product.view',
                    'finance.application.view', 'finance.application.review',
                    'finance.application.decide',
                    'reports.provider.view',
                    'messaging.conversation.view', 'messaging.message.send',
                ],
            ],
            [
                'code'        => 'ngo_admin',
                'name_ar'     => 'مدير المنظمة الأهلية',
                'name_en'     => 'NGO Administrator',
                'description' => 'ينشر البرامج والمنح والتدريب والإرشاد ويستقبل الطلبات.',
                'scope'       => 'organization',
                'org_type'    => 'ngo',
                'permissions' => [
                    'org.profile.*', 'org.document.*', 'org.member.*', 'org.page.*',
                    'services.offering.*', 'services.request.view', 'services.quotation.submit',
                    'services.milestone.manage',
                    'content.article.view',
                    'reports.provider.view', 'reports.data.export',
                    'messaging.conversation.view', 'messaging.message.send',
                ],
            ],
            [
                'code'        => 'provider_admin',
                'name_ar'     => 'مدير مقدّم الخدمة',
                'name_en'     => 'Service Provider Administrator',
                'description' => 'ينشر باقات الخدمات ويستقبل الطلبات ويقدّم عروض الأسعار ويدير التنفيذ.',
                'scope'       => 'organization',
                'org_type'    => 'service_provider',
                'permissions' => [
                    'org.profile.*', 'org.document.*', 'org.member.*', 'org.page.*',
                    'services.offering.*', 'services.request.view', 'services.quotation.submit',
                    'services.milestone.manage',
                    'content.article.view',
                    'reports.provider.view', 'reports.data.export',
                    'messaging.conversation.view', 'messaging.message.send',
                ],
            ],
            [
                'code'        => 'provider_staff',
                'name_ar'     => 'منفّذ خدمة',
                'name_en'     => 'Service Provider Staff',
                'description' => 'ينفّذ طلبات الخدمة ويحدّث المراحل ويتواصل مع المشروع.',
                'scope'       => 'organization',
                'org_type'    => 'service_provider',
                'permissions' => [
                    'org.profile.view', 'services.offering.view', 'services.request.view',
                    'services.milestone.manage',
                    'messaging.conversation.view', 'messaging.message.send',
                ],
            ],
            [
                'code'        => 'bds_manager',
                'name_ar'     => 'مدير مركز تطوير الأعمال',
                'name_en'     => 'BDS Centre Manager',
                'description' => 'يدير المركز وفريق الأخصائيين ويوزّع حالات الدعم ويتابع النتائج.',
                'scope'       => 'organization',
                'org_type'    => 'bds_center',
                'permissions' => [
                    'org.profile.*', 'org.document.*', 'org.member.*', 'org.page.*',
                    // صلاحيات المركز مسرودة صراحةً: النمط bds.* كان يمنح
                    // bds.case.view_any (اطلاع على حالات كل المراكز)، ومدير المركز
                    // يدير مركزه فقط. الإشراف العابر للمراكز يبقى لفريق المنصة.
                    // Listed explicitly: the bds.* wildcard granted
                    // bds.case.view_any — visibility over every centre's cases.
                    // A centre manager runs their own centre; cross-centre
                    // oversight stays with platform staff.
                    'bds.center.manage', 'bds.case.view', 'bds.case.manage',
                    'bds.case.assign', 'bds.note.internal', 'bds.note.shared',
                    'bds.consultation.manage', 'bds.plan.manage', 'bds.referral.create',
                    'assessment.needs.view',
                    'services.offering.view',
                    'content.article.view',
                    'reports.provider.view', 'reports.data.export',
                    'messaging.conversation.view', 'messaging.message.send',
                ],
            ],
            [
                'code'        => 'bds_specialist',
                'name_ar'     => 'أخصائي تطوير أعمال',
                'name_en'     => 'Business Development Specialist',
                'description' => 'يقيّم احتياجات المشروع ويسجّل الجلسات ويضع خطط العمل ويحيل المشروعات.',
                'scope'       => 'organization',
                'org_type'    => 'bds_center',
                'permissions' => [
                    'org.profile.view',
                    'bds.case.view', 'bds.case.manage',
                    'bds.note.internal', 'bds.note.shared',
                    'bds.consultation.manage', 'bds.plan.manage', 'bds.referral.create',
                    'assessment.needs.view',
                    'services.offering.view', 'finance.product.view',
                    'content.article.view',
                    'messaging.conversation.view', 'messaging.message.send',
                ],
            ],
            [
                'code'        => 'government_admin',
                'name_ar'     => 'مسؤول جهة حكومية',
                'name_en'     => 'Government Entity Administrator',
                'description' => 'يدير ملف الجهة الحكومية ويطّلع على التقارير المتاحة له.',
                'scope'       => 'organization',
                'org_type'    => 'government',
                'permissions' => [
                    'org.profile.*', 'org.document.*', 'org.member.view', 'org.page.*',
                    'content.article.view', 'reports.provider.view',
                    'messaging.conversation.view', 'messaging.message.send',
                ],
            ],
        ];
    }
}
