<?php
/**
 * دليل خدمات تطوير الأعمال | Public service catalogue (§4.6).
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,mixed> $filters
 * @var array<string,string> $types
 * @var array<string,string> $deliveryModes
 * @var \App\Services\ServiceOfferingService $service
 */

$queryFor = static function (array $overrides) use ($filters): string {
    $params = array_filter(
        array_merge($filters, $overrides),
        static fn ($v) => $v !== null && $v !== '' && $v !== 0,
    );

    return $params === [] ? '' : '?' . http_build_query($params);
};
?>
<section class="bg-white border-bottom border-np py-4">
    <div class="container">
        <h1 class="h4 mb-2">خدمات تطوير الأعمال</h1>
        <p class="text-muted-np fs-sm">
            باقات خدمات غير مالية من مقدّمي خدمات ومنظمات موثّقة، معتمدة من فريق المنصة
            قبل عرضها. بعضها مجاني ضمن برامج تنموية، والجهة الممولة مذكورة في كل حالة.
        </p>

        <form method="get" action="<?= e(url('/services')) ?>" class="row g-2 mt-2">
            <div class="col-lg-4 col-md-6">
                <label class="visually-hidden" for="q">بحث</label>
                <input type="search" class="form-control" id="q" name="q"
                       value="<?= e($filters['q']) ?>" placeholder="ابحث عن خدمة…">
            </div>

            <div class="col-lg-3 col-md-6">
                <label class="visually-hidden" for="service_type">نوع الخدمة</label>
                <select class="form-select" id="service_type" name="service_type">
                    <option value="">كل الأنواع</option>
                    <?php foreach ($types as $code => $label): ?>
                        <option value="<?= e($code) ?>"
                            <?= $filters['service_type'] === $code ? ' selected' : '' ?>>
                            <?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-4 col-6">
                <label class="visually-hidden" for="delivery_mode">طريقة التنفيذ</label>
                <select class="form-select" id="delivery_mode" name="delivery_mode">
                    <option value="">أي طريقة</option>
                    <?php foreach ($deliveryModes as $code => $label): ?>
                        <option value="<?= e($code) ?>"
                            <?= $filters['delivery_mode'] === $code ? ' selected' : '' ?>>
                            <?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-4 col-6">
                <label class="visually-hidden" for="governorate_id">المحافظة</label>
                <select class="form-select" id="governorate_id" name="governorate_id">
                    <option value="">كل المحافظات</option>
                    <?php foreach ($governorates as $governorate): ?>
                        <option value="<?= e((string) $governorate['id']) ?>"
                            <?= (int) $filters['governorate_id'] === (int) $governorate['id'] ? ' selected' : '' ?>>
                            <?= e($governorate['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-1 col-md-4 col-6 d-grid">
                <button type="submit" class="btn btn-primary"><?= __e('common.search') ?></button>
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="free_only" name="free_only"
                        <?= $filters['free_only'] !== '' ? ' checked' : '' ?>>
                    <label class="form-check-label fs-sm" for="free_only">
                        الخدمات المجانية ضمن برامج تنموية فقط
                    </label>
                </div>
            </div>
        </form>
    </div>
</section>

<section class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <p class="text-muted-np fs-sm mb-0"><?= e(number_ar($results['total'])) ?> باقة خدمة</p>

        <form method="get" action="<?= e(url('/services')) ?>" class="d-flex align-items-center gap-2">
            <?php foreach (['q', 'service_type', 'delivery_mode', 'governorate_id', 'free_only'] as $key): ?>
                <input type="hidden" name="<?= e($key) ?>" value="<?= e((string) $filters[$key]) ?>">
            <?php endforeach; ?>
            <label class="fs-sm text-muted-np mb-0" for="sort">ترتيب:</label>
            <select class="form-select form-select-sm" id="sort" name="sort" data-auto-submit style="width:auto">
                <option value="newest"<?= $filters['sort'] === 'newest' ? ' selected' : '' ?>>الأحدث</option>
                <option value="price_asc"<?= $filters['sort'] === 'price_asc' ? ' selected' : '' ?>>الأقل سعراً</option>
                <option value="popular"<?= $filters['sort'] === 'popular' ? ' selected' : '' ?>>الأكثر طلباً</option>
                <option value="rating"<?= $filters['sort'] === 'rating' ? ' selected' : '' ?>>الأعلى تقييماً</option>
            </select>
            <noscript><button type="submit" class="btn btn-sm btn-outline-primary">تطبيق</button></noscript>
        </form>
    </div>

    <?php if ($results['data'] === []): ?>
        <div class="np-card">
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">🧭</div>
                <p class="mb-1">لا توجد خدمات مطابقة.</p>
                <p class="fs-sm mb-0">جرّب توسيع نطاق البحث أو إزالة بعض عوامل التصفية.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($results['data'] as $offering): ?>
                <div class="col-md-6 col-lg-4">
                    <?= $view->partial('partials/offer-card', [
                        'offer'     => $offering,
                        'kind'      => 'service',
                        'href'      => url('/services/' . $offering['slug']),
                        'typeLabel' => $service->typeLabel((string) $offering['service_type']),
                        'priceLine' => $service->priceLabel($offering),
                    ]) ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($results['last_page'] > 1): ?>
            <nav class="d-flex justify-content-between align-items-center mt-4" aria-label="صفحات النتائج">
                <span class="fs-sm text-muted-np">
                    <?= e(__('common.page_of', ['current' => $results['page'], 'last' => $results['last_page']])) ?>
                </span>
                <div class="d-flex gap-2">
                    <?php if ($results['page'] > 1): ?>
                        <a class="btn btn-sm btn-outline-primary"
                           href="<?= e(url('/services') . $queryFor(['page' => $results['page'] - 1])) ?>">
                            <?= __e('common.previous') ?></a>
                    <?php endif; ?>
                    <?php if ($results['page'] < $results['last_page']): ?>
                        <a class="btn btn-sm btn-outline-primary"
                           href="<?= e(url('/services') . $queryFor(['page' => $results['page'] + 1])) ?>">
                            <?= __e('common.next') ?></a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
