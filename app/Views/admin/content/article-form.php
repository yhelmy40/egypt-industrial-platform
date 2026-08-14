<?php
/**
 * تحرير مقال | Article editor (§4.11).
 *
 * @var array<string,mixed>|null $article
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,string> $allowedTags
 * @var bool $canPublish
 * @var \App\Services\ContentService $service
 */

$isEdit = $article !== null;
$status = $isEdit ? (string) $article['status'] : 'draft';
$action = $isEdit ? url('/admin/articles/' . $article['id']) : url('/admin/articles');
$value  = static fn (string $key, string $default = ''): string => e((string) ($article[$key] ?? $default));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><?= $isEdit ? 'تعديل المقال' : 'مقال جديد' ?></h1>
        <?php if ($isEdit): ?>
            <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
                <?= e($service->statusLabel($status)) ?></span>
        <?php endif; ?>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/articles')) ?>">رجوع</a>
</div>

<?php if ($isEdit && $status === 'rejected' && !empty($article['review_note_ar'])): ?>
    <div class="alert alert-warning" role="alert">
        <strong>أُعيد للتعديل:</strong> <?= e($article['review_note_ar']) ?>
    </div>
<?php endif; ?>

<?php if ($isEdit && $status === 'published'): ?>
    <div class="alert alert-info fs-sm" role="alert">
        هذه المادة منشورة. أي تعديل يحفظه هذا النموذج <strong>يعيدها إلى طابور المراجعة</strong>،
        لأن نصّاً تغيّر بعد الاعتماد نصّ لم يراجعه أحد.
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form method="post" action="<?= e($action) ?>" class="np-card">
            <?= csrf_field() ?>
            <div class="np-card__body row g-3">
                <div class="col-12">
                    <label class="form-label" for="title_ar">العنوان <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="title_ar" name="title_ar" required
                           maxlength="200" value="<?= $value('title_ar') ?>">
                </div>

                <div class="col-md-8">
                    <label class="form-label" for="category_id">التصنيف</label>
                    <select class="form-select" id="category_id" name="category_id">
                        <option value="">— اختر —</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e((string) $category['id']) ?>"
                                <?= (int) ($article['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>>
                                <?= e($category['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text fs-xs">إلزامي قبل الإرسال للمراجعة.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="reading_minutes">دقائق القراءة</label>
                    <input type="number" min="1" max="255" dir="ltr" class="form-control numeric"
                           id="reading_minutes" name="reading_minutes" value="<?= $value('reading_minutes') ?>">
                    <div class="form-text fs-xs">تُقدَّر تلقائياً إن تُركت فارغة.</div>
                </div>

                <div class="col-12">
                    <label class="form-label" for="excerpt_ar">الملخّص</label>
                    <textarea class="form-control" id="excerpt_ar" name="excerpt_ar" rows="2"
                              maxlength="500"><?= $value('excerpt_ar') ?></textarea>
                    <div class="form-text fs-xs">
                        يظهر في القوائم ونتائج البحث. يُشتقّ من أول النصّ إن تُرك فارغاً.
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label" for="body_ar">نصّ المقال <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="body_ar" name="body_ar" rows="18"
                              required style="font-family:monospace;font-size:.85rem"><?= $value('body_ar') ?></textarea>
                    <div class="form-text fs-xs">
                        يُقبل تنسيق HTML محدود، ويُنقَّى عند الحفظ فتُزال الوسوم غير المسموحة.
                        المسموح: <span dir="ltr"><?= e(implode(', ', $allowedTags)) ?></span>.
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="author_name_ar">اسم الكاتب المعروض</label>
                    <input type="text" class="form-control" id="author_name_ar" name="author_name_ar"
                           maxlength="150" value="<?= $value('author_name_ar') ?>">
                </div>

                <div class="col-md-6 d-flex align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_featured"
                               name="is_featured" value="1"
                            <?= (int) ($article['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_featured">مادة مميّزة</label>
                    </div>
                </div>
            </div>

            <div class="np-card__footer d-flex gap-2">
                <button type="submit" class="btn btn-primary">حفظ</button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/admin/articles')) ?>">إلغاء</a>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <?php if ($isEdit): ?>
            <?php if (in_array($status, ['draft', 'rejected'], true)): ?>
                <div class="np-card mb-3">
                    <div class="np-card__header"><h2 class="h6 mb-0">الإرسال للمراجعة</h2></div>
                    <div class="np-card__body">
                        <p class="fs-sm text-muted-np">
                            يلزم قبل الإرسال: ملخّص، وتصنيف، ونصّ لا يقلّ عن ٢٠٠ حرف.
                        </p>
                        <form method="post"
                              action="<?= e(url('/admin/articles/' . $article['id'] . '/submit')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-primary w-100">إرسال للمراجعة</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($canPublish) && $status === 'pending_review'): ?>
                <div class="np-card mb-3">
                    <div class="np-card__header"><h2 class="h6 mb-0">قرار النشر</h2></div>
                    <form method="post"
                          action="<?= e(url('/admin/articles/' . $article['id'] . '/decide')) ?>">
                        <?= csrf_field() ?>
                        <div class="np-card__body">
                            <label class="form-label fs-sm" for="review_note_ar">
                                ملاحظة <span class="text-muted-np">(إلزامية عند الإعادة)</span>
                            </label>
                            <textarea class="form-control form-control-sm" id="review_note_ar"
                                      name="review_note_ar" rows="3" maxlength="1000"></textarea>
                        </div>
                        <div class="np-card__footer d-flex gap-2">
                            <button type="submit" name="decision" value="published"
                                    class="btn btn-sm btn-primary flex-grow-1">نشر</button>
                            <button type="submit" name="decision" value="rejected"
                                    class="btn btn-sm btn-outline-danger flex-grow-1">إعادة للتعديل</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <div class="np-card">
                <div class="np-card__body fs-sm">
                    <dl class="row mb-0">
                        <dt class="col-5">المُعرّف</dt>
                        <dd class="col-7" dir="ltr"><?= $value('slug') ?></dd>
                        <dt class="col-5">آخر تعديل</dt>
                        <dd class="col-7"><?= e(format_date((string) $article['updated_at'], true)) ?></dd>
                        <dt class="col-5">المشاهدات</dt>
                        <dd class="col-7 numeric"><?= e(number_ar((int) $article['view_count'])) ?></dd>
                    </dl>
                </div>
                <?php if (!empty($canPublish) && $status !== 'archived'): ?>
                    <div class="np-card__footer">
                        <form method="post"
                              action="<?= e(url('/admin/articles/' . $article['id'] . '/archive')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger w-100"
                                    data-confirm="أرشفة المقال؟ لن يظهر للعامة بعدها.">أرشفة</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="np-card">
                <div class="np-card__body fs-sm text-muted-np">
                    تُحفظ المادة <strong>كمسودة</strong>. بعد اكتمالها أرسلها للمراجعة،
                    ويقرّر النشر من يملك صلاحيته.
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
