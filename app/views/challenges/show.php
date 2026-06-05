<?php
/** عرض تحدٍّ | Challenge details */
$pageTitle = $challenge['title'];
$isAdmin   = Auth::isAdmin();
?>

<div class="page-head d-flex justify-content-between align-items-start mb-3">
    <div>
        <h2 class="section-title mb-1"><?= e($challenge['title']) ?></h2>
        <p class="text-muted small mb-0">
            <span class="badge bg-<?= challenge_status_color($challenge['status']) ?>"><?= e(challenge_status_label($challenge['status'])) ?></span>
            <span class="badge bg-<?= priority_color($challenge['priority']) ?>"><?= e(priority_label($challenge['priority'])) ?></span>
        </p>
    </div>
    <a href="<?= url('challenge') ?>" class="btn btn-outline-navy btn-sm">← القائمة</a>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">وصف المشكلة</div>
            <div class="card-body"><?= nl2br(e($challenge['description'])) ?></div>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card h-100"><div class="card-header">الأثر المتوقع</div>
                    <div class="card-body"><?= nl2br(e($challenge['expected_impact'] ?: '—')) ?></div></div>
            </div>
            <div class="col-md-6">
                <div class="card h-100"><div class="card-header">الخبرة المطلوبة</div>
                    <div class="card-body"><?= nl2br(e($challenge['needed_expertise'] ?: '—')) ?></div></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">معلومات</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr><th class="text-muted">المصنع</th><td><?= e($challenge['factory_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">القطاع</th><td><?= e($challenge['sector_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">مقدّم التحدي</th><td><?= e($challenge['creator_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">تاريخ التقديم</th><td><?= fmt_date($challenge['created_at'] ?? null) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">الإجراءات</div>
            <div class="card-body d-grid gap-2">
                <?php if ($isAdmin && in_array($challenge['status'], ['pending', 'under_review'], true)): ?>
                    <form method="post" action="<?= url('challenge/approve/' . $challenge['id']) ?>">
                        <?= Csrf::field() ?>
                        <button class="btn btn-teal w-100">✅ اعتماد التحدي</button>
                    </form>
                    <form method="post" action="<?= url('challenge/reject/' . $challenge['id']) ?>"
                          data-confirm="هل تريد رفض هذا التحدي؟">
                        <?= Csrf::field() ?>
                        <button class="btn btn-outline-navy w-100">✖ رفض التحدي</button>
                    </form>
                <?php endif; ?>

                <?php if ($isAdmin || Auth::is('factory')): ?>
                    <a href="<?= url('challenge/matches/' . $challenge['id']) ?>" class="btn btn-primary">🔗 المطابقة الذكية</a>
                <?php endif; ?>

                <?php if ($isAdmin && in_array($challenge['status'], ['matched', 'open'], true)): ?>
                    <a href="<?= url('project/create/' . $challenge['id']) ?>" class="btn btn-teal">🧪 تحويل إلى مشروع</a>
                <?php endif; ?>

                <?php if ($isAdmin || (Auth::is('factory'))): ?>
                    <a href="<?= url('challenge/edit/' . $challenge['id']) ?>" class="btn btn-outline-navy">✏ تعديل</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">الباحثون / الخبراء المرتبطون</div>
    <div class="card-body p-0">
        <?php if (empty($savedMatches)): ?>
            <div class="empty-state p-4"><div class="ico">🔗</div><p>لم يتم ربط أي باحث أو خبير بعد.</p></div>
        <?php else: ?>
        <table class="table mb-0">
            <thead><tr><th>الاسم</th><th>النوع</th><th>الجهة</th><th>الدرجة</th><th>الكلمات المشتركة</th></tr></thead>
            <tbody>
            <?php foreach ($savedMatches as $m): ?>
                <tr>
                    <td><strong><?= e($m['profile_name']) ?></strong></td>
                    <td><span class="badge bg-secondary"><?= $m['profile_type'] === 'expert' ? 'خبير' : 'باحث' ?></span></td>
                    <td><?= e($m['organization'] ?: '—') ?></td>
                    <td><span class="match-score"><?= (int) $m['match_score'] ?></span></td>
                    <td><small class="text-muted"><?= e($m['matched_keywords'] ?: '—') ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
