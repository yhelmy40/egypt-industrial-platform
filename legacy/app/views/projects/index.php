<?php
/** قائمة المشاريع | R&D projects list */
$pageTitle = 'مشاريع البحث والتطوير';
?>

<div class="page-head d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="section-title mb-1">مشاريع البحث والتطوير</h2>
        <p class="text-muted small mb-0">إجمالي <?= count($projects) ?> مشروع</p>
    </div>
    <?php if (Auth::isAdmin()): ?>
        <a href="<?= url('project/create') ?>" class="btn btn-primary">➕ مشروع جديد</a>
    <?php endif; ?>
</div>

<div class="card mb-3">
    <div class="card-body d-flex gap-2 flex-wrap">
        <?php $cur = $_GET['status'] ?? ''; ?>
        <a href="<?= url('project') ?>" class="btn btn-sm <?= $cur === '' ? 'btn-teal' : 'btn-outline-navy' ?>">الكل</a>
        <?php foreach (['planned', 'active', 'completed', 'cancelled'] as $st): ?>
            <a href="<?= url('project?status=' . $st) ?>" class="btn btn-sm <?= $cur === $st ? 'btn-teal' : 'btn-outline-navy' ?>"><?= e(project_status_label($st)) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <input type="text" class="form-control mb-3" placeholder="🔍 بحث..." data-table-filter="#projTable">
        <div class="table-responsive">
        <table class="table align-middle" id="projTable">
            <thead><tr><th>#</th><th>المشروع</th><th>المصنع</th><th>الباحث/الخبير</th><th>الميزانية</th><th>الحالة</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($projects)): ?>
                <tr><td colspan="7"><div class="empty-state p-4"><div class="ico">🧪</div><p>لا توجد مشاريع.</p></div></td></tr>
            <?php else: foreach ($projects as $i => $p): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= e($p['title']) ?></strong><br><small class="text-muted"><?= e($p['challenge_title'] ?? '') ?></small></td>
                    <td><?= e($p['factory_name'] ?? '—') ?></td>
                    <td><?= e($p['researcher_name'] ?? '—') ?></td>
                    <td><?= fmt_money($p['budget_estimate']) ?></td>
                    <td><span class="badge bg-<?= project_status_color($p['status']) ?>"><?= e(project_status_label($p['status'])) ?></span></td>
                    <td><a href="<?= url('project/show/' . $p['id']) ?>" class="btn btn-outline-navy btn-sm">عرض</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
