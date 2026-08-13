<?php
/** نموذج مورد معرفي | Knowledge resource form */
$pageTitle = 'إضافة مورد معرفي';
?>

<div class="page-head mb-3">
    <h2 class="section-title mb-1">إضافة مورد معرفي</h2>
    <p class="text-muted small mb-0">شارك بحثاً أو دراسة حالة أو دليلاً إرشادياً مع مجتمع المنصة.</p>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= url('knowledge/store') ?>" class="needs-validation" enctype="multipart/form-data" novalidate>
            <?= Csrf::field() ?>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">العنوان <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required value="<?= old('title') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">التصنيف <span class="text-danger">*</span></label>
                    <select name="category" class="form-select" required>
                        <option value="">— اختر —</option>
                        <?php foreach (KnowledgeResource::CATEGORIES as $cat): ?>
                            <option value="<?= $cat ?>" <?= old_is('category', $cat) ? 'selected' : '' ?>><?= e(knowledge_category_label($cat)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">القطاع</label>
                    <select name="sector_id" class="form-select">
                        <option value="">— عام —</option>
                        <?php foreach ($sectors as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= old_is('sector_id', $s['id']) ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">رابط خارجي (اختياري)</label>
                    <input type="url" name="link" class="form-control" placeholder="https://..." value="<?= old('link') ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">الوصف</label>
                    <textarea name="description" class="form-control" rows="4"><?= old('description') ?></textarea>
                </div>

                <div class="col-12">
                    <label class="form-label">رفع ملف (اختياري)</label>
                    <input type="file" name="file" class="form-control">
                    <small class="text-muted">الصيغ المسموحة: PDF, Word, Excel, PowerPoint, صور, ZIP — بحد أقصى 10 ميجابايت.</small>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">➕ إضافة المورد</button>
                <a href="<?= url('knowledge') ?>" class="btn btn-outline-navy">إلغاء</a>
            </div>
        </form>
    </div>
</div>
