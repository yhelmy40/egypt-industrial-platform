<?php
/** عرض مشروع | Project details */
$pageTitle = $project['title'];
?>

<div class="page-head d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="section-title mb-1"><?= e($project['title']) ?></h2>
        <span class="badge bg-<?= project_status_color($project['status']) ?>"><?= e(project_status_label($project['status'])) ?></span>
    </div>
    <div>
        <a href="<?= url('project') ?>" class="btn btn-outline-navy btn-sm">← القائمة</a>
        <?php if (Auth::isAdmin()): ?>
            <a href="<?= url('project/edit/' . $project['id']) ?>" class="btn btn-teal btn-sm">تعديل</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">تفاصيل المشروع</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr><th class="text-muted" style="width:45%">التحدي المرتبط</th>
                        <td><a href="<?= url('challenge/show/' . $project['challenge_id']) ?>"><?= e($project['challenge_title'] ?? '—') ?></a></td></tr>
                    <tr><th class="text-muted">المصنع</th><td><?= e($project['factory_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">الباحث/الخبير</th><td><?= e($project['researcher_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">القطاع</th><td><?= e($project['sector_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">تاريخ البداية</th><td><?= fmt_date($project['start_date']) ?></td></tr>
                    <tr><th class="text-muted">تاريخ النهاية</th><td><?= fmt_date($project['end_date']) ?></td></tr>
                    <tr><th class="text-muted">الميزانية التقديرية</th><td><?= fmt_money($project['budget_estimate']) ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">المخرجات المتوقعة</div>
            <div class="card-body"><?= nl2br(e($project['expected_outcome'] ?: '—')) ?></div>
        </div>
        <div class="card">
            <div class="card-header">المخرجات الفعلية</div>
            <div class="card-body"><?= nl2br(e($project['actual_outcome'] ?: '—')) ?></div>
        </div>
    </div>
</div>
