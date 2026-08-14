<?php
/**
 * القائمة الجانبية لمساحة العمل | Workspace sidebar.
 *
 * تُعرض العناصر حسب صلاحيات المستخدم الفعّالة فقط — القائمة انعكاس مباشر
 * لمصفوفة الصلاحيات، لا قائمة ثابتة.
 * Items render according to the user's effective permissions — the menu is a
 * direct reflection of the permission matrix, not a fixed list.
 */

use App\Support\TenantContext;

$path         = $_SERVER['REQUEST_URI'] ?? '';
$organization = TenantContext::organization();

/**
 * كل عنصر: [التسمية، الرابط، الأيقونة، الصلاحية المطلوبة أو null، جاهز؟]
 *
 * بعض الصلاحيات يحملها أكثر من جانب بحكم طبيعتها — «عرض طلبات الخدمة» مثلاً
 * يحملها المشروع ومقدّم الخدمة معاً — فالصلاحية وحدها لا تكفي لإخفاء قسم لا
 * يعني المستخدم. لذلك يقيَّد قسم «ما نقدّمه» بنوع المنشأة أيضاً: عرضه لصاحب
 * مشروع صغير يملأ قائمته بشاشات فارغة لا عمل له فيها.
 */
$organizationType = (string) ($organization['type_code'] ?? '');

/** أقسام مقصورة على أنواع منشآت بعينها | Sections limited to certain org types. */
$sectionOrgTypes = [
    'ما نقدّمه'            => ['bank', 'service_provider', 'ngo', 'bds_center'],
    'مركز تطوير الأعمال' => ['bds_center'],
];
$sections = [
    'العمل اليومي' => [
        ['لوحة التحكم', '/app', '▤', null, true],
        ['الطلبات', '/app/orders', '📦', 'marketplace.order.view', true],
        ['الاستفسارات', '/app/enquiries', '💬', 'marketplace.enquiry.view', true],
        ['الإشعارات', '/app/notifications', '🔔', null, true],
        ['الرسائل', '/app/messages', '✉', 'messaging.conversation.view', false],
    ],
    'المنشأة' => [
        ['ملف المنشأة', '/app/organization', '🏢', 'org.profile.view', true],
        ['المستندات', '/app/organization/documents', '📄', 'org.document.view', true],
        ['الصفحة التعريفية', '/app/page', '🌐', 'org.page.manage', true],
        ['الفريق', '/app/team', '👥', 'org.member.view', true],
    ],
    'السوق' => [
        ['المنتجات والخدمات', '/app/listings', '🛒', 'marketplace.listing.view', true],
        ['عروض الأسعار', '/app/quotations', '🧾', 'marketplace.quotation.manage', true],
    ],
    'التمويل والخدمات' => [
        ['فرص التمويل', '/app/finance/opportunities', '🏦', 'finance.product.view', true],
        ['طلبات التمويل', '/app/finance/applications', '📋', 'finance.application.view', true],
        ['الخدمات غير المالية', '/app/services/browse', '🧭', 'services.offering.view', true],
        ['طلبات الخدمة', '/app/services/my-requests', '🤝', 'services.request.submit', true],
        ['تقييم الاحتياجات', '/app/assessment', '📊', 'assessment.needs.view', true],
        ['مراكز تطوير الأعمال', '/app/bds/centers', '🎓', 'bds.case.request', true],
        ['طلبات الدعم', '/app/bds/my-cases', '🗂', 'bds.case.request', true],
    ],
    // مساحة عمل مركز تطوير الأعمال | The BDS centre workspace
    'مركز تطوير الأعمال' => [
        ['حالات الدعم', '/app/bds/cases', '🧑‍🏫', 'bds.case.manage', true],
    ],
    // تظهر لمقدّمي الخدمات والمؤسسات المالية فقط، بحكم الصلاحيات
    'ما نقدّمه' => [
        ['منتجاتنا التمويلية', '/app/finance/products', '💳', 'finance.product.manage', true],
        ['طلبات التمويل الواردة', '/app/finance/requests', '📥', 'finance.application.review', true],
        ['باقات خدماتنا', '/app/services/offerings', '🧰', 'services.offering.manage', true],
        ['طلبات الخدمة الواردة', '/app/services/requests', '📨', 'services.request.view', true],
    ],
    'إدارة الأعمال' => [
        ['العملاء', '/app/crm/contacts', '👤', 'crm.contact.view', false],
        ['الفرص البيعية', '/app/crm/opportunities', '📈', 'crm.opportunity.view', false],
        ['المخزون', '/app/erp/inventory', '📦', 'erp.item.view', false],
        ['الفواتير', '/app/erp/invoices', '🧾', 'erp.invoice.view', false],
        ['المصروفات', '/app/erp/expenses', '💰', 'erp.expense.manage', false],
        ['التقارير', '/app/reports', '📊', 'reports.organization.view', false],
    ],
];
?>
<aside class="workspace-sidebar" id="workspaceSidebar">
    <a class="workspace-sidebar__brand" href="<?= e(url('/')) ?>">
        <?= $view->partial('partials/logo') ?>
        <span><?= __e('portal.platform_name') ?></span>
    </a>

    <?php if ($organization !== null): ?>
        <div class="workspace-sidebar__org">
            <span class="workspace-sidebar__org-name">
                <?= e($organization['trading_name'] ?: $organization['legal_name']) ?>
            </span>
            <span style="opacity:.7"><?= e($organization['type_name'] ?? '') ?></span>
            <?php if (($organization['status'] ?? '') === 'verified'): ?>
                <span class="np-badge np-badge--success mt-2 d-inline-flex">
                    <span aria-hidden="true">✓</span> <?= __e('common.verified') ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <nav class="workspace-nav" aria-label="<?= __e('common.main_menu') ?>">
        <?php foreach ($sections as $sectionLabel => $items): ?>
            <?php
            // القسم المقصور على أنواع بعينها يختفي عن غيرها قبل فحص الصلاحيات
            if (isset($sectionOrgTypes[$sectionLabel])
                && !in_array($organizationType, $sectionOrgTypes[$sectionLabel], true)
            ) {
                continue;
            }

            // إخفاء القسم بالكامل إذا لم يملك المستخدم أي صلاحية داخله
            $visible = array_filter(
                $items,
                static fn (array $item): bool => $item[3] === null || TenantContext::can($item[3]),
            );
            if ($visible === []) {
                continue;
            }
            ?>
            <div class="workspace-nav__section"><?= e($sectionLabel) ?></div>

            <?php foreach ($visible as [$label, $href, $icon, $permission, $ready]): ?>
                <?php if ($ready): ?>
                    <a class="workspace-nav__link<?= rtrim($path, '/') === rtrim(url($href), '/') ? ' active' : '' ?>"
                       href="<?= e(url($href)) ?>">
                        <span aria-hidden="true"><?= e($icon) ?></span>
                        <span><?= e($label) ?></span>
                    </a>
                <?php else: ?>
                    <span class="workspace-nav__link" aria-disabled="true"
                          title="ستتوفر هذه الوحدة في مرحلة لاحقة">
                        <span aria-hidden="true"><?= e($icon) ?></span>
                        <span><?= e($label) ?></span>
                        <span class="workspace-nav__soon">قريباً</span>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?php if (TenantContext::isPlatformStaff()): ?>
            <div class="workspace-nav__section">إدارة المنصة</div>
            <a class="workspace-nav__link" href="<?= e(url('/admin')) ?>">
                <span aria-hidden="true">⚙</span>
                <span>لوحة تحكم المنصة</span>
            </a>
        <?php endif; ?>
    </nav>
</aside>
