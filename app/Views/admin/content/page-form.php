<?php
/**
 * تحرير صفحة ثابتة | Static page editor (§4.11).
 *
 * @var array<string,mixed> $page
 * @var array<int,string> $allowedTags
 * @var bool $canPublish
 * @var \App\Services\ContentService $service
 */
$status = (string) $page['status'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">تحرير: <?= e($page['title_ar']) ?></h1>
        <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
            <?= e($service->statusLabel($status)) ?></span>
        <span class="fs-sm text-muted-np">·</span>
        <span class="numeric fs-sm" dir="ltr">/<?= e($page['slug']) ?></span>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/pages')) ?>">رجوع</a>
</div>

<?php if ($status === 'published'): ?>
    <div class="alert alert-info fs-sm" role="alert">
        هذه الصفحة منشورة. أي تعديل يحفظه هذا النموذج <strong>يعيدها إلى طابور المراجعة</strong>
        حتى يعتمدها من يملك صلاحية النشر — والزائر يبقى يرى النسخة المنشورة حتى ذلك الحين.
    </div>
<?php endif; ?>

<?php if (!empty($page['review_note_ar'])): ?>
    <div class="alert alert-warning fs-sm" role="alert">
        <strong>ملاحظة المراجعة:</strong> <?= e($page['review_note_ar']) ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form method="post" action="<?= e(url('/admin/pages/' . $page['slug'])) ?>" class="np-card">
            <?= csrf_field() ?>
            <div class="np-card__body row g-3">
                <div class="col-12">
                    <label class="form-label" for="title_ar">عنوان الصفحة <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="title_ar" name="title_ar" required
                           maxlength="200" value="<?= e($page['title_ar']) ?>">
                </div>

                <div class="col-12">
                    <label class="form-label" for="body_ar">نصّ الصفحة <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="body_ar" name="body_ar" rows="24" required
                              style="font-family:monospace;font-size:.85rem"><?= e($page['body_ar']) ?></textarea>
                    <div class="form-text fs-xs">
                        يُقبل تنسيق HTML محدود ويُنقَّى عند الحفظ.
                        المسموح: <span dir="ltr"><?= e(implode(', ', $allowedTags)) ?></span>.
                    </div>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="needs_legal_review"
                               name="needs_legal_review" value="1"
                            <?= (int) $page['needs_legal_review'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="needs_legal_review">
                            الصياغة تحتاج مراجعة قانونية
                        </label>
                    </div>
                    <div class="form-text fs-xs">
                        عند تفعيله يظهر تنويه للزائر بأن الصياغة أولية. أزله بعد اعتماد الجهة
                        القانونية المختصة.
                    </div>
                </div>
            </div>

            <div class="np-card__footer d-flex gap-2">
                <button type="submit" class="btn btn-primary">حفظ</button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/admin/pages')) ?>">إلغاء</a>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <?php if ($canPublish && $status === 'pending_review'): ?>
            <div class="np-card mb-3">
                <div class="np-card__header"><h2 class="h6 mb-0">قرار النشر</h2></div>
                <form method="post" action="<?= e(url('/admin/pages/' . $page['slug'] . '/decide')) ?>">
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
                        <button type="submit" name="decision" value="draft"
                                class="btn btn-sm btn-outline-danger flex-grow-1">إعادة للمحرّر</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="np-card">
            <div class="np-card__body fs-sm">
                <dl class="row mb-0">
                    <dt class="col-5">آخر نشر</dt>
                    <dd class="col-7">
                        <?= $page['published_at'] !== null
                            ? e(format_date((string) $page['published_at'], true)) : '—' ?>
                    </dd>
                    <dt class="col-5">آخر تعديل</dt>
                    <dd class="col-7"><?= e(format_date((string) $page['updated_at'], true)) ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>
