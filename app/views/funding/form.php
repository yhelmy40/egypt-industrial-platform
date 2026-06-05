<?php
/** نموذج فرصة تمويل | Funding opportunity form */
$editing   = !empty($opportunity);
$pageTitle = $editing ? 'تعديل فرصة تمويل' : 'إضافة فرصة تمويل';
$action    = $editing ? url('funding/update/' . $opportunity['id']) : url('funding/store');
$f = fn($k, $d = '') => $editing ? e($opportunity[$k] ?? $d) : old($k, $d);
$selectedSectors = $editing
    ? array_filter(array_map('intval', explode(',', $opportunity['eligible_sectors'] ?? '')))
    : array_map('intval', (array)($_SESSION['_old']['eligible_sectors'] ?? []));
?>

<div class="page-head mb-3">
    <h2 class="section-title mb-1"><?= e($pageTitle) ?></h2>
    <p class="text-muted small mb-0">حدّد القطاعات المؤهلة لمساعدة المصانع والباحثين على إيجاد التمويل المناسب.</p>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= $action ?>" class="needs-validation" novalidate>
            <?= Csrf::field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">اسم البرنامج <span class="text-danger">*</span></label>
                    <input type="text" name="program_name" class="form-control" required value="<?= $f('program_name') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">جهة التمويل <span class="text-danger">*</span></label>
                    <input type="text" name="funding_entity" class="form-control" required value="<?= $f('funding_entity') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">الحد الأقصى للتمويل (ج.م) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" name="max_funding_amount" class="form-control" required value="<?= $f('max_funding_amount') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">الموعد النهائي للتقديم <span class="text-danger">*</span></label>
                    <input type="date" name="application_deadline" class="form-control" required value="<?= $f('application_deadline') ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">القطاعات المؤهلة</label>
                    <select name="eligible_sectors[]" class="form-select" multiple size="6">
                        <?php foreach ($sectors as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= in_array((int) $s['id'], $selectedSectors, true) ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">اضغط Ctrl (أو Cmd) لاختيار أكثر من قطاع. اترك الحقل فارغاً ليشمل كل القطاعات.</small>
                </div>

                <div class="col-12">
                    <label class="form-label">وصف البرنامج</label>
                    <textarea name="description" class="form-control" rows="3"><?= $f('description') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">بيانات التواصل</label>
                    <textarea name="contact_details" class="form-control" rows="2" placeholder="البريد، الهاتف، رابط التقديم..."><?= $f('contact_details') ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $editing ? '💾 حفظ التعديلات' : '➕ إضافة الفرصة' ?></button>
                <a href="<?= url('funding') ?>" class="btn btn-outline-navy">إلغاء</a>
            </div>
        </form>
    </div>
</div>
