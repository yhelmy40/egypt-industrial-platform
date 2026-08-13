<?php
/** عرض مصنع | Factory details */
$pageTitle = $factory['name'];
?>

<div class="page-head d-flex justify-content-between align-items-center mb-3">
    <h2 class="section-title mb-0"><?= e($factory['name']) ?></h2>
    <div>
        <a href="<?= url('factory') ?>" class="btn btn-outline-navy btn-sm">← القائمة</a>
        <?php if (Auth::isAdmin() || (int)($factory['user_id'] ?? 0) === (int) Auth::id()): ?>
            <a href="<?= url('factory/edit/' . $factory['id']) ?>" class="btn btn-teal btn-sm">تعديل</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">بيانات المصنع</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr><th class="text-muted" style="width:45%">القطاع</th><td><?= e($factory['sector_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">المحافظة</th><td><?= e($factory['governorate_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">مسؤول التواصل</th><td><?= e($factory['contact_person'] ?: '—') ?></td></tr>
                    <tr><th class="text-muted">البريد الإلكتروني</th><td><?= e($factory['email'] ?: '—') ?></td></tr>
                    <tr><th class="text-muted">الهاتف</th><td><?= e($factory['phone'] ?: '—') ?></td></tr>
                    <tr><th class="text-muted">مستوى استهلاك الطاقة</th><td><span class="badge bg-secondary"><?= e(energy_label($factory['energy_usage_level'])) ?></span></td></tr>
                    <tr><th class="text-muted">الطاقة الإنتاجية</th><td><?= e($factory['production_capacity'] ?: '—') ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">المنتجات</div>
            <div class="card-body"><?= nl2br(e($factory['products'] ?: '—')) ?></div>
        </div>
        <div class="card">
            <div class="card-header">أبرز التحديات</div>
            <div class="card-body"><?= nl2br(e($factory['main_challenges'] ?: '—')) ?></div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">التحديات المقدّمة من هذا المصنع</div>
    <div class="card-body p-0">
        <?php if (empty($challenges)): ?>
            <div class="empty-state p-4"><div class="ico">🎯</div><p>لا توجد تحديات لهذا المصنع.</p></div>
        <?php else: ?>
        <table class="table mb-0">
            <thead><tr><th>العنوان</th><th>الأولوية</th><th>الحالة</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($challenges as $c): ?>
                <tr>
                    <td><?= e($c['title']) ?></td>
                    <td><span class="badge bg-<?= priority_color($c['priority']) ?>"><?= e(priority_label($c['priority'])) ?></span></td>
                    <td><span class="badge bg-<?= challenge_status_color($c['status']) ?>"><?= e(challenge_status_label($c['status'])) ?></span></td>
                    <td><a href="<?= url('challenge/show/' . $c['id']) ?>" class="btn btn-outline-navy btn-sm">عرض</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
