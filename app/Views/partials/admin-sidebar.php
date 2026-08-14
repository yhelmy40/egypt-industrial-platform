<?php
/**
 * القائمة الجانبية للإدارة | Admin sidebar.
 * تظهر العناصر حسب صلاحيات المستخدم فقط (مسؤول التشغيل يرى أقل من مدير المنصة).
 */

use App\Support\TenantContext;

$path = $_SERVER['REQUEST_URI'] ?? '';

$sections = [
    'المتابعة' => [
        ['لوحة التحكم', '/admin', '▤', 'reports.platform.view', true],
        ['تقارير المنصة', '/admin/reports', '📊', 'reports.platform.view', false],
    ],
    'المراجعة والاعتماد' => [
        ['طلبات التوثيق', '/admin/verifications', '✅', 'org.account.view_any', true],
        ['المنشآت', '/admin/verifications?status=', '🏢', 'org.account.view_any', true],
        ['مراجعة الإعلانات', '/admin/moderation', '🛒', 'marketplace.listing.moderate', true],
        ['الشكاوى والنزاعات', '/admin/complaints', '⚠', 'marketplace.complaint.manage', true],
        ['اعتماد المنتجات التمويلية', '/admin/finance/products', '🏦', 'finance.product.moderate', true],
        ['اعتماد باقات الخدمات', '/admin/services/offerings', '🧭', 'services.offering.moderate', true],
        ['فرز طلبات التمويل', '/admin/finance/applications', '📋', 'finance.application.view_any', true],
    ],
    'المحتوى' => [
        ['مركز المعرفة', '/admin/articles', '📚', 'content.article.manage', false],
        ['الصفحات الثابتة', '/admin/pages', '📄', 'content.page.manage', false],
        ['الأسئلة الشائعة', '/admin/faqs', '❓', 'content.faq.manage', false],
    ],
    'الضبط' => [
        ['المستخدمون', '/admin/users', '👥', 'platform.user.view', false],
        ['الأدوار والصلاحيات', '/admin/roles', '🔐', 'platform.role.view', false],
        ['البيانات المرجعية', '/admin/reference', '🗂', 'platform.reference.manage', false],
        ['إعدادات المنصة', '/admin/settings', '⚙', 'platform.settings.view', false],
        ['سجل التدقيق', '/admin/audit', '📜', 'platform.audit.view', false],
    ],
];
?>
<aside class="workspace-sidebar" id="workspaceSidebar">
    <a class="workspace-sidebar__brand" href="<?= e(url('/')) ?>">
        <?= $view->partial('partials/logo') ?>
        <span>إدارة المنصة</span>
    </a>

    <div class="workspace-sidebar__org">
        <span class="workspace-sidebar__org-name"><?= e((string) config('app.operator.name_ar')) ?></span>
        <span style="opacity:.7">الجهة المشغّلة للمنصة</span>
    </div>

    <nav class="workspace-nav" aria-label="<?= __e('common.main_menu') ?>">
        <?php foreach ($sections as $sectionLabel => $items): ?>
            <?php
            $visible = array_filter(
                $items,
                static fn (array $item): bool => $item[3] === null || TenantContext::can($item[3]),
            );
            if ($visible === []) { continue; }
            ?>
            <div class="workspace-nav__section"><?= e($sectionLabel) ?></div>
            <?php foreach ($visible as [$label, $href, $icon, $permission, $ready]): ?>
                <?php if ($ready): ?>
                    <a class="workspace-nav__link<?= rtrim($path, '/') === rtrim(url($href), '/') ? ' active' : '' ?>"
                       href="<?= e(url($href)) ?>">
                        <span aria-hidden="true"><?= e($icon) ?></span><span><?= e($label) ?></span>
                    </a>
                <?php else: ?>
                    <span class="workspace-nav__link" aria-disabled="true" title="ستتوفر هذه الوحدة في مرحلة لاحقة">
                        <span aria-hidden="true"><?= e($icon) ?></span><span><?= e($label) ?></span>
                        <span class="workspace-nav__soon">قريباً</span>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="workspace-nav__section">التنقّل</div>
        <a class="workspace-nav__link" href="<?= e(url('/app')) ?>">
            <span aria-hidden="true">↩</span><span>مساحة عمل المنشأة</span>
        </a>
    </nav>
</aside>
