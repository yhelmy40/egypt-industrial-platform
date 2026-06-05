<?php
/** نموذج المصنع | Factory create/edit form */
$editing   = !empty($factory);
$pageTitle = $editing ? 'تعديل ملف المصنع' : 'إضافة مصنع';
$action    = $editing ? url('factory/update/' . $factory['id']) : url('factory/store');
$f = fn($k, $d = '') => $editing ? e($factory[$k] ?? $d) : old($k, $d);
?>

<div class="page-head mb-3">
    <h2 class="section-title mb-1"><?= e($pageTitle) ?></h2>
    <p class="text-muted small mb-0">أدخل بيانات المصنع بدقة لتحسين نتائج المطابقة والتقارير.</p>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= $action ?>" class="needs-validation" novalidate>
            <?= Csrf::field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">اسم المصنع <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required value="<?= $f('name') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">القطاع <span class="text-danger">*</span></label>
                    <select name="sector_id" class="form-select" required>
                        <option value="">— اختر —</option>
                        <?php foreach ($sectors as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($editing ? ($factory['sector_id'] ?? '') == $s['id'] : old_is('sector_id', $s['id'])) ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">المحافظة <span class="text-danger">*</span></label>
                    <select name="governorate_id" class="form-select" required>
                        <option value="">— اختر —</option>
                        <?php foreach ($governorates as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= ($editing ? ($factory['governorate_id'] ?? '') == $g['id'] : old_is('governorate_id', $g['id'])) ? 'selected' : '' ?>><?= e($g['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">مسؤول التواصل</label>
                    <input type="text" name="contact_person" class="form-control" value="<?= $f('contact_person') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control" value="<?= $f('email') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">الهاتف</label>
                    <input type="text" name="phone" class="form-control" value="<?= $f('phone') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">المنتجات</label>
                    <textarea name="products" class="form-control" rows="3"><?= $f('products') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">أبرز التحديات</label>
                    <textarea name="main_challenges" class="form-control" rows="3"><?= $f('main_challenges') ?></textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label">مستوى استهلاك الطاقة</label>
                    <select name="energy_usage_level" class="form-select">
                        <?php foreach (['low' => 'منخفض', 'medium' => 'متوسط', 'high' => 'مرتفع'] as $val => $lbl): ?>
                            <option value="<?= $val ?>" <?= ($editing ? ($factory['energy_usage_level'] ?? 'medium') === $val : old_is('energy_usage_level', $val)) ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">الطاقة الإنتاجية</label>
                    <input type="text" name="production_capacity" class="form-control" placeholder="مثال: 5000 طن سنوياً" value="<?= $f('production_capacity') ?>">
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $editing ? '💾 حفظ التعديلات' : '➕ تسجيل المصنع' ?></button>
                <a href="<?= url('factory') ?>" class="btn btn-outline-navy">إلغاء</a>
            </div>
        </form>
    </div>
</div>
