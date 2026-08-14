<?php
/**
 * صفحة باقة خدمة | Service offering detail (§4.6).
 *
 * @var array<string,mixed> $offering
 * @var array<int,array<string,mixed>> $related
 * @var \App\Services\ServiceOfferingService $service
 */

$providerName = $offering['trading_name'] ?: $offering['legal_name'];

$lines = static fn (?string $text): array => array_values(array_filter(
    array_map('trim', preg_split('/\r?\n/', (string) $text) ?: []),
    static fn (string $line): bool => $line !== '',
));
?>
<section class="container py-4">
    <nav aria-label="<?= __e('common.breadcrumb') ?>" class="mb-3">
        <ol class="breadcrumb fs-sm mb-0">
            <li class="breadcrumb-item"><a href="<?= e(url('/services')) ?>">خدمات تطوير الأعمال</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($offering['name_ar']) ?></li>
        </ol>
    </nav>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="np-card mb-3">
                <div class="np-card__body">
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <span class="np-badge np-badge--review">
                            <?= e($service->typeLabel((string) $offering['service_type'])) ?></span>
                        <span class="np-badge np-badge--muted">
                            <?= e(\App\Services\ServiceOfferingService::DELIVERY_MODES[$offering['delivery_mode']]
                                ?? (string) $offering['delivery_mode']) ?></span>
                        <?php if (!empty($offering['is_demo'])): ?>
                            <span class="np-demo-tag"><?= __e('common.demo_data') ?></span>
                        <?php endif; ?>
                    </div>

                    <h1 class="h4 mb-2"><?= e($offering['name_ar']) ?></h1>

                    <p class="fs-sm text-muted-np mb-3">
                        <a href="<?= e(url('/business/' . $offering['provider_slug'])) ?>">
                            <?= e($providerName) ?></a>
                        <span class="np-verified"><span aria-hidden="true">✓</span> جهة موثّقة</span>
                    </p>

                    <?php if (!empty($offering['short_description'])): ?>
                        <p><?= e($offering['short_description']) ?></p>
                    <?php endif; ?>

                    <?php if (!empty($offering['description'])): ?>
                        <p class="mb-0" style="white-space:pre-line"><?= e($offering['description']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($offering['deliverables_ar'])): ?>
                <div class="np-card mb-3">
                    <div class="np-card__header">ما تحصل عليه</div>
                    <div class="np-card__body">
                        <ul class="mb-0">
                            <?php foreach ($lines($offering['deliverables_ar']) as $line): ?>
                                <li><?= e($line) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($offering['target_audience_ar'])): ?>
                <div class="np-card">
                    <div class="np-card__header">لمن هذه الخدمة</div>
                    <div class="np-card__body">
                        <p class="mb-0" style="white-space:pre-line">
                            <?= e($offering['target_audience_ar']) ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <div class="np-card mb-3">
                <div class="np-card__header">التفاصيل</div>
                <div class="np-card__body">
                    <div class="stat-value fs-4 mb-2"><?= e($service->priceLabel($offering)) ?></div>

                    <?php if ((string) $offering['pricing_mode'] === 'free'): ?>
                        <p class="fs-xs text-muted-np">
                            المنصة لا تتحمّل تكلفة الخدمات المجانية؛ الجهة الممولة مذكورة أعلاه.
                        </p>
                    <?php endif; ?>

                    <dl class="row fs-sm mb-0">
                        <?php if (!empty($offering['duration_note_ar'])): ?>
                            <dt class="col-5 fw-normal text-muted-np">المدة</dt>
                            <dd class="col-7"><?= e($offering['duration_note_ar']) ?></dd>
                        <?php endif; ?>

                        <dt class="col-5 fw-normal text-muted-np">التنفيذ</dt>
                        <dd class="col-7">
                            <?= e(\App\Services\ServiceOfferingService::DELIVERY_MODES[$offering['delivery_mode']]
                                ?? (string) $offering['delivery_mode']) ?></dd>

                        <dt class="col-5 fw-normal text-muted-np">التغطية</dt>
                        <dd class="col-7">
                            <?= (int) $offering['covers_all_governorates'] === 1
                                ? 'كل المحافظات' : 'محافظات محدَّدة' ?></dd>
                    </dl>
                </div>
            </div>

            <div class="np-card mb-3">
                <div class="np-card__body">
                    <a class="btn btn-primary w-100"
                       href="<?= e(url('/app/services/browse/' . $offering['id'] . '/request')) ?>">
                        طلب هذه الخدمة
                    </a>
                    <p class="fs-xs text-muted-np mb-0 mt-2">
                        الطلب يتطلّب حساباً ومنشأة مسجّلة على المنصة.
                    </p>
                </div>
            </div>

            <div class="alert alert-info fs-sm mb-0">
                المنصة تعرض الباقة وتنقل الطلب. الاتفاق على النطاق والسعر والتنفيذ يتم بينك
                وبين مقدّم الخدمة، والمنصة ليست طرفاً في التعاقد.
            </div>
        </div>
    </div>

    <?php if (count($related) > 1): ?>
        <h2 class="h5 mt-5 mb-3">خدمات أخرى من النوع نفسه</h2>
        <div class="row g-3">
            <?php foreach ($related as $item): ?>
                <?php if ((int) $item['id'] === (int) $offering['id']) { continue; } ?>
                <div class="col-md-6 col-lg-4">
                    <?= $view->partial('partials/offer-card', [
                        'offer'     => $item,
                        'kind'      => 'service',
                        'href'      => url('/services/' . $item['slug']),
                        'typeLabel' => $service->typeLabel((string) $item['service_type']),
                        'priceLine' => $service->priceLabel($item),
                    ]) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
