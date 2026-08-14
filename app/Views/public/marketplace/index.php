<?php
/**
 * تصفّح السوق | Marketplace browse and search (§4.4, §11).
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,mixed> $filters
 */

$queryFor = static function (array $overrides) use ($filters): string {
    $params = array_filter(
        array_merge($filters, $overrides),
        static fn ($v) => $v !== null && $v !== '',
    );

    return $params === [] ? '' : '?' . http_build_query($params);
};
?>
<section class="bg-white border-bottom border-np py-4">
    <div class="container">
        <h1 class="h4 mb-3">سوق المشروعات</h1>

        <form method="get" action="<?= e(url('/marketplace')) ?>" class="row g-2">
            <div class="col-lg-4 col-md-6">
                <label class="visually-hidden" for="q">بحث</label>
                <input type="search" class="form-control" id="q" name="q"
                       value="<?= e($filters['q']) ?>" placeholder="ابحث عن منتج أو خدمة…">
            </div>

            <div class="col-lg-2 col-md-3 col-6">
                <label class="visually-hidden" for="type">النوع</label>
                <select class="form-select" id="type" name="type">
                    <option value="">كل الأنواع</option>
                    <option value="product"<?= $filters['type'] === 'product' ? ' selected' : '' ?>>منتجات</option>
                    <option value="service"<?= $filters['type'] === 'service' ? ' selected' : '' ?>>خدمات</option>
                </select>
            </div>

            <div class="col-lg-2 col-md-3 col-6">
                <label class="visually-hidden" for="governorate_id">المحافظة</label>
                <select class="form-select" id="governorate_id" name="governorate_id">
                    <option value="">كل المحافظات</option>
                    <?php foreach ($governorates as $governorate): ?>
                        <option value="<?= e((string) $governorate['id']) ?>"
                            <?= (int) $filters['governorate_id'] === (int) $governorate['id'] ? ' selected' : '' ?>>
                            <?= e($governorate['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6 col-6">
                <label class="visually-hidden" for="sector_id">القطاع</label>
                <select class="form-select" id="sector_id" name="sector_id">
                    <option value="">كل القطاعات</option>
                    <?php foreach ($sectors as $sector): ?>
                        <option value="<?= e((string) $sector['id']) ?>"
                            <?= (int) $filters['sector_id'] === (int) $sector['id'] ? ' selected' : '' ?>>
                            <?= e($sector['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6 col-6 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><?= __e('common.search') ?></button>
                <a class="btn btn-outline-primary" href="<?= e(url('/marketplace')) ?>" title="<?= __e('common.reset_filters') ?>">↺</a>
            </div>
        </form>

        <?php // تصفية متقدّمة — تبقى مفتوحة إذا كانت مُستخدمة ?>
        <details class="mt-2" <?= ($filters['price_min'] || $filters['price_max'] || $filters['pricing_mode']) ? 'open' : '' ?>>
            <summary class="fs-sm text-muted-np" style="cursor:pointer">تصفية متقدّمة</summary>
            <form method="get" action="<?= e(url('/marketplace')) ?>" class="row g-2 mt-1">
                <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
                <input type="hidden" name="type" value="<?= e($filters['type']) ?>">
                <input type="hidden" name="governorate_id" value="<?= e((string) $filters['governorate_id']) ?>">
                <input type="hidden" name="sector_id" value="<?= e((string) $filters['sector_id']) ?>">

                <div class="col-md-3 col-6">
                    <label class="form-label fs-sm" for="price_min">أقل سعر</label>
                    <input type="number" class="form-control form-control-sm" id="price_min" name="price_min"
                           min="0" step="0.01" value="<?= e((string) $filters['price_min']) ?>" dir="ltr">
                </div>
                <div class="col-md-3 col-6">
                    <label class="form-label fs-sm" for="price_max">أعلى سعر</label>
                    <input type="number" class="form-control form-control-sm" id="price_max" name="price_max"
                           min="0" step="0.01" value="<?= e((string) $filters['price_max']) ?>" dir="ltr">
                </div>
                <div class="col-md-3 col-6">
                    <label class="form-label fs-sm" for="pricing_mode">طريقة التسعير</label>
                    <select class="form-select form-select-sm" id="pricing_mode" name="pricing_mode">
                        <option value="">الكل</option>
                        <option value="fixed"<?= $filters['pricing_mode'] === 'fixed' ? ' selected' : '' ?>>سعر معروض</option>
                        <option value="quote"<?= $filters['pricing_mode'] === 'quote' ? ' selected' : '' ?>>عرض سعر</option>
                    </select>
                </div>
                <div class="col-md-3 col-6 d-flex align-items-end">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">تطبيق</button>
                </div>
            </form>
        </details>
    </div>
</section>

<section class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <p class="text-muted-np fs-sm mb-0">
            <?= e(number_ar($results['total'])) ?> نتيجة
        </p>

        <form method="get" action="<?= e(url('/marketplace')) ?>" class="d-flex align-items-center gap-2">
            <?php foreach (['q', 'type', 'governorate_id', 'sector_id', 'price_min', 'price_max', 'pricing_mode'] as $key): ?>
                <input type="hidden" name="<?= e($key) ?>" value="<?= e((string) $filters[$key]) ?>">
            <?php endforeach; ?>
            <label class="fs-sm text-muted-np mb-0" for="sort">ترتيب:</label>
            <select class="form-select form-select-sm" id="sort" name="sort" data-auto-submit style="width:auto">
                <option value="newest"<?= $filters['sort'] === 'newest' ? ' selected' : '' ?>>الأحدث</option>
                <option value="price_asc"<?= $filters['sort'] === 'price_asc' ? ' selected' : '' ?>>الأقل سعراً</option>
                <option value="price_desc"<?= $filters['sort'] === 'price_desc' ? ' selected' : '' ?>>الأعلى سعراً</option>
                <option value="popular"<?= $filters['sort'] === 'popular' ? ' selected' : '' ?>>الأكثر طلباً</option>
                <option value="rating"<?= $filters['sort'] === 'rating' ? ' selected' : '' ?>>الأعلى تقييماً</option>
            </select>
            <noscript><button type="submit" class="btn btn-sm btn-outline-primary">تطبيق</button></noscript>
        </form>
    </div>

    <?php if ($results['data'] === []): ?>
        <div class="np-card">
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">🔍</div>
                <p class="mb-1">لا توجد نتائج مطابقة لبحثك.</p>
                <p class="fs-sm mb-0">جرّب كلمات أعم، أو أزل بعض عوامل التصفية.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($results['data'] as $listing): ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <?= $view->partial('partials/listing-card', ['listing' => $listing]) ?>
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
                           href="<?= e(url('/marketplace') . $queryFor(['page' => $results['page'] - 1])) ?>">
                            <?= __e('common.previous') ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($results['page'] < $results['last_page']): ?>
                        <a class="btn btn-sm btn-outline-primary"
                           href="<?= e(url('/marketplace') . $queryFor(['page' => $results['page'] + 1])) ?>">
                            <?= __e('common.next') ?>
                        </a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
