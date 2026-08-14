<?php
/**
 * فرص التمويل في مساحة العمل | Financing opportunities inside the workspace (§4.5).
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,mixed> $filters
 * @var array<string,string> $types
 * @var array<int,array<string,mixed>> $suggestions
 * @var \App\Services\FinancingProductService $service
 */
?>
<div class="mb-3"><?= $view->partial('partials/finance-disclaimer') ?></div>

<div class="row g-3">
    <div class="col-lg-8">
        <form method="get" action="<?= e(url('/app/finance/opportunities')) ?>" class="row g-2 mb-3">
            <div class="col-md-5">
                <label class="visually-hidden" for="q">بحث</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['q']) ?>" placeholder="ابحث عن منتج تمويلي…">
            </div>
            <div class="col-md-4 col-6">
                <label class="visually-hidden" for="financing_type">النوع</label>
                <select class="form-select form-select-sm" id="financing_type" name="financing_type">
                    <option value="">كل الأنواع</option>
                    <?php foreach ($types as $code => $label): ?>
                        <option value="<?= e($code) ?>"
                            <?= $filters['financing_type'] === $code ? ' selected' : '' ?>>
                            <?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-4">
                <label class="visually-hidden" for="amount">المبلغ</label>
                <input type="number" class="form-control form-control-sm" id="amount" name="amount"
                       dir="ltr" min="0" step="1000" placeholder="المبلغ"
                       value="<?= e((string) $filters['amount']) ?>">
            </div>
            <div class="col-md-1 col-2 d-grid">
                <button type="submit" class="btn btn-sm btn-primary">↩</button>
            </div>
        </form>

        <?php if ($results['data'] === []): ?>
            <div class="np-card">
                <div class="np-empty">
                    <div class="np-empty__icon" aria-hidden="true">🏦</div>
                    <p class="mb-0">لا توجد منتجات مطابقة لبحثك.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="np-card">
                <div class="np-card__body p-0">
                    <?php foreach ($results['data'] as $product): ?>
                        <div class="p-3 border-bottom border-np">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <a class="fw-bold" target="_blank" rel="noopener"
                                       href="<?= e(url('/financing/' . $product['slug'])) ?>">
                                        <?= e($product['name_ar']) ?></a>
                                    <div class="fs-xs text-muted-np">
                                        <?= e($product['trading_name'] ?: $product['legal_name']) ?>
                                        · <?= e($service->typeLabel((string) $product['financing_type'])) ?>
                                        <?php if (!empty($product['is_demo'])): ?>
                                            <span class="np-demo-tag"><?= __e('common.demo_data') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <a class="btn btn-sm btn-primary"
                                   href="<?= e(url('/app/finance/products/' . $product['id'] . '/apply')) ?>">
                                    تقديم طلب</a>
                            </div>

                            <?php if (!empty($product['short_description'])): ?>
                                <p class="fs-sm text-muted-np mt-2 mb-1">
                                    <?= e(str_excerpt($product['short_description'], 140)) ?></p>
                            <?php endif; ?>

                            <div class="fs-sm numeric">
                                <?= $product['min_amount'] === null
                                    ? 'الشريحة حسب الطلب'
                                    : e(money((float) $product['min_amount']) . ' – '
                                        . money((float) $product['max_amount'])) ?>
                                <?php if ($product['max_tenor_months'] !== null): ?>
                                    <span class="text-muted-np">
                                        · حتى <?= e(number_ar((int) $product['max_tenor_months'])) ?> شهراً</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="np-card">
            <div class="np-card__header">
                مقترح لمشروعك
                <a class="fs-xs fw-normal" href="<?= e(url('/app/assessment')) ?>">تحديث</a>
            </div>
            <div class="np-card__body p-0">
                <?= $view->partial('partials/suggestion-list', [
                    'suggestions'  => $suggestions,
                    'hrefPrefix'   => '/financing/',
                    'emptyMessage' => 'أكمل تقييم احتياجات مشروعك لتظهر لك اقتراحات مبنية على بياناتك.',
                ]) ?>
            </div>
        </div>
    </div>
</div>
