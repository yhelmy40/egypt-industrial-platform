<?php
/**
 * صفحة الصنف | Listing detail (§4.4).
 *
 * @var array<string,mixed> $listing
 * @var array<int,array<string,mixed>> $images
 * @var array<int,array<string,mixed>> $related
 * @var array<int,array<string,mixed>> $reviews
 */

$isQuote   = $listing['pricing_mode'] === 'quote';
$inStock   = (int) $listing['track_inventory'] === 0
    || (float) ($listing['available_quantity'] ?? 0) > 0;
$sellerName = $listing['trading_name'] ?: $listing['legal_name'];
?>
<section class="container py-4">
    <nav aria-label="<?= __e('common.breadcrumb') ?>" class="mb-3">
        <ol class="breadcrumb fs-sm mb-0">
            <li class="breadcrumb-item"><a href="<?= e(url('/marketplace')) ?>">سوق المشروعات</a></li>
            <li class="breadcrumb-item">
                <a href="<?= e(url('/business/' . $listing['seller_slug'])) ?>"><?= e($sellerName) ?></a>
            </li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($listing['name_ar']) ?></li>
        </ol>
    </nav>

    <div class="row g-4">
        <?php // ─── الصور ─── ?>
        <div class="col-lg-6">
            <div class="np-card">
                <div class="listing-gallery">
                    <?php if (!empty($listing['primary_media_id'])): ?>
                        <img src="<?= e(url('/files/' . $listing['primary_media_id'])) ?>"
                             alt="<?= e($listing['name_ar']) ?>" class="listing-gallery__main">
                    <?php else: ?>
                        <div class="listing-gallery__main listing-card__placeholder" aria-hidden="true">
                            <?= $listing['listing_type'] === 'service' ? '🧰' : '📦' ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (count($images) > 1): ?>
                    <div class="np-card__body">
                        <div class="d-flex gap-2 flex-wrap">
                            <?php foreach ($images as $image): ?>
                                <img src="<?= e(url('/files/' . $image['media_id'])) ?>"
                                     alt="<?= e($image['alt_text'] ?? $listing['name_ar']) ?>"
                                     loading="lazy"
                                     style="width:72px;height:72px;object-fit:cover;border-radius:var(--np-radius-sm);border:1px solid var(--np-line)">
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php // ─── التفاصيل والشراء ─── ?>
        <div class="col-lg-6">
            <div class="np-card">
                <div class="np-card__body">
                    <div class="d-flex gap-2 mb-2">
                        <span class="np-badge np-badge--muted">
                            <?= $listing['listing_type'] === 'service' ? 'خدمة' : 'منتج' ?>
                        </span>
                        <?php if (!empty($listing['category_name'])): ?>
                            <span class="np-badge np-badge--info"><?= e($listing['category_name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <h1 class="h4 mb-2"><?= e($listing['name_ar']) ?></h1>

                    <p class="fs-sm text-muted-np mb-3">
                        <a href="<?= e(url('/business/' . $listing['seller_slug'])) ?>"><?= e($sellerName) ?></a>
                        <span class="np-verified"><span aria-hidden="true">✓</span> موثّقة</span>
                        <?php if (!empty($listing['governorate_name'])): ?>
                            · <?= e($listing['governorate_name']) ?>
                        <?php endif; ?>
                    </p>

                    <?php if (!empty($listing['short_description'])): ?>
                        <p class="mb-3"><?= e($listing['short_description']) ?></p>
                    <?php endif; ?>

                    <div class="mb-3">
                        <?php if ($isQuote): ?>
                            <span class="np-badge np-badge--info px-3 py-2">السعر حسب الطلب — اطلب عرض سعر</span>
                        <?php else: ?>
                            <div class="stat-value">
                                <?= e(money((float) $listing['price'], (string) $listing['currency_code'])) ?>
                                <?php if (!empty($listing['unit_of_measure'])): ?>
                                    <span class="fs-6 text-muted-np">/ <?= e($listing['unit_of_measure']) ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="fs-xs text-muted-np mb-0">
                                <?= (int) $listing['vat_included'] === 1 ? 'السعر شامل ضريبة القيمة المضافة' : 'السعر غير شامل الضريبة' ?>
                                <?php if ((float) $listing['vat_rate'] > 0): ?>
                                    (<?= e(number_ar((float) $listing['vat_rate'], 0)) ?>%)
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <dl class="row fs-sm mb-3">
                        <?php if ((float) $listing['min_order_quantity'] > 1): ?>
                            <dt class="col-5 fw-normal text-muted-np">الحد الأدنى للطلب</dt>
                            <dd class="col-7"><?= e(number_ar((float) $listing['min_order_quantity'], 0)) ?>
                                <?= e($listing['unit_of_measure'] ?? '') ?></dd>
                        <?php endif; ?>

                        <?php if ($listing['lead_time_days'] !== null): ?>
                            <dt class="col-5 fw-normal text-muted-np">مدة التنفيذ</dt>
                            <dd class="col-7"><?= e(number_ar((int) $listing['lead_time_days'])) ?> يوم</dd>
                        <?php endif; ?>

                        <?php if (!empty($listing['delivery_area'])): ?>
                            <dt class="col-5 fw-normal text-muted-np">نطاق التوصيل</dt>
                            <dd class="col-7"><?= e($listing['delivery_area']) ?></dd>
                        <?php endif; ?>

                        <?php if ((int) $listing['track_inventory'] === 1): ?>
                            <dt class="col-5 fw-normal text-muted-np">المتاح</dt>
                            <dd class="col-7">
                                <?php if ($inStock): ?>
                                    <?= e(number_ar((float) $listing['available_quantity'], 0)) ?>
                                    <?= e($listing['unit_of_measure'] ?? '') ?>
                                <?php else: ?>
                                    <span class="text-danger">غير متوفر حالياً</span>
                                <?php endif; ?>
                            </dd>
                        <?php endif; ?>
                    </dl>

                    <?php if ($isQuote): ?>
                        <a class="btn btn-primary w-100"
                           href="<?= e(url('/business/' . $listing['seller_slug']) . '#quote') ?>">
                            اطلب عرض سعر
                        </a>
                    <?php elseif (!$inStock): ?>
                        <button class="btn btn-secondary w-100" disabled>غير متوفر حالياً</button>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('/cart/add')) ?>" class="d-flex gap-2" data-guard>
                            <?= csrf_field() ?>
                            <input type="hidden" name="listing_id" value="<?= e((string) $listing['id']) ?>">
                            <label class="visually-hidden" for="quantity">الكمية</label>
                            <input type="number" class="form-control" id="quantity" name="quantity"
                                   value="<?= e((string) (float) $listing['min_order_quantity']) ?>"
                                   min="<?= e((string) (float) $listing['min_order_quantity']) ?>"
                                   step="0.001" style="max-width:8rem" dir="ltr">
                            <button type="submit" class="btn btn-primary flex-grow-1">أضف إلى السلة</button>
                        </form>
                    <?php endif; ?>

                    <a class="btn btn-outline-primary w-100 mt-2"
                       href="<?= e(url('/business/' . $listing['seller_slug']) . '#enquiry') ?>">
                        استفسر عن هذا الصنف
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($listing['description'])): ?>
        <div class="np-card mt-4">
            <div class="np-card__header">الوصف التفصيلي</div>
            <div class="np-card__body">
                <?php // نص عادي يُهرَّب ثم تُحوَّل الأسطر — لا HTML من المستخدم ?>
                <p class="mb-0" style="white-space:pre-line"><?= e($listing['description']) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($reviews !== []): ?>
        <div class="np-card mt-4">
            <div class="np-card__header">
                تقييمات العملاء
                <span class="fs-sm text-muted-np fw-normal">بعد طلبات مكتملة فقط</span>
            </div>
            <div class="np-card__body p-0">
                <?php foreach ($reviews as $review): ?>
                    <div class="p-3 border-bottom border-np">
                        <div class="d-flex justify-content-between">
                            <strong class="fs-sm"><?= e($review['customer_name']) ?></strong>
                            <span class="fs-sm">
                                <?= e(str_repeat('★', (int) $review['rating'])) ?><span
                                    class="text-muted-np"><?= e(str_repeat('☆', 5 - (int) $review['rating'])) ?></span>
                            </span>
                        </div>
                        <?php if (!empty($review['comment'])): ?>
                            <p class="fs-sm mb-1 mt-1"><?= e($review['comment']) ?></p>
                        <?php endif; ?>
                        <span class="fs-xs text-muted-np"><?= e(time_ago($review['created_at'])) ?></span>

                        <?php if (!empty($review['seller_reply'])): ?>
                            <div class="mt-2 ps-3 border-start border-np">
                                <span class="fs-xs fw-bold">رد المنشأة:</span>
                                <p class="fs-sm mb-0"><?= e($review['seller_reply']) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (count($related) > 1): ?>
        <h2 class="h5 mt-5 mb-3">أصناف أخرى من نفس المنشأة</h2>
        <div class="row g-3">
            <?php foreach ($related as $item): ?>
                <?php if ((int) $item['id'] === (int) $listing['id']) { continue; } ?>
                <div class="col-6 col-md-3">
                    <?= $view->partial('partials/listing-card', [
                        'listing' => $item + [
                            'seller_slug'  => $listing['seller_slug'],
                            'trading_name' => $listing['trading_name'],
                            'legal_name'   => $listing['legal_name'],
                        ],
                    ]) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
