<?php
/**
 * نموذج المنتج التمويلي | Financing product form (§4.5).
 *
 * @var array<string,mixed>|null $product
 * @var array<string,string> $types
 * @var \App\Services\FinancingProductService $service
 */
$isEdit  = $product !== null;
$action  = $isEdit ? url('/app/finance/products/' . $product['id']) : url('/app/finance/products');
$value   = static fn (string $key, mixed $default = '') => old($key, $product[$key] ?? $default);
$status  = $isEdit ? (string) $product['status'] : 'draft';
$locked  = $status === 'pending_review';
?>
<a class="fs-sm text-muted-np" href="<?= e(url('/app/finance/products')) ?>">→ كل المنتجات</a>

<?php if ($isEdit): ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-1 mb-3">
        <h1 class="h5 mb-0">
            <?= e($product['name_ar']) ?>
            <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
                <?= e($service->statusLabel($status)) ?></span>
        </h1>
        <?php if ($status === 'published'): ?>
            <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
               href="<?= e(url('/financing/' . $product['slug'])) ?>">معاينة الصفحة العامة ↗</a>
        <?php endif; ?>
    </div>

    <?php if ($status === 'rejected' && !empty($product['moderation_note'])): ?>
        <div class="alert alert-warning">
            <strong>لم يُعتمد هذا المنتج.</strong>
            <p class="mb-0"><?= e($product['moderation_note']) ?></p>
            <p class="fs-sm mb-0 mt-2">عدّل ما يلزم ثم أعد الإرسال للاعتماد.</p>
        </div>
    <?php elseif ($locked): ?>
        <div class="alert alert-info">
            المنتج قيد الاعتماد لدى فريق المنصة، فلا يمكن تعديله الآن.
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form method="post" action="<?= e($action) ?>" data-guard>
            <?= csrf_field() ?>

            <div class="np-card mb-3">
                <div class="np-card__header">التعريف</div>
                <div class="np-card__body">
                    <div class="mb-3">
                        <label class="form-label" for="name_ar">اسم المنتج <span class="required">*</span></label>
                        <input type="text" class="form-control <?= has_error('name_ar') ? 'is-invalid' : '' ?>"
                               id="name_ar" name="name_ar" required maxlength="200"
                               value="<?= e($value('name_ar')) ?>" <?= $locked ? 'disabled' : '' ?>>
                        <?php if (has_error('name_ar')): ?>
                            <div class="invalid-feedback"><?= e(error_for('name_ar')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="financing_type">نوع التمويل</label>
                            <select class="form-select" id="financing_type" name="financing_type"
                                <?= $locked ? 'disabled' : '' ?>>
                                <?php foreach ($types as $code => $label): ?>
                                    <option value="<?= e($code) ?>"
                                        <?= (string) $value('financing_type', 'working_capital') === $code ? ' selected' : '' ?>>
                                        <?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="category_id">التصنيف</label>
                            <select class="form-select" id="category_id" name="category_id"
                                <?= $locked ? 'disabled' : '' ?>>
                                <option value="">بلا تصنيف</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e((string) $category['id']) ?>"
                                        <?= (int) $value('category_id', 0) === (int) $category['id'] ? ' selected' : '' ?>>
                                        <?= e($category['name_ar']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="short_description">
                            الوصف المختصر <span class="required">*</span></label>
                        <textarea class="form-control" id="short_description" name="short_description"
                                  rows="2" maxlength="500" <?= $locked ? 'disabled' : '' ?>
                        ><?= e($value('short_description')) ?></textarea>
                        <p class="form-text">سطر أو سطران يظهران في بطاقة المنتج.</p>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="description">الوصف التفصيلي</label>
                        <textarea class="form-control" id="description" name="description"
                                  rows="6" maxlength="20000" <?= $locked ? 'disabled' : '' ?>
                        ><?= e($value('description')) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="np-card mb-3">
                <div class="np-card__header">الشريحة والمدة والتكلفة</div>
                <div class="np-card__body">
                    <div class="row g-3">
                        <div class="col-md-3 col-6">
                            <label class="form-label fs-sm" for="min_amount">أقل مبلغ</label>
                            <input type="number" class="form-control" dir="ltr" id="min_amount" name="min_amount"
                                   min="0" step="1000" value="<?= e($value('min_amount')) ?>"
                                <?= $locked ? 'disabled' : '' ?>>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fs-sm" for="max_amount">أعلى مبلغ</label>
                            <input type="number" class="form-control" dir="ltr" id="max_amount" name="max_amount"
                                   min="0" step="1000" value="<?= e($value('max_amount')) ?>"
                                <?= $locked ? 'disabled' : '' ?>>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fs-sm" for="min_tenor_months">أقل مدة (شهور)</label>
                            <input type="number" class="form-control" dir="ltr" id="min_tenor_months"
                                   name="min_tenor_months" min="1" max="360"
                                   value="<?= e($value('min_tenor_months')) ?>" <?= $locked ? 'disabled' : '' ?>>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fs-sm" for="max_tenor_months">أطول مدة (شهور)</label>
                            <input type="number" class="form-control" dir="ltr" id="max_tenor_months"
                                   name="max_tenor_months" min="1" max="360"
                                   value="<?= e($value('max_tenor_months')) ?>" <?= $locked ? 'disabled' : '' ?>>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="rate_note_ar">
                            بيان التكلفة أو العائد <span class="required">*</span></label>
                        <textarea class="form-control" id="rate_note_ar" name="rate_note_ar"
                                  rows="2" maxlength="500" <?= $locked ? 'disabled' : '' ?>
                        ><?= e($value('rate_note_ar')) ?></textarea>
                        <p class="form-text">
                            تُعرض كما تكتبها. المنصة لا تحسب أقساطاً ولا تُحوّل النسب، فاكتب ما
                            يفهمه صاحب المشروع دون لبس.
                        </p>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="fees_note_ar">المصروفات والرسوم</label>
                        <textarea class="form-control" id="fees_note_ar" name="fees_note_ar"
                                  rows="2" maxlength="500" <?= $locked ? 'disabled' : '' ?>
                        ><?= e($value('fees_note_ar')) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="np-card mb-3">
                <div class="np-card__header">الأهلية والمستندات</div>
                <div class="np-card__body">
                    <label class="form-label" for="eligibility_summary_ar">
                        شروط الأهلية <span class="required">*</span></label>
                    <textarea class="form-control" id="eligibility_summary_ar" name="eligibility_summary_ar"
                              rows="4" maxlength="2000" <?= $locked ? 'disabled' : '' ?>
                              placeholder="شرط في كل سطر."
                    ><?= e($value('eligibility_summary_ar')) ?></textarea>

                    <label class="form-label mt-3" for="required_documents_ar">
                        المستندات المطلوبة <span class="required">*</span></label>
                    <textarea class="form-control" id="required_documents_ar" name="required_documents_ar"
                              rows="4" maxlength="2000" <?= $locked ? 'disabled' : '' ?>
                              placeholder="مستند في كل سطر."
                    ><?= e($value('required_documents_ar')) ?></textarea>

                    <div class="row g-3 mt-1">
                        <div class="col-md-4 col-6">
                            <label class="form-label fs-sm" for="min_years_in_business">أقل مدة نشاط (سنوات)</label>
                            <input type="number" class="form-control form-control-sm" dir="ltr"
                                   id="min_years_in_business" name="min_years_in_business" min="0" max="100"
                                   value="<?= e($value('min_years_in_business')) ?>" <?= $locked ? 'disabled' : '' ?>>
                        </div>
                        <div class="col-md-4 col-6">
                            <label class="form-label fs-sm" for="min_annual_revenue">أقل إيراد سنوي</label>
                            <input type="number" class="form-control form-control-sm" dir="ltr"
                                   id="min_annual_revenue" name="min_annual_revenue" min="0" step="1000"
                                   value="<?= e($value('min_annual_revenue')) ?>" <?= $locked ? 'disabled' : '' ?>>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1"
                                       id="requires_formal_registration" name="requires_formal_registration"
                                    <?= (int) $value('requires_formal_registration', 0) === 1 ? ' checked' : '' ?>
                                    <?= $locked ? 'disabled' : '' ?>>
                                <label class="form-check-label fs-sm" for="requires_formal_registration">
                                    يشترط تقنين النشاط</label>
                            </div>
                        </div>
                    </div>

                    <p class="form-text mt-3">
                        هذه القيم تُستخدم في ترتيب الاقتراحات وتنبيه المشروع لما ينقصه. لا تحجب
                        المنتج عن أحد ولا تنتج قراراً.
                    </p>
                </div>
            </div>

            <?php if (!$locked): ?>
                <button type="submit" class="btn btn-primary">
                    <?= $isEdit ? 'حفظ التعديلات' : 'حفظ كمسودة' ?></button>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($isEdit): ?>
        <div class="col-lg-4">
            <div class="np-card mb-3">
                <div class="np-card__header">الاعتماد والنشر</div>
                <div class="np-card__body">
                    <?php if (in_array($status, ['draft', 'rejected'], true)): ?>
                        <form method="post" data-guard
                              action="<?= e(url('/app/finance/products/' . $product['id'] . '/submit')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-primary w-100">إرسال للاعتماد</button>
                        </form>
                        <p class="fs-xs text-muted-np mb-0 mt-2">
                            يراجع فريق المنصة المنتج قبل ظهوره للمشروعات.
                        </p>
                    <?php elseif ($status === 'pending_review'): ?>
                        <p class="fs-sm mb-0">المنتج في طابور الاعتماد.</p>
                    <?php elseif ($status === 'published'): ?>
                        <form method="post" data-guard
                              action="<?= e(url('/app/finance/products/' . $product['id'] . '/archive')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-danger w-100"
                                    data-confirm="سيختفي المنتج من دليل التمويل. متابعة؟">
                                سحب المنتج</button>
                        </form>
                    <?php else: ?>
                        <p class="fs-sm text-muted-np mb-0">المنتج مسحوب ولا يظهر للمشروعات.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="np-card">
                <div class="np-card__header">الأداء</div>
                <div class="np-card__body">
                    <dl class="row fs-sm mb-0">
                        <dt class="col-7 fw-normal text-muted-np">عدد الطلبات</dt>
                        <dd class="col-5 numeric"><?= e(number_ar((int) $product['application_count'])) ?></dd>
                        <dt class="col-7 fw-normal text-muted-np">مرات المشاهدة</dt>
                        <dd class="col-5 numeric"><?= e(number_ar((int) $product['view_count'])) ?></dd>
                    </dl>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
