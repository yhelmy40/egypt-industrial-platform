<?php
/** عرض ملف باحث/خبير | Researcher / expert details */
$pageTitle = $profile['name'];
$keywords  = array_filter(array_map('trim', preg_split('/[،,]/u', $profile['expertise_keywords'] ?? '')));
?>

<div class="page-head d-flex justify-content-between align-items-center mb-3">
    <h2 class="section-title mb-0"><?= e($profile['name']) ?></h2>
    <div>
        <a href="<?= url('researcher') ?>" class="btn btn-outline-navy btn-sm">← القائمة</a>
        <?php if (Auth::isAdmin() || (int)($profile['user_id'] ?? 0) === (int) Auth::id()): ?>
            <a href="<?= url('researcher/edit/' . $profile['id']) ?>" class="btn btn-teal btn-sm">تعديل</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">البيانات الأساسية</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr><th class="text-muted" style="width:40%">النوع</th><td><span class="badge bg-secondary"><?= $profile['profile_type'] === 'expert' ? 'خبير / استشاري' : 'باحث / جامعي' ?></span></td></tr>
                    <tr><th class="text-muted">الجهة</th><td><?= e($profile['organization'] ?: '—') ?></td></tr>
                    <tr><th class="text-muted">التخصص</th><td><?= e($profile['specialization']) ?></td></tr>
                    <tr><th class="text-muted">المحافظة</th><td><?= e($profile['governorate_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">البريد</th><td><?= e($profile['email'] ?: '—') ?></td></tr>
                    <tr><th class="text-muted">الهاتف</th><td><?= e($profile['phone'] ?: '—') ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">كلمات الخبرة</div>
            <div class="card-body">
                <?php foreach ($keywords as $kw): ?><span class="kw-chip"><?= e($kw) ?></span><?php endforeach; ?>
                <?php if (empty($keywords)): ?><span class="text-muted">—</span><?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header">المشاريع السابقة</div>
            <div class="card-body"><?= nl2br(e($profile['previous_projects'] ?: '—')) ?></div>
        </div>
    </div>
</div>
