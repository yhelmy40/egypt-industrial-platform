<?php
/** قائمة الباحثين والخبراء | Researchers / experts list */
$pageTitle = 'الباحثون والخبراء';
?>

<div class="page-head d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="section-title mb-1">الباحثون والخبراء</h2>
        <p class="text-muted small mb-0">إجمالي <?= count($profiles) ?> ملف</p>
    </div>
    <?php if (Auth::isAdmin() || in_array(Auth::role(), ['researcher', 'expert'], true)): ?>
        <a href="<?= url('researcher/create') ?>" class="btn btn-primary">➕ إضافة ملف</a>
    <?php endif; ?>
</div>

<div class="card mb-3">
    <div class="card-body d-flex gap-2 flex-wrap">
        <a href="<?= url('researcher') ?>" class="btn btn-sm <?= empty($type) ? 'btn-teal' : 'btn-outline-navy' ?>">الكل</a>
        <a href="<?= url('researcher?type=researcher') ?>" class="btn btn-sm <?= ($type ?? '') === 'researcher' ? 'btn-teal' : 'btn-outline-navy' ?>">الباحثون</a>
        <a href="<?= url('researcher?type=expert') ?>" class="btn btn-sm <?= ($type ?? '') === 'expert' ? 'btn-teal' : 'btn-outline-navy' ?>">الخبراء</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <input type="text" class="form-control mb-3" placeholder="🔍 بحث..." data-table-filter="#rpTable">
        <div class="table-responsive">
        <table class="table align-middle" id="rpTable">
            <thead><tr><th>#</th><th>الاسم</th><th>النوع</th><th>الجهة</th><th>التخصص</th><th>المحافظة</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($profiles)): ?>
                <tr><td colspan="7"><div class="empty-state p-4"><div class="ico">🔬</div><p>لا توجد ملفات.</p></div></td></tr>
            <?php else: foreach ($profiles as $i => $p): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= e($p['name']) ?></strong></td>
                    <td><span class="badge bg-secondary"><?= $p['profile_type'] === 'expert' ? 'خبير' : 'باحث' ?></span></td>
                    <td><?= e($p['organization'] ?: '—') ?></td>
                    <td><?= e($p['specialization']) ?></td>
                    <td><?= e($p['governorate_name'] ?? '—') ?></td>
                    <td class="text-nowrap">
                        <a href="<?= url('researcher/show/' . $p['id']) ?>" class="btn btn-outline-navy btn-sm">عرض</a>
                        <?php if (Auth::isAdmin() || (int)($p['user_id'] ?? 0) === (int) Auth::id()): ?>
                            <a href="<?= url('researcher/edit/' . $p['id']) ?>" class="btn btn-teal btn-sm">تعديل</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
