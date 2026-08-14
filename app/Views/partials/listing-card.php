<?php
/**
 * بطاقة صنف | Listing card — shared by search, storefront and related lists.
 *
 * @var array<string,mixed> $listing
 */

$isQuote = ($listing['pricing_mode'] ?? 'fixed') === 'quote';
?>
<div class="np-card h-100 listing-card">
    <a class="listing-card__media" href="<?= e(url('/marketplace/' . $listing['slug'])) ?>"
       aria-label="<?= e($listing['name_ar']) ?>">
        <?php if (!empty($listing['primary_media_id'])): ?>
            <img src="<?= e(url('/files/' . $listing['primary_media_id'])) ?>"
                 alt="<?= e($listing['name_ar']) ?>" loading="lazy">
        <?php else: ?>
            <span class="listing-card__placeholder" aria-hidden="true">
                <?= ($listing['listing_type'] ?? 'product') === 'service' ? '🧰' : '📦' ?>
            </span>
        <?php endif; ?>

        <?php if (!empty($listing['is_featured'])): ?>
            <span class="listing-card__flag np-badge np-badge--warning">مميّز</span>
        <?php endif; ?>
    </a>

    <div class="np-card__body">
        <h3 class="h6 mb-1">
            <a href="<?= e(url('/marketplace/' . $listing['slug'])) ?>" class="stretched-link-none">
                <?= e($listing['name_ar']) ?>
            </a>
        </h3>

        <?php if (!empty($listing['short_description'])): ?>
            <p class="fs-sm text-muted-np mb-2"><?= e(str_excerpt($listing['short_description'], 80)) ?></p>
        <?php endif; ?>

        <?php if (!empty($listing['trading_name']) || !empty($listing['legal_name'])): ?>
            <p class="fs-xs text-muted-np mb-2">
                <a href="<?= e(url('/business/' . ($listing['seller_slug'] ?? ''))) ?>">
                    <?= e($listing['trading_name'] ?: $listing['legal_name']) ?>
                </a>
                <?php if (!empty($listing['governorate_name'])): ?>
                    · <?= e($listing['governorate_name']) ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-end gap-2">
            <div>
                <?php if ($isQuote): ?>
                    <span class="np-badge np-badge--info">اطلب عرض سعر</span>
                <?php else: ?>
                    <span class="fw-bold text-primary numeric">
                        <?= e(money((float) $listing['price'], (string) ($listing['currency_code'] ?? 'EGP'))) ?>
                    </span>
                    <?php if (!empty($listing['unit_of_measure'])): ?>
                        <span class="fs-xs text-muted-np">/ <?= e($listing['unit_of_measure']) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($listing['rating_count'])): ?>
                <span class="fs-xs text-muted-np">
                    ★ <?= e(number_ar((float) $listing['rating_average'], 1)) ?>
                    (<?= e(number_ar((int) $listing['rating_count'])) ?>)
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>
