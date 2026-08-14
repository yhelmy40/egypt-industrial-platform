<?php
/**
 * نموذج باقة الخدمة | Service offering form (§4.6).
 *
 * @var array<string,mixed>|null $offering
 * @var array<string,string> $types
 * @var array<string,string> $deliveryModes
 * @var array<string,string> $pricingModes
 * @var \App\Services\ServiceOfferingService $service
 */
$isEdit = $offering !== null;
$action = $isEdit ? url('/app/services/offerings/' . $offering['id']) : url('/app/services/offerings');
$value  = static fn (string $key, mixed $default = '') => old($key, $offering[$key] ?? $default);
$status = $isEdit ? (string) $offering['status'] : 'draft';
$locked = $status === 'pending_review';
$selectedGovernorates = $isEdit && !empty($offering['governorate_ids'])
    ? array_map('intval', explode(',', (string) $offering['governorate_ids']))
    : [];
?>
<a class="fs-sm text-muted-np" href="<?= e(url('/app/services/offerings')) ?>">→ كل الباقات</a>

<?php if ($isEdit): ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-1 mb-3">
        <h1 class="h5 mb-0">
            <?= e($offering['name_ar']) ?>
            <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
                <?= e($service->statusLabel($status)) ?></span>
        </h1>
        <?php if ($status === 'published'): ?>
            <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
               href="<?= e(url('/services/' . $offering['slug'])) ?>">معاينة الصفحة العامة ↗</a>
        <?php endif; ?>
    </div>

    <?php if ($status === 'rejected' && !empty($offering['moderation_note'])): ?>
        <div class="alert alert-warning">
            <strong>لم تُعتمد هذه الباقة.</strong>
            <p class="mb-0"><?= e($offering['moderation_note']) ?></p>
        </div>
    <?php elseif ($locked): ?>
        <div class="alert alert-info">الباقة قيد الاعتماد، فلا يمكن تعديلها الآن.</div>
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
                        <label class="form-label" for="name_ar">اسم الباقة <span class="required">*</span></label>
                        <input type="text" class="form-control <?= has_error('name_ar') ? 'is-invalid' : '' ?>"
                               id="name_ar" name="name_ar" required maxlength="200"
                               value="<?= e($value('name_ar')) ?>" <?= $locked ? 'disabled' : '' ?>>
                        <?php if (has_error('name_ar')): ?>
                            <div class="invalid-feedback"><?= e(error_for('name_ar')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="service_type">نوع الخدمة</label>
                            <select class="form-select" id="service_type" name="service_type"
                                <?= $locked ? 'disabled' : '' ?>>
                                <?php foreach ($types as $code => $label): ?>
                                    <option value="<?= e($code) ?>"
                                        <?= (string) $value('service_type', 'consulting') === $code ? ' selected' : '' ?>>
                                        <?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="delivery_mode">طريقة التنفيذ</label>
                            <select class="form-select" id="delivery_mode" name="delivery_mode"
                                <?= $locked ? 'disabled' : '' ?>>
                                <?php foreach ($deliveryModes as $code => $label): ?>
                                    <option value="<?= e($code) ?>"
                                        <?= (string) $value('delivery_mode', 'hybrid') === $code ? ' selected' : '' ?>>
                                        <?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
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
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="description">الوصف التفصيلي</label>
                        <textarea class="form-control" id="description" name="description"
                                  rows="5" maxlength="20000" <?= $locked ? 'disabled' : '' ?>
                        ><?= e($value('description')) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="np-card mb-3">
                <div class="np-card__header">التسعير والمدة</div>
                <div class="np-card__body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="pricing_mode">طريقة التسعير</label>
                            <select class="form-select" id="pricing_mode" name="pricing_mode"
                                <?= $locked ? 'disabled' : '' ?>>
                                <?php foreach ($pricingModes as $code => $label): ?>
                                    <option value="<?= e($code) ?>"
                                        <?= (string) $value('pricing_mode', 'quote') === $code ? ' selected' : '' ?>>
                                        <?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-6">
                            <label class="form-label fs-sm" for="price_from">من</label>
                            <input type="number" class="form-control" dir="ltr" id="price_from" name="price_from"
                                   min="0" step="0.01" value="<?= e($value('price_from')) ?>"
                                <?= $locked ? 'disabled' : '' ?>>
                        </div>
                        <div class="col-md-4 col-6">
                            <label class="form-label fs-sm" for="price_to">إلى</label>
                            <input type="number" class="form-control" dir="ltr" id="price_to" name="price_to"
                                   min="0" step="0.01" value="<?= e($value('price_to')) ?>"
                                <?= $locked ? 'disabled' : '' ?>>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="funded_by_ar">الجهة الممولة (للخدمة المجانية)</label>
                        <input type="text" class="form-control" id="funded_by_ar" name="funded_by_ar"
                               maxlength="200" value="<?= e($value('funded_by_ar')) ?>"
                            <?= $locked ? 'disabled' : '' ?>>
                        <p class="form-text">
                            إلزامية إذا كانت الخدمة مجانية: «مجاني» بلا مصدر يوحي بأن المنصة تتحمّل
                            التكلفة، وهي لا تفعل.
                        </p>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="duration_note_ar">مدة التنفيذ</label>
                        <input type="text" class="form-control" id="duration_note_ar" name="duration_note_ar"
                               maxlength="300" value="<?= e($value('duration_note_ar')) ?>"
                               placeholder="مثال: من أسبوعين إلى ثلاثة." <?= $locked ? 'disabled' : '' ?>>
                    </div>
                </div>
            </div>

            <div class="np-card mb-3">
                <div class="np-card__header">المحتوى والتغطية</div>
                <div class="np-card__body">
                    <label class="form-label" for="deliverables_ar">
                        مخرجات الخدمة <span class="required">*</span></label>
                    <textarea class="form-control" id="deliverables_ar" name="deliverables_ar"
                              rows="4" maxlength="2000" placeholder="مخرَج في كل سطر."
                        <?= $locked ? 'disabled' : '' ?>><?= e($value('deliverables_ar')) ?></textarea>

                    <label class="form-label mt-3" for="target_audience_ar">
                        الفئة المستهدفة <span class="required">*</span></label>
                    <textarea class="form-control" id="target_audience_ar" name="target_audience_ar"
                              rows="3" maxlength="1000" <?= $locked ? 'disabled' : '' ?>
                    ><?= e($value('target_audience_ar')) ?></textarea>

                    <fieldset class="mt-3">
                        <legend class="form-label">المحافظات المغطّاة</legend>
                        <p class="form-text mt-0">اترك الكل فارغاً لتغطية كل المحافظات.</p>
                        <div class="row g-1" style="max-height:220px;overflow-y:auto">
                            <?php foreach ($governorates as $governorate): ?>
                                <div class="col-md-4 col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="governorate_ids[]"
                                               value="<?= e((string) $governorate['id']) ?>"
                                               id="gov_<?= e((string) $governorate['id']) ?>"
                                            <?= in_array((int) $governorate['id'], $selectedGovernorates, true) ? ' checked' : '' ?>
                                            <?= $locked ? 'disabled' : '' ?>>
                                        <label class="form-check-label fs-sm"
                                               for="gov_<?= e((string) $governorate['id']) ?>">
                                            <?= e($governorate['name_ar']) ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
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
                              action="<?= e(url('/app/services/offerings/' . $offering['id'] . '/submit')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-primary w-100">إرسال للاعتماد</button>
                        </form>
                        <p class="fs-xs text-muted-np mb-0 mt-2">
                            لا تظهر الباقة للمشروعات قبل اعتماد فريق المنصة.
                        </p>
                    <?php elseif ($status === 'pending_review'): ?>
                        <p class="fs-sm mb-0">الباقة في طابور الاعتماد.</p>
                    <?php elseif ($status === 'published'): ?>
                        <form method="post" data-guard
                              action="<?= e(url('/app/services/offerings/' . $offering['id'] . '/archive')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-danger w-100"
                                    data-confirm="ستختفي الباقة من دليل الخدمات. متابعة؟">سحب الباقة</button>
                        </form>
                    <?php else: ?>
                        <p class="fs-sm text-muted-np mb-0">الباقة مسحوبة.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="np-card">
                <div class="np-card__header">الأداء</div>
                <div class="np-card__body">
                    <dl class="row fs-sm mb-0">
                        <dt class="col-7 fw-normal text-muted-np">عدد الطلبات</dt>
                        <dd class="col-5 numeric"><?= e(number_ar((int) $offering['request_count'])) ?></dd>
                        <dt class="col-7 fw-normal text-muted-np">مرات المشاهدة</dt>
                        <dd class="col-5 numeric"><?= e(number_ar((int) $offering['view_count'])) ?></dd>
                    </dl>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
