<?php
/** بنك التحديات | Challenges list */
$pageTitle = 'بنك التحديات الصناعية';
?>

<div class="page-head d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="section-title mb-1">بنك التحديات الصناعية</h2>
        <p class="text-muted small mb-0">إجمالي <?= count($challenges) ?> تحدٍّ</p>
    </div>
    <?php if (Auth::isAdmin() || Auth::is('factory')): ?>
        <a href="<?= url('challenge/create') ?>" class="btn btn-primary">➕ تقديم تحدٍّ</a>
    <?php endif; ?>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="<?= url('challenge') ?>" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small">الحالة</label>
                <select name="status" class="form-select">
                    <option value="">كل الحالات</option>
                    <?php foreach (Challenge::STATUSES as $st): ?>
                        <option value="<?= $st ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>><?= e(challenge_status_label($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small">القطاع</label>
                <select name="sector_id" class="form-select">
                    <option value="">كل القطاعات</option>
                    <?php foreach ($sectors as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= (int)($filters['sector_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-teal flex-grow-1">تصفية</button>
                <a href="<?= url('challenge') ?>" class="btn btn-outline-navy">إعادة ضبط</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <input type="text" class="form-control mb-3" placeholder="🔍 بحث سريع..." data-table-filter="#challengesTable">
        <div class="table-responsive">
        <table class="table align-middle" id="challengesTable">
            <thead><tr><th>#</th><th>العنوان</th><th>المصنع</th><th>القطاع</th><th>الأولوية</th><th>الحالة</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($challenges)): ?>
                <tr><td colspan="7"><div class="empty-state p-4"><div class="ico">🎯</div><p>لا توجد تحديات مطابقة.</p></div></td></tr>
            <?php else: foreach ($challenges as $i => $c): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= e($c['title']) ?></strong></td>
                    <td><?= e($c['factory_name'] ?? '—') ?></td>
                    <td><?= e($c['sector_name'] ?? '—') ?></td>
                    <td><span class="badge bg-<?= priority_color($c['priority']) ?>"><?= e(priority_label($c['priority'])) ?></span></td>
                    <td><span class="badge bg-<?= challenge_status_color($c['status']) ?>"><?= e(challenge_status_label($c['status'])) ?></span></td>
                    <td><a href="<?= url('challenge/show/' . $c['id']) ?>" class="btn btn-outline-navy btn-sm">عرض</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
