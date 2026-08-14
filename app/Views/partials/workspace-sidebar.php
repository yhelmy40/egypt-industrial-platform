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
 */
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
    'الخدمات والدعم' => [
        ['فرص التمويل', '/app/financing', '🏦', 'finance.product.view', false],
        ['طلبات التمويل', '/app/financing/applications', '📋', 'finance.application.view', false],
        ['الخدمات غير المالية', '/app/services', '🧭', 'services.request.view', false],
        ['تقييم الاحتياجات', '/app/assessment', '📊', 'assessment.needs.submit', false],
        ['الدعم الفني والإرشادي', '/app/bds', '🤝', 'bds.case.request', false],
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
