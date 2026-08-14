<?php
/**
 * صفحة منتج تمويلي | Financing product detail (§4.5).
 *
 * @var array<string,mixed> $product
 * @var array<int,array<string,mixed>> $related
 * @var \App\Services\FinancingProductService $service
 */

$providerName = $product['trading_name'] ?: $product['legal_name'];

/** نص متعدّد الأسطر يُعرض كقائمة | Render a multi-line field as a list. */
$lines = static fn (?string $text): array => array_values(array_filter(
    array_map('trim', preg_split('/\r?\n/', (string) $text) ?: []),
    static fn (string $line): bool => $line !== '',
));
?>
<section class="container py-4">
    <nav aria-label="<?= __e('common.breadcrumb') ?>" class="mb-3">
        <ol class="breadcrumb fs-sm mb-0">
            <li class="breadcrumb-item"><a href="<?= e(url('/financing')) ?>">فرص التمويل</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($product['name_ar']) ?></li>
        </ol>
    </nav>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="np-card mb-3">
                <div class="np-card__body">
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <span class="np-badge np-badge--info">
                            <?= e($service->typeLabel((string) $product['financing_type'])) ?></span>
                        <?php if (!empty($product['is_demo'])): ?>
                            <span class="np-demo-tag"><?= __e('common.demo_data') ?></span>
                        <?php endif; ?>
                    </div>

                    <h1 class="h4 mb-2"><?= e($product['name_ar']) ?></h1>

                    <p class="fs-sm text-muted-np mb-3">
                        <a href="<?= e(url('/business/' . $product['provider_slug'])) ?>">
                            <?= e($providerName) ?></a>
                        <span class="np-verified"><span aria-hidden="true">✓</span> مؤسسة موثّقة</span>
                    </p>

                    <?php if (!empty($product['short_description'])): ?>
                        <p><?= e($product['short_description']) ?></p>
                    <?php endif; ?>

                    <?php if (!empty($product['description'])): ?>
                        <p class="mb-0" style="white-space:pre-line"><?= e($product['description']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php // ─── شروط الأهلية ─── ?>
            <?php if (!empty($product['eligibility_summary_ar'])): ?>
                <div class="np-card mb-3">
                    <div class="np-card__header">شروط الأهلية المعلنة</div>
                    <div class="np-card__body">
                        <ul class="mb-0">
                            <?php foreach ($lines($product['eligibility_summary_ar']) as $line): ?>
                                <li><?= e($line) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="fs-xs text-muted-np mb-0 mt-3">
                            استيفاء هذه الشروط لا يعني الموافقة: المؤسسة تدرس كل طلب على حدة
                            وفق سياستها الائتمانية.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <?php // ─── المستندات ─── ?>
            <?php if (!empty($product['required_documents_ar'])): ?>
                <div class="np-card mb-3">
                    <div class="np-card__header">المستندات المطلوبة</div>
                    <div class="np-card__body">
                        <ul class="mb-0">
                            <?php foreach ($lines($product['required_documents_ar']) as $line): ?>
                                <li><?= e($line) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php // ─── التكلفة ─── ?>
            <div class="np-card">
                <div class="np-card__header">التكلفة والرسوم</div>
                <div class="np-card__body">
                    <p class="mb-2"><?= e((string) ($product['rate_note_ar'] ?? 'غير محدَّدة.')) ?></p>
                    <?php if (!empty($product['fees_note_ar'])): ?>
                        <p class="fs-sm text-muted-np mb-2"><?= e($product['fees_note_ar']) ?></p>
                    <?php endif; ?>
                    <p class="fs-xs text-muted-np mb-0">
                        المنصة لا تحسب أقساطاً ولا جداول سداد. الأرقام النهائية تصدر عن المؤسسة
                        المالية عند التعاقد.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="np-card mb-3">
                <div class="np-card__header">ملخّص المنتج</div>
                <div class="np-card__body">
                    <dl class="row fs-sm mb-0">
                        <dt class="col-5 fw-normal text-muted-np">شريحة التمويل</dt>
                        <dd class="col-7 numeric">
                            <?= $product['min_amount'] === null
                                ? 'حسب الطلب'
                                : e(money((float) $product['min_amount']) . ' – '
                                    . money((float) $product['max_amount'])) ?>
                        </dd>

                        <?php if ($product['max_tenor_months'] !== null): ?>
                            <dt class="col-5 fw-normal text-muted-np">مدة السداد</dt>
                            <dd class="col-7">
                                حتى <?= e(number_ar((int) $product['max_tenor_months'])) ?> شهراً
                            </dd>
                        <?php endif; ?>

                        <?php if ($product['min_years_in_business'] !== null): ?>
                            <dt class="col-5 fw-normal text-muted-np">أقل مدة نشاط</dt>
                            <dd class="col-7">
                                <?= e(number_ar((int) $product['min_years_in_business'])) ?> سنة
                            </dd>
                        <?php endif; ?>

                        <dt class="col-5 fw-normal text-muted-np">التقنين</dt>
                        <dd class="col-7">
                            <?= (int) $product['requires_formal_registration'] === 1
                                ? 'مطلوب' : 'غير مشترط' ?>
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="np-card mb-3">
                <div class="np-card__body">
                    <a class="btn btn-primary w-100"
                       href="<?= e(url('/app/finance/products/' . $product['id'] . '/apply')) ?>">
                        تقديم طلب تمويل
                    </a>
                    <p class="fs-xs text-muted-np mb-0 mt-2">
                        التقديم يتطلّب حساباً ومنشأة موثّقة على المنصة.
                    </p>
                </div>
            </div>

            <?= $view->partial('partials/finance-disclaimer') ?>
        </div>
    </div>

    <?php if (count($related) > 1): ?>
        <h2 class="h5 mt-5 mb-3">منتجات أخرى من النوع نفسه</h2>
        <div class="row g-3">
            <?php foreach ($related as $item): ?>
                <?php if ((int) $item['id'] === (int) $product['id']) { continue; } ?>
                <div class="col-md-6 col-lg-4">
                    <?= $view->partial('partials/offer-card', [
                        'offer'     => $item,
                        'kind'      => 'financing',
                        'href'      => url('/financing/' . $item['slug']),
                        'typeLabel' => $service->typeLabel((string) $item['financing_type']),
                        'priceLine' => $item['min_amount'] === null
                            ? 'الشريحة حسب الطلب'
                            : money((float) $item['min_amount']) . ' – ' . money((float) $item['max_amount']),
                    ]) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
