<?php
/**
 * دليل فرص التمويل | Public financing catalogue (§4.5).
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,mixed> $filters
 * @var array<string,string> $types
 * @var \App\Services\FinancingProductService $service
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
        <h1 class="h4 mb-2">فرص التمويل</h1>
        <p class="text-muted-np fs-sm">
            منتجات تمويلية أدخلتها مؤسسات مالية موثّقة واعتمدها فريق المنصة قبل عرضها.
            الشروط معلنة كاملة قبل التقديم.
        </p>

        <form method="get" action="<?= e(url('/financing')) ?>" class="row g-2 mt-2">
            <div class="col-lg-4 col-md-6">
                <label class="visually-hidden" for="q">بحث</label>
                <input type="search" class="form-control" id="q" name="q"
                       value="<?= e($filters['q']) ?>" placeholder="ابحث عن منتج تمويلي…">
            </div>

            <div class="col-lg-3 col-md-6">
                <label class="visually-hidden" for="financing_type">نوع التمويل</label>
                <select class="form-select" id="financing_type" name="financing_type">
                    <option value="">كل الأنواع</option>
                    <?php foreach ($types as $code => $label): ?>
                        <option value="<?= e($code) ?>"
                            <?= $filters['financing_type'] === $code ? ' selected' : '' ?>>
                            <?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-4 col-6">
                <label class="visually-hidden" for="amount">المبلغ المطلوب</label>
                <input type="number" class="form-control" id="amount" name="amount" dir="ltr"
                       min="0" step="1000" value="<?= e((string) $filters['amount']) ?>"
                       placeholder="المبلغ المطلوب">
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
        </form>
    </div>
</section>

<section class="container py-4">
    <div class="mb-3"><?= $view->partial('partials/finance-disclaimer') ?></div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <p class="text-muted-np fs-sm mb-0"><?= e(number_ar($results['total'])) ?> منتجاً تمويلياً</p>

        <form method="get" action="<?= e(url('/financing')) ?>" class="d-flex align-items-center gap-2">
            <?php foreach (['q', 'financing_type', 'amount', 'governorate_id', 'sector_id'] as $key): ?>
                <input type="hidden" name="<?= e($key) ?>" value="<?= e((string) $filters[$key]) ?>">
            <?php endforeach; ?>
            <label class="fs-sm text-muted-np mb-0" for="sort">ترتيب:</label>
            <select class="form-select form-select-sm" id="sort" name="sort" data-auto-submit style="width:auto">
                <option value="newest"<?= $filters['sort'] === 'newest' ? ' selected' : '' ?>>الأحدث</option>
                <option value="amount_asc"<?= $filters['sort'] === 'amount_asc' ? ' selected' : '' ?>>الأقل حداً أدنى</option>
                <option value="amount_desc"<?= $filters['sort'] === 'amount_desc' ? ' selected' : '' ?>>الأعلى سقفاً</option>
                <option value="popular"<?= $filters['sort'] === 'popular' ? ' selected' : '' ?>>الأكثر طلباً</option>
            </select>
            <noscript><button type="submit" class="btn btn-sm btn-outline-primary">تطبيق</button></noscript>
        </form>
    </div>

    <?php if ($results['data'] === []): ?>
        <div class="np-card">
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">🏦</div>
                <p class="mb-1">لا توجد منتجات مطابقة.</p>
                <p class="fs-sm mb-0">جرّب إزالة بعض عوامل التصفية أو تعديل المبلغ.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($results['data'] as $product): ?>
                <div class="col-md-6 col-lg-4">
                    <?= $view->partial('partials/offer-card', [
                        'offer'     => $product,
                        'kind'      => 'financing',
                        'href'      => url('/financing/' . $product['slug']),
                        'typeLabel' => $service->typeLabel((string) $product['financing_type']),
                        'priceLine' => $product['min_amount'] === null
                            ? 'الشريحة حسب الطلب'
                            : money((float) $product['min_amount']) . ' – '
                              . money((float) $product['max_amount']),
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
                           href="<?= e(url('/financing') . $queryFor(['page' => $results['page'] - 1])) ?>">
                            <?= __e('common.previous') ?></a>
                    <?php endif; ?>
                    <?php if ($results['page'] < $results['last_page']): ?>
                        <a class="btn btn-sm btn-outline-primary"
                           href="<?= e(url('/financing') . $queryFor(['page' => $results['page'] + 1])) ?>">
                            <?= __e('common.next') ?></a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
