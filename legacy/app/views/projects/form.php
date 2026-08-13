<?php
/** نموذج المشروع | Project create/edit form */
$editing   = !empty($project);
$pageTitle = $editing ? 'تعديل مشروع' : 'إنشاء مشروع بحث وتطوير';
$action    = $editing ? url('project/update/' . $project['id']) : url('project/store');
$f = fn($k, $d = '') => $editing ? e($project[$k] ?? $d) : old($k, $d);

// قيم مبدئية من تحدٍّ محوَّل | prefill from a converted challenge
$preChallengeId = $editing ? ($project['challenge_id'] ?? '') : ($preChallenge['id'] ?? old('challenge_id'));
$preFactoryId   = $editing ? ($project['factory_id'] ?? '') : ($preChallenge['factory_id'] ?? old('factory_id'));
$preTitle       = $editing ? ($project['title'] ?? '') : ($preChallenge ? 'مشروع: ' . $preChallenge['title'] : old('title'));
?>

<div class="page-head mb-3">
    <h2 class="section-title mb-1"><?= e($pageTitle) ?></h2>
    <p class="text-muted small mb-0">حوِّل تحدياً معتمداً إلى مشروع بحث وتطوير وحدّد الجهات والميزانية.</p>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= $action ?>" class="needs-validation" novalidate>
            <?= Csrf::field() ?>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">عنوان المشروع <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required value="<?= e($preTitle) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">الحالة</label>
                    <select name="status" class="form-select">
                        <?php foreach (['planned' => 'مخطط', 'active' => 'نشط', 'completed' => 'مكتمل', 'cancelled' => 'ملغي'] as $val => $lbl): ?>
                            <option value="<?= $val ?>" <?= ($editing ? ($project['status'] ?? 'planned') === $val : old_is('status', $val)) ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-12">
                    <label class="form-label">التحدي المرتبط <span class="text-danger">*</span></label>
                    <select name="challenge_id" class="form-select" required>
                        <option value="">— اختر تحدياً —</option>
                        <?php foreach ($challenges as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= (int) $preChallengeId === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['title']) ?> — <?= e($c['sector_name'] ?? '') ?> [<?= e(challenge_status_label($c['status'])) ?>]
                            </option>
                        <?php endforeach; ?>
                        <?php if ($editing && $preChallengeId && !in_array((int) $preChallengeId, array_map(fn($c) => (int) $c['id'], $challenges), true)): ?>
                            <option value="<?= (int) $preChallengeId ?>" selected><?= e($project['challenge_title'] ?? ('تحدٍّ #' . $preChallengeId)) ?></option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">المصنع</label>
                    <select name="factory_id" class="form-select">
                        <option value="">— غير محدد —</option>
                        <?php foreach ($factories as $fac): ?>
                            <option value="<?= $fac['id'] ?>" <?= (int) $preFactoryId === (int) $fac['id'] ? 'selected' : '' ?>><?= e($fac['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">الباحث / الخبير</label>
                    <select name="researcher_profile_id" class="form-select">
                        <option value="">— غير محدد —</option>
                        <?php foreach ($profiles as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($editing ? ($project['researcher_profile_id'] ?? '') == $p['id'] : old_is('researcher_profile_id', $p['id'])) ? 'selected' : '' ?>>
                                <?= e($p['name']) ?> (<?= $p['profile_type'] === 'expert' ? 'خبير' : 'باحث' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">تاريخ البداية</label>
                    <input type="date" name="start_date" class="form-control" value="<?= $f('start_date') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">تاريخ النهاية</label>
                    <input type="date" name="end_date" class="form-control" value="<?= $f('end_date') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">الميزانية التقديرية (ج.م)</label>
                    <input type="number" step="0.01" name="budget_estimate" class="form-control" value="<?= $f('budget_estimate') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">المخرجات المتوقعة</label>
                    <textarea name="expected_outcome" class="form-control" rows="3"><?= $f('expected_outcome') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">المخرجات الفعلية</label>
                    <textarea name="actual_outcome" class="form-control" rows="3"><?= $f('actual_outcome') ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $editing ? '💾 حفظ التعديلات' : '➕ إنشاء المشروع' ?></button>
                <a href="<?= url('project') ?>" class="btn btn-outline-navy">إلغاء</a>
            </div>
        </form>
    </div>
</div>
