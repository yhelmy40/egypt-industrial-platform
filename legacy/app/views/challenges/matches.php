<?php
/** المطابقة الذكية | Smart matching recommendations */
$pageTitle = 'المطابقة الذكية';
$isAdmin   = Auth::isAdmin();
$savedIds  = array_map(fn($s) => (int) $s['profile_id'], $saved ?? []);
?>

<div class="page-head d-flex justify-content-between align-items-start mb-3">
    <div>
        <h2 class="section-title mb-1">المطابقة الذكية</h2>
        <p class="text-muted small mb-0">التحدي: <strong><?= e($challenge['title']) ?></strong> — القطاع: <?= e($challenge['sector_name'] ?? '—') ?></p>
    </div>
    <a href="<?= url('challenge/show/' . $challenge['id']) ?>" class="btn btn-outline-navy btn-sm">← التحدي</a>
</div>

<div class="alert alert-info">
    🔎 يعتمد محرك المطابقة على قواعد بسيطة: مقارنة الكلمات المفتاحية للخبرة المطلوبة مع تخصصات الباحثين والخبراء، مع منح أولوية لتطابق القطاع. الدرجة الأعلى تعني تطابقاً أقوى.
</div>

<form method="post" action="<?= url('challenge/assign/' . $challenge['id']) ?>">
    <?= Csrf::field() ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>الباحثون والخبراء الموصى بهم (<?= count($matches) ?>)</span>
            <?php if ($isAdmin && !empty($matches)): ?>
                <button class="btn btn-teal btn-sm">💾 حفظ المطابقات المختارة</button>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (empty($matches)): ?>
                <div class="empty-state p-4"><div class="ico">🔍</div>
                    <p>لا توجد مطابقات. تأكد من إدخال كلمات الخبرة المطلوبة في التحدي ووجود ملفات باحثين/خبراء.</p></div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <?php if ($isAdmin): ?><th style="width:48px">ربط</th><?php endif; ?>
                        <th>الاسم</th><th>النوع</th><th>التخصص</th><th>الكلمات المشتركة</th><th>الدرجة</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($matches as $m): $p = $m['profile']; ?>
                    <tr>
                        <?php if ($isAdmin): ?>
                        <td class="text-center">
                            <input type="checkbox" class="form-check-input" name="profiles[]"
                                   value="<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $savedIds, true) ? 'checked' : '' ?>>
                        </td>
                        <?php endif; ?>
                        <td><strong><?= e($p['name']) ?></strong><br><small class="text-muted"><?= e($p['organization'] ?? '') ?></small></td>
                        <td><span class="badge bg-secondary"><?= ($p['profile_type'] ?? '') === 'expert' ? 'خبير' : 'باحث' ?></span></td>
                        <td><?= e($p['specialization'] ?? '—') ?></td>
                        <td>
                            <?php foreach ($m['matched_keywords'] as $kw): ?>
                                <span class="kw-chip"><?= e($kw) ?></span>
                            <?php endforeach; ?>
                            <?php if (empty($m['matched_keywords'])): ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td><span class="match-score"><?= (int) $m['score'] ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isAdmin && !empty($matches)): ?>
        <div class="mt-3">
            <button class="btn btn-primary">💾 حفظ المطابقات وتحديث حالة التحدي</button>
        </div>
    <?php endif; ?>
</form>
