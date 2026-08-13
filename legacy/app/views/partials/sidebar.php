<?php
/** القائمة الجانبية | Sidebar navigation (role-aware) */
$role = Auth::role();
$cur  = $_GET['url'] ?? '';
$seg  = explode('/', trim($cur, '/'))[0] ?? '';

/** هل العنصر نشط؟ | active helper */
$active = fn(string $c) => $seg === $c ? 'active' : '';
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="badge-logo">🏭</div>
        <div class="brand-text">
            <strong>منصة البحث والتطوير الصناعي</strong>
            <span>وزارة الصناعة — جمهورية مصر العربية</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <a href="<?= url('dashboard') ?>" class="<?= $active('dashboard') ?>">
            <span class="ico">📊</span> لوحة المعلومات
        </a>

        <p class="nav-section">الوحدات الأساسية</p>

        <?php if (in_array($role, ['admin', 'factory'], true)): ?>
        <a href="<?= url('challenge') ?>" class="<?= $active('challenge') ?>">
            <span class="ico">🎯</span> بنك التحديات
        </a>
        <?php else: ?>
        <a href="<?= url('challenge') ?>" class="<?= $active('challenge') ?>">
            <span class="ico">🎯</span> التحديات الصناعية
        </a>
        <?php endif; ?>

        <a href="<?= url('factory') ?>" class="<?= $active('factory') ?>">
            <span class="ico">🏭</span>
            <?= $role === 'factory' ? 'ملف المصنع' : 'المصانع' ?>
        </a>

        <a href="<?= url('researcher') ?>" class="<?= $active('researcher') ?>">
            <span class="ico">🔬</span> الباحثون والخبراء
        </a>

        <a href="<?= url('project') ?>" class="<?= $active('project') ?>">
            <span class="ico">🧪</span> مشاريع البحث والتطوير
        </a>

        <a href="<?= url('funding') ?>" class="<?= $active('funding') ?>">
            <span class="ico">💰</span> فرص التمويل
        </a>

        <a href="<?= url('knowledge') ?>" class="<?= $active('knowledge') ?>">
            <span class="ico">📚</span> مكتبة المعرفة
        </a>

        <p class="nav-section">الحساب</p>

        <?php if ($role === 'factory'): ?>
        <a href="<?= url('factory/profile') ?>"><span class="ico">📝</span> تحديث ملف المصنع</a>
        <?php elseif (in_array($role, ['researcher', 'expert'], true)): ?>
        <a href="<?= url('researcher/profile') ?>"><span class="ico">📝</span> ملفي الشخصي</a>
        <?php elseif ($role === 'investor'): ?>
        <a href="<?= url('funding/create') ?>"><span class="ico">➕</span> إضافة فرصة تمويل</a>
        <?php endif; ?>

        <a href="<?= url('notification') ?>" class="<?= $active('notification') ?>">
            <span class="ico">🔔</span> الإشعارات
        </a>
    </nav>

    <div class="sidebar-foot">
        <?= e(APP_NAME_EN) ?><br>
        الإصدار <?= e(APP_VERSION) ?>
    </div>
</aside>
