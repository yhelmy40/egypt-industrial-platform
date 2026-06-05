<?php
/** قائمة المصانع | Factories list */
$pageTitle = 'المصانع';
?>

<div class="page-head d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="section-title mb-1">المصانع المسجلة</h2>
        <p class="text-muted small mb-0">إجمالي <?= count($factories) ?> مصنع</p>
    </div>
    <?php if (Auth::isAdmin() || Auth::is('factory')): ?>
        <a href="<?= url('factory/create') ?>" class="btn btn-primary">➕ إضافة مصنع</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <input type="text" class="form-control mb-3" placeholder="🔍 بحث في المصانع..."
               data-table-filter="#factoriesTable">
        <div class="table-responsive">
        <table class="table align-middle" id="factoriesTable">
            <thead>
                <tr><th>#</th><th>اسم المصنع</th><th>القطاع</th><th>المحافظة</th><th>مسؤول التواصل</th><th>الطاقة</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($factories)): ?>
                <tr><td colspan="7"><div class="empty-state p-4"><div class="ico">🏭</div><p>لا توجد مصانع مسجلة بعد.</p></div></td></tr>
            <?php else: foreach ($factories as $i => $f): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= e($f['name']) ?></strong></td>
                    <td><?= e($f['sector_name'] ?? '—') ?></td>
                    <td><?= e($f['governorate_name'] ?? '—') ?></td>
                    <td><?= e($f['contact_person'] ?: '—') ?></td>
                    <td><span class="badge bg-secondary"><?= e(energy_label($f['energy_usage_level'])) ?></span></td>
                    <td class="text-nowrap">
                        <a href="<?= url('factory/show/' . $f['id']) ?>" class="btn btn-outline-navy btn-sm">عرض</a>
                        <?php if (Auth::isAdmin() || (int)($f['user_id'] ?? 0) === (int) Auth::id()): ?>
                            <a href="<?= url('factory/edit/' . $f['id']) ?>" class="btn btn-teal btn-sm">تعديل</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
