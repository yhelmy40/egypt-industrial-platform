<?php
/**
 * نموذج الصنف | Listing create/edit form (§4.4).
 * @var array<string,mixed>|null $listing
 */
$isEdit  = $listing !== null;
$action  = $isEdit ? url('/app/listings/' . $listing['id']) : url('/app/listings');
$value   = static fn (string $key, mixed $default = '') => $listing[$key] ?? $default;
?>
<div class="row g-3">
    <div class="col-lg-8">
        <form method="post" action="<?= e($action) ?>" novalidate data-guard>
            <div class="np-card">
                <div class="np-card__header">
                    <?= $isEdit ? 'تعديل الصنف' : 'صنف جديد' ?>
                    <?php if ($isEdit): ?>
                        <span class="np-badge <?= e($service->statusBadgeClass((string) $listing['status'])) ?>">
                            <?= e($service->statusLabel((string) $listing['status'])) ?></span>
                    <?php endif; ?>
                </div>
                <div class="np-card__body">
                    <?= csrf_field() ?>

                    <?php if ($isEdit && $listing['status'] === 'rejected' && !empty($listing['moderation_note'])): ?>
                        <div class="alert alert-danger fs-sm" role="alert">
                            <strong>سبب رفض النشر:</strong> <?= e($listing['moderation_note']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="name_ar">اسم الصنف<span class="required">*</span></label>
                            <input type="text" class="form-control<?= has_error('name_ar') ? ' is-invalid' : '' ?>"
                                   id="name_ar" name="name_ar" required maxlength="200"
                                   value="<?= e(old('name_ar', $value('name_ar'))) ?>">
                            <?php if (has_error('name_ar')): ?><div class="invalid-feedback"><?= e(error_for('name_ar')) ?></div><?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="listing_type">النوع<span class="required">*</span></label>
                            <select class="form-select" id="listing_type" name="listing_type" required>
                                <option value="product"<?= $value('listing_type') === 'product' ? ' selected' : '' ?>>منتج</option>
                                <option value="service"<?= $value('listing_type') === 'service' ? ' selected' : '' ?>>خدمة</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="category_id">التصنيف</label>
                            <select class="form-select" id="category_id" name="category_id">
                                <option value=""><?= __e('common.select') ?></option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e((string) $category['id']) ?>"
                                        <?= (int) $value('category_id') === (int) $category['id'] ? ' selected' : '' ?>>
                                        <?= e($category['name_ar']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="sku">كود الصنف (SKU)</label>
                            <input type="text" class="form-control" id="sku" name="sku" dir="ltr" maxlength="60"
                                   value="<?= e(old('sku', $value('sku'))) ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="short_description">وصف مختصر<span class="required">*</span></label>
                            <textarea class="form-control<?= has_error('short_description') ? ' is-invalid' : '' ?>"
                                      id="short_description" name="short_description" rows="2" required
                                      minlength="10" maxlength="500"><?= e(old('short_description', $value('short_description'))) ?></textarea>
                            <?php if (has_error('short_description')): ?><div class="invalid-feedback"><?= e(error_for('short_description')) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="description">الوصف التفصيلي</label>
                            <textarea class="form-control" id="description" name="description"
                                      rows="6" maxlength="20000"><?= e(old('description', $value('description'))) ?></textarea>
                        </div>

                        <div class="col-12"><hr class="my-1"><h3 class="h6 mb-0">التسعير</h3></div>

                        <div class="col-md-4">
                            <label class="form-label" for="pricing_mode">طريقة التسعير<span class="required">*</span></label>
                            <select class="form-select" id="pricing_mode" name="pricing_mode" required>
                                <option value="fixed"<?= $value('pricing_mode', 'fixed') === 'fixed' ? ' selected' : '' ?>>سعر معروض</option>
                                <option value="quote"<?= $value('pricing_mode') === 'quote' ? ' selected' : '' ?>>اطلب عرض سعر</option>
                            </select>
                            <div class="form-text">أصناف «عرض السعر» لا تُضاف إلى السلة.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="price">السعر (ج.م)</label>
                            <input type="number" class="form-control<?= has_error('price') ? ' is-invalid' : '' ?>"
                                   id="price" name="price" min="0" step="0.01" dir="ltr"
                                   value="<?= e(old('price', $value('price'))) ?>">
                            <?php if (has_error('price')): ?><div class="invalid-feedback"><?= e(error_for('price')) ?></div><?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="vat_rate">نسبة الضريبة %</label>
                            <input type="number" class="form-control" id="vat_rate" name="vat_rate"
                                   min="0" max="100" step="0.01" dir="ltr"
                                   value="<?= e(old('vat_rate', $value('vat_rate', $defaultVat))) ?>">
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="vat_included" name="vat_included"
                                       value="1"<?= (int) $value('vat_included', 0) === 1 ? ' checked' : '' ?>>
                                <label class="form-check-label" for="vat_included">السعر شامل ضريبة القيمة المضافة</label>
                            </div>
                        </div>

                        <div class="col-12"><hr class="my-1"><h3 class="h6 mb-0">المخزون والتسليم</h3></div>

                        <div class="col-md-4">
                            <label class="form-label" for="unit_of_measure">وحدة القياس</label>
                            <input type="text" class="form-control" id="unit_of_measure" name="unit_of_measure"
                                   maxlength="40" placeholder="قطعة، كيلوجرام، ساعة…"
                                   value="<?= e(old('unit_of_measure', $value('unit_of_measure'))) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="min_order_quantity">الحد الأدنى للطلب</label>
                            <input type="number" class="form-control" id="min_order_quantity" name="min_order_quantity"
                                   min="0.001" step="0.001" dir="ltr"
                                   value="<?= e(old('min_order_quantity', $value('min_order_quantity', '1'))) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="available_quantity">الكمية المتاحة</label>
                            <input type="number" class="form-control" id="available_quantity" name="available_quantity"
                                   min="0" step="0.001" dir="ltr"
                                   value="<?= e(old('available_quantity', $value('available_quantity'))) ?>">
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="track_inventory" name="track_inventory"
                                       value="1"<?= (int) $value('track_inventory', 0) === 1 ? ' checked' : '' ?>>
                                <label class="form-check-label" for="track_inventory">
                                    تتبّع المخزون (تُخصم الكمية تلقائياً عند الطلب ويُمنع الطلب عند النفاد)
                                </label>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="lead_time_days">مدة التنفيذ (أيام)</label>
                            <input type="number" class="form-control" id="lead_time_days" name="lead_time_days"
                                   min="0" max="3650" dir="ltr"
                                   value="<?= e(old('lead_time_days', $value('lead_time_days'))) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="delivery_fee">رسوم التوصيل (ج.م)</label>
                            <input type="number" class="form-control" id="delivery_fee" name="delivery_fee"
                                   min="0" step="0.01" dir="ltr"
                                   value="<?= e(old('delivery_fee', $value('delivery_fee'))) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="delivery_area">نطاق التوصيل</label>
                            <input type="text" class="form-control" id="delivery_area" name="delivery_area"
                                   maxlength="300" value="<?= e(old('delivery_area', $value('delivery_area'))) ?>">
                        </div>
                    </div>
                </div>
                <div class="np-card__footer d-flex justify-content-between">
                    <a class="btn btn-link text-muted-np" href="<?= e(url('/app/listings')) ?>">رجوع</a>
                    <button type="submit" class="btn btn-primary"><?= __e('common.save') ?></button>
                </div>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <?php if ($isEdit): ?>
            <div class="np-card mb-3">
                <div class="np-card__header">النشر</div>
                <div class="np-card__body">
                    <?php if (in_array($listing['status'], ['draft', 'rejected', 'archived'], true)): ?>
                        <form method="post" action="<?= e(url('/app/listings/' . $listing['id'] . '/submit')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-primary w-100">إرسال للنشر</button>
                        </form>
                        <p class="form-text mb-0 mt-1">يتطلّب توثيق المنشأة، وقد يمرّ بمراجعة الإدارة.</p>
                    <?php elseif ($listing['status'] === 'pending_review'): ?>
                        <p class="text-muted-np fs-sm mb-0">الصنف بانتظار مراجعة فريق المنصة.</p>
                    <?php elseif ($listing['status'] === 'published'): ?>
                        <a class="btn btn-outline-primary w-100 mb-2" target="_blank" rel="noopener"
                           href="<?= e(url('/marketplace/' . $listing['slug'])) ?>">معاينة في السوق</a>
                        <form method="post" action="<?= e(url('/app/listings/' . $listing['id'] . '/archive')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-danger w-100 btn-sm"
                                    data-confirm="سيختفي الصنف من السوق. هل تريد المتابعة؟">إخفاء من السوق</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="np-card">
                <div class="np-card__header">الصور</div>
                <div class="np-card__body">
                    <?php if ($images !== []): ?>
                        <div class="row g-2 mb-3">
                            <?php foreach ($images as $image): ?>
                                <div class="col-4">
                                    <img src="<?= e(url('/files/' . $image['media_id'])) ?>" alt=""
                                         class="w-100" loading="lazy"
                                         style="aspect-ratio:1;object-fit:cover;border-radius:var(--np-radius-sm)">
                                    <form method="post"
                                          action="<?= e(url('/app/listings/' . $listing['id'] . '/images/' . $image['id'] . '/delete')) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-link text-danger p-0 fs-xs">حذف</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('/app/listings/' . $listing['id'] . '/images')) ?>"
                          enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <?php if (has_error('image') || has_error('file')): ?>
                            <div class="alert alert-danger fs-sm"><?= e(error_for('image') ?? error_for('file')) ?></div>
                        <?php endif; ?>
                        <input type="file" class="form-control mb-2" name="image" accept=".jpg,.jpeg,.png,.webp" required>
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100">رفع صورة</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info fs-sm">
                احفظ الصنف أولاً، ثم يمكنك رفع صوره وإرساله للنشر.
            </div>
        <?php endif; ?>
    </div>
</div>
