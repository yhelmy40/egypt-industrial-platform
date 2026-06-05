<?php
/** نموذج التحدي | Challenge create/edit form */
$editing   = !empty($challenge);
$pageTitle = $editing ? 'تعديل تحدٍّ' : 'تقديم تحدٍّ صناعي';
$action    = $editing ? url('challenge/update/' . $challenge['id']) : url('challenge/store');
$f = fn($k, $d = '') => $editing ? e($challenge[$k] ?? $d) : old($k, $d);
?>

<div class="page-head mb-3">
    <h2 class="section-title mb-1"><?= e($pageTitle) ?></h2>
    <p class="text-muted small mb-0">صف التحدي بوضوح وحدّد الخبرة المطلوبة لتحسين المطابقة مع الباحثين والخبراء.</p>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= $action ?>" class="needs-validation" novalidate>
            <?= Csrf::field() ?>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">عنوان التحدي <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required value="<?= $f('title') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">الأولوية</label>
                    <select name="priority" class="form-select">
                        <?php foreach (['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية'] as $val => $lbl): ?>
                            <option value="<?= $val ?>" <?= ($editing ? ($challenge['priority'] ?? 'medium') === $val : old_is('priority', $val)) ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">وصف المشكلة <span class="text-danger">*</span></label>
                    <textarea name="description" class="form-control" rows="4" required><?= $f('description') ?></textarea>
                </div>

                <div class="col-md-6">
                    <label class="form-label">القطاع <span class="text-danger">*</span></label>
                    <select name="sector_id" class="form-select" required>
                        <option value="">— اختر —</option>
                        <?php foreach ($sectors as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($editing ? ($challenge['sector_id'] ?? '') == $s['id'] : old_is('sector_id', $s['id'])) ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (Auth::isAdmin()): ?>
                <div class="col-md-6">
                    <label class="form-label">معرّف المصنع (اختياري)</label>
                    <input type="number" name="factory_id" class="form-control" placeholder="رقم المصنع" value="<?= $f('factory_id') ?>">
                </div>
                <?php endif; ?>

                <div class="col-md-6">
                    <label class="form-label">الأثر المتوقع</label>
                    <textarea name="expected_impact" class="form-control" rows="3"><?= $f('expected_impact') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">الخبرة المطلوبة (كلمات مفتاحية)</label>
                    <textarea name="needed_expertise" class="form-control" rows="3" placeholder="مثال: كفاءة الطاقة، معالجة المياه، صيانة تنبؤية"><?= $f('needed_expertise') ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $editing ? '💾 حفظ التعديلات' : '➕ تقديم التحدي' ?></button>
                <a href="<?= url('challenge') ?>" class="btn btn-outline-navy">إلغاء</a>
            </div>
        </form>
    </div>
</div>
