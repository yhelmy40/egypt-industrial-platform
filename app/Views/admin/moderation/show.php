<?php
/**
 * مراجعة إعلان | Listing moderation decision (§3.1, §4.4).
 *
 * @var array<string,mixed> $listing
 * @var array<string,mixed>|null $organization
 * @var array<int,array<string,mixed>> $images
 * @var \App\Services\ListingService $service
 */

$status     = (string) $listing['status'];
$canDecide  = $status === 'pending_review';
?>
<a class="fs-sm text-muted-np" href="<?= e(url('/admin/moderation')) ?>">→ قائمة المراجعة</a>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-1 mb-3">
    <h1 class="h5 mb-0">
        <?= e($listing['name_ar']) ?>
        <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
            <?= e($service->statusLabel($status)) ?></span>
    </h1>
    <?php if ($status === 'published'): ?>
        <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
           href="<?= e(url('/marketplace/' . $listing['slug'])) ?>">معاينة الصفحة العامة ↗</a>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="np-card mb-3">
            <div class="np-card__header">محتوى الإعلان</div>
            <div class="np-card__body">
                <table class="np-table">
                    <tbody>
                        <tr><th>النوع</th>
                            <td><?= $listing['listing_type'] === 'service' ? 'خدمة' : 'منتج' ?></td></tr>
                        <tr><th>الرابط</th>
                            <td dir="ltr" class="fs-sm"><?= e('/marketplace/' . $listing['slug']) ?></td></tr>
                        <?php if (!empty($listing['sku'])): ?>
                            <tr><th>كود الصنف</th><td dir="ltr" class="fs-sm"><?= e($listing['sku']) ?></td></tr>
                        <?php endif; ?>
                        <tr><th>التسعير</th>
                            <td>
                                <?= $listing['pricing_mode'] === 'quote'
                                    ? 'عرض سعر عند الطلب'
                                    : e(money((float) $listing['price'], (string) $listing['currency_code'])) ?>
                                <?php if ($listing['pricing_mode'] !== 'quote'): ?>
                                    <span class="fs-xs text-muted-np">
                                        (<?= (int) $listing['vat_included'] === 1 ? 'شامل الضريبة' : 'غير شامل' ?>)
                                    </span>
                                <?php endif; ?>
                            </td></tr>
                        <?php if ((int) $listing['track_inventory'] === 1): ?>
                            <tr><th>المخزون</th>
                                <td class="numeric"><?= e(number_ar((float) $listing['available_quantity'], 0)) ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($listing['delivery_area'])): ?>
                            <tr><th>نطاق التوصيل</th><td><?= e($listing['delivery_area']) ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if (!empty($listing['short_description'])): ?>
                    <h2 class="h6 mt-3">الوصف المختصر</h2>
                    <p class="fs-sm"><?= e($listing['short_description']) ?></p>
                <?php endif; ?>

                <?php if (!empty($listing['description'])): ?>
                    <h2 class="h6 mt-3">الوصف التفصيلي</h2>
                    <p class="fs-sm mb-0" style="white-space:pre-line"><?= e($listing['description']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($images !== []): ?>
            <div class="np-card mb-3">
                <div class="np-card__header">الصور</div>
                <div class="np-card__body d-flex flex-wrap gap-2">
                    <?php foreach ($images as $image): ?>
                        <img src="<?= e(url('/files/' . $image['media_id'])) ?>"
                             alt="<?= e((string) ($image['alt_text'] ?? $listing['name_ar'])) ?>" loading="lazy"
                             style="width:120px;height:120px;object-fit:cover;border-radius:var(--np-radius-sm);border:1px solid var(--np-line)">
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($listing['moderation_note'])): ?>
            <div class="np-card">
                <div class="np-card__header">آخر ملاحظة مراجعة</div>
                <div class="np-card__body">
                    <p class="fs-sm mb-0" style="white-space:pre-line"><?= e($listing['moderation_note']) ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="np-card mb-3">
            <div class="np-card__header">القرار</div>
            <div class="np-card__body">
                <?php if (!$canDecide): ?>
                    <p class="fs-sm text-muted-np mb-0">
                        هذا الإعلان ليس في حالة «بانتظار المراجعة»، فلا قرار مطلوب الآن.
                    </p>
                <?php else: ?>
                    <form method="post" action="<?= e(url('/admin/moderation/' . $listing['id'] . '/decide')) ?>"
                          data-guard>
                        <?= csrf_field() ?>

                        <label class="form-label" for="note">سبب القرار</label>
                        <textarea class="form-control <?= has_error('note') ? 'is-invalid' : '' ?>"
                                  id="note" name="note" rows="4" maxlength="1000"
                        ><?= e(old('note')) ?></textarea>
                        <?php if (has_error('note')): ?>
                            <div class="invalid-feedback"><?= e(error_for('note')) ?></div>
                        <?php endif; ?>
                        <p class="form-text">
                            السبب إلزامي عند الرفض ويصل إلى صاحب المنشأة. الرفض بلا سبب يترك المشروع
                            بلا طريق للتصحيح.
                        </p>

                        <div class="d-flex gap-2">
                            <button type="submit" name="decision" value="approve" class="btn btn-primary flex-grow-1">
                                اعتماد النشر</button>
                            <button type="submit" name="decision" value="reject" class="btn btn-outline-danger flex-grow-1">
                                رفض</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($organization !== null): ?>
            <div class="np-card">
                <div class="np-card__header">المنشأة البائعة</div>
                <div class="np-card__body">
                    <dl class="row fs-sm mb-0">
                        <dt class="col-5 fw-normal text-muted-np">الاسم</dt>
                        <dd class="col-7"><?= e($organization['trading_name'] ?: $organization['legal_name']) ?></dd>

                        <dt class="col-5 fw-normal text-muted-np">حالة التوثيق</dt>
                        <dd class="col-7">
                            <span class="np-badge <?= $organization['status'] === 'verified'
                                ? 'np-badge--success' : 'np-badge--pending' ?>">
                                <?= e($organization['status'] === 'verified' ? 'موثّقة' : 'غير موثّقة') ?></span>
                        </dd>

                        <?php if (!empty($organization['governorate_name'])): ?>
                            <dt class="col-5 fw-normal text-muted-np">المحافظة</dt>
                            <dd class="col-7"><?= e($organization['governorate_name']) ?></dd>
                        <?php endif; ?>

                        <?php if (!empty($organization['sector_name'])): ?>
                            <dt class="col-5 fw-normal text-muted-np">القطاع</dt>
                            <dd class="col-7"><?= e($organization['sector_name']) ?></dd>
                        <?php endif; ?>
                    </dl>

                    <a class="btn btn-sm btn-outline-primary mt-3"
                       href="<?= e(url('/admin/verifications/' . $organization['id'])) ?>">ملف التوثيق</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
