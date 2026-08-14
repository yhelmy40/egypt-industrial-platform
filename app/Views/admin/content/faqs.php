<?php
/**
 * الأسئلة الشائعة — الإدارة | FAQ administration (§4.11).
 *
 * @var array<int,array<string,mixed>> $faqs
 * @var array<int,string> $sections
 * @var array<string,string> $filters
 * @var bool $canPublish
 * @var \App\Services\ContentService $service
 */

$tabs = ['' => 'الكل', 'pending_review' => 'بانتظار النشر', 'draft' => 'مسودة',
         'published' => 'منشور', 'rejected' => 'مُعاد', 'archived' => 'مؤرشف'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">الأسئلة الشائعة</h1>
        <p class="fs-sm text-muted-np mb-0">إجابات قصيرة تُنشر بعد المراجعة.</p>
    </div>
    <div class="d-flex flex-wrap gap-1">
        <?php foreach ($tabs as $value => $label): ?>
            <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= e(url('/admin/faqs') . ($value === '' ? '' : '?status=' . $value)) ?>">
                <?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="np-card mb-3">
    <div class="np-card__header"><h2 class="h6 mb-0">سؤال جديد</h2></div>
    <form method="post" action="<?= e(url('/admin/faqs')) ?>">
        <?= csrf_field() ?>
        <div class="np-card__body row g-2">
            <div class="col-md-3">
                <label class="form-label fs-sm" for="section">القسم</label>
                <select class="form-select form-select-sm" id="section" name="section">
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= e($section) ?>"><?= e($service->sectionLabel($section)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-7">
                <label class="form-label fs-sm" for="question_ar">السؤال <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm" id="question_ar"
                       name="question_ar" required maxlength="300">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-sm" for="sort_order">الترتيب</label>
                <input type="number" min="0" dir="ltr" class="form-control form-control-sm numeric"
                       id="sort_order" name="sort_order" value="0">
            </div>
            <div class="col-12">
                <label class="form-label fs-sm" for="answer_ar">الإجابة <span class="text-danger">*</span></label>
                <textarea class="form-control form-control-sm" id="answer_ar" name="answer_ar"
                          rows="3" required></textarea>
            </div>
        </div>
        <div class="np-card__footer">
            <button type="submit" class="btn btn-sm btn-primary">إضافة كمسودة</button>
        </div>
    </form>
</div>

<?php if ($faqs === []): ?>
    <div class="np-card">
        <div class="np-card__body">
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">❓</div>
                <p class="mb-0">لا أسئلة مطابقة.</p>
            </div>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($faqs as $faq): ?>
        <?php $status = (string) $faq['status']; ?>
        <div class="np-card mb-2">
            <div class="np-card__body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <span class="np-badge np-badge--info">
                            <?= e($service->sectionLabel((string) $faq['section'])) ?></span>
                        <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
                            <?= e($service->statusLabel($status)) ?></span>
                    </div>
                    <div class="d-flex flex-wrap gap-1">
                        <?php if (in_array($status, ['draft', 'rejected'], true)): ?>
                            <form method="post"
                                  action="<?= e(url('/admin/faqs/' . $faq['id'] . '/submit')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-primary">إرسال للمراجعة</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canPublish && $status === 'pending_review'): ?>
                            <form method="post"
                                  action="<?= e(url('/admin/faqs/' . $faq['id'] . '/decide')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="decision" value="published">
                                <button type="submit" class="btn btn-sm btn-primary">نشر</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canPublish && $status !== 'archived'): ?>
                            <form method="post"
                                  action="<?= e(url('/admin/faqs/' . $faq['id'] . '/archive')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        data-confirm="أرشفة هذا السؤال؟">أرشفة</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="post" action="<?= e(url('/admin/faqs/' . $faq['id'])) ?>" class="row g-2">
                    <?= csrf_field() ?>
                    <div class="col-md-3">
                        <label class="visually-hidden" for="section_<?= e((string) $faq['id']) ?>">القسم</label>
                        <select class="form-select form-select-sm"
                                id="section_<?= e((string) $faq['id']) ?>" name="section">
                            <?php foreach ($sections as $section): ?>
                                <option value="<?= e($section) ?>"
                                    <?= (string) $faq['section'] === $section ? 'selected' : '' ?>>
                                    <?= e($service->sectionLabel($section)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <label class="visually-hidden" for="q_<?= e((string) $faq['id']) ?>">السؤال</label>
                        <input type="text" class="form-control form-control-sm"
                               id="q_<?= e((string) $faq['id']) ?>" name="question_ar"
                               value="<?= e($faq['question_ar']) ?>" maxlength="300" required>
                    </div>
                    <div class="col-md-2">
                        <label class="visually-hidden" for="o_<?= e((string) $faq['id']) ?>">الترتيب</label>
                        <input type="number" min="0" dir="ltr" class="form-control form-control-sm numeric"
                               id="o_<?= e((string) $faq['id']) ?>" name="sort_order"
                               value="<?= e((string) $faq['sort_order']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="visually-hidden" for="a_<?= e((string) $faq['id']) ?>">الإجابة</label>
                        <textarea class="form-control form-control-sm"
                                  id="a_<?= e((string) $faq['id']) ?>" name="answer_ar"
                                  rows="3" required><?= e($faq['answer_ar']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-sm btn-outline-primary">حفظ</button>
                        <?php if (!empty($faq['review_note_ar'])): ?>
                            <span class="fs-xs text-danger ms-2"><?= e($faq['review_note_ar']) ?></span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
