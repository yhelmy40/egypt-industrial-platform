<?php
/**
 * بطاقة عرض | Offer card — shared by financing and service catalogues (§4.5, §4.6).
 *
 * بطاقة واحدة للنوعين: الاختلاف في سطر السعر والوسم فقط، ونسخها مرتين كان
 * سيجعل أي تحسين لاحق يُطبَّق على كتالوج وينسى الآخر.
 *
 * @var array<string,mixed> $offer
 * @var string $kind        financing|service
 * @var string $href        رابط التفاصيل
 * @var string $typeLabel
 * @var string $priceLine
 */
$providerName = $offer['trading_name'] ?: $offer['legal_name'];
?>
<div class="np-card h-100 offer-card">
    <div class="np-card__body d-flex flex-column h-100">
        <div class="d-flex flex-wrap gap-2 mb-2">
            <span class="np-badge <?= $kind === 'financing' ? 'np-badge--info' : 'np-badge--review' ?>">
                <?= e($typeLabel) ?>
            </span>
            <?php if (!empty($offer['is_demo'])): ?>
                <span class="np-demo-tag"><?= __e('common.demo_data') ?></span>
            <?php endif; ?>
        </div>

        <h3 class="h6 mb-1">
            <a href="<?= e($href) ?>"><?= e($offer['name_ar']) ?></a>
        </h3>

        <p class="fs-xs text-muted-np mb-2">
            <?= e($providerName) ?>
            <span class="np-verified"><span aria-hidden="true">✓</span> جهة موثّقة</span>
        </p>

        <?php if (!empty($offer['short_description'])): ?>
            <p class="fs-sm text-muted-np mb-3"><?= e(str_excerpt($offer['short_description'], 110)) ?></p>
        <?php endif; ?>

        <div class="mt-auto">
            <div class="fw-bold text-primary numeric mb-2"><?= e($priceLine) ?></div>
            <a class="btn btn-sm btn-outline-primary w-100" href="<?= e($href) ?>">التفاصيل والشروط</a>
        </div>
    </div>
</div>
