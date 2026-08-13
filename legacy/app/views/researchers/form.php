<?php
/** نموذج باحث/خبير | Researcher / expert form */
$editing   = !empty($profile);
$pageTitle = $editing ? 'تعديل الملف المهني' : 'إنشاء ملف مهني';
$action    = $editing ? url('researcher/update/' . $profile['id']) : url('researcher/store');
$f = fn($k, $d = '') => $editing ? e($profile[$k] ?? $d) : old($k, $d);
// النوع الافتراضي حسب الدور | default type from role
$defaultType = Auth::is('expert') ? 'expert' : 'researcher';
$lockType    = in_array(Auth::role(), ['researcher', 'expert'], true);
?>

<div class="page-head mb-3">
    <h2 class="section-title mb-1"><?= e($pageTitle) ?></h2>
    <p class="text-muted small mb-0">كلمات الخبرة دقيقة = مطابقة أفضل مع التحديات الصناعية.</p>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= $action ?>" class="needs-validation" novalidate>
            <?= Csrf::field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">الاسم <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required value="<?= $f('name') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">النوع</label>
                    <select name="profile_type" class="form-select" <?= $lockType ? 'disabled' : '' ?>>
                        <?php $curType = $editing ? ($profile['profile_type'] ?? $defaultType) : ($lockType ? $defaultType : old('profile_type', 'researcher')); ?>
                        <option value="researcher" <?= $curType === 'researcher' ? 'selected' : '' ?>>باحث / جامعي</option>
                        <option value="expert" <?= $curType === 'expert' ? 'selected' : '' ?>>خبير / استشاري</option>
                    </select>
                    <?php if ($lockType): ?><input type="hidden" name="profile_type" value="<?= e($defaultType) ?>"><?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">المحافظة</label>
                    <select name="governorate_id" class="form-select">
                        <option value="">— اختر —</option>
                        <?php foreach ($governorates as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= ($editing ? ($profile['governorate_id'] ?? '') == $g['id'] : old_is('governorate_id', $g['id'])) ? 'selected' : '' ?>><?= e($g['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">الجهة / المؤسسة</label>
                    <input type="text" name="organization" class="form-control" value="<?= $f('organization') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">التخصص <span class="text-danger">*</span></label>
                    <input type="text" name="specialization" class="form-control" required value="<?= $f('specialization') ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">كلمات الخبرة <span class="text-danger">*</span></label>
                    <input type="text" name="expertise_keywords" class="form-control" required
                           placeholder="افصل بينها بفاصلة: كفاءة الطاقة، معالجة المياه، صيانة تنبؤية" value="<?= $f('expertise_keywords') ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">المشاريع السابقة</label>
                    <textarea name="previous_projects" class="form-control" rows="3"><?= $f('previous_projects') ?></textarea>
                </div>

                <div class="col-md-6">
                    <label class="form-label">البريد الإلكتروني <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" required value="<?= $f('email') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">الهاتف</label>
                    <input type="text" name="phone" class="form-control" value="<?= $f('phone') ?>">
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $editing ? '💾 حفظ التعديلات' : '➕ إنشاء الملف' ?></button>
                <a href="<?= url('researcher') ?>" class="btn btn-outline-navy">إلغاء</a>
            </div>
        </form>
    </div>
</div>
