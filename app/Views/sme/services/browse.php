<?php
/**
 * تصفّح الخدمات | Browsing approved service offerings (§4.6).
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,mixed> $filters
 * @var array<string,string> $types
 * @var array<string,string> $deliveryModes
 * @var array<int,array<string,mixed>> $suggestions
 * @var \App\Services\ServiceOfferingService $service
 */
?>
<div class="row g-3">
    <div class="col-lg-8">
        <form method="get" action="<?= e(url('/app/services/browse')) ?>" class="row g-2 mb-3">
            <div class="col-md-5">
                <label class="visually-hidden" for="q">بحث</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['q']) ?>" placeholder="ابحث عن خدمة…">
            </div>
            <div class="col-md-3 col-6">
                <label class="visually-hidden" for="service_type">النوع</label>
                <select class="form-select form-select-sm" id="service_type" name="service_type">
                    <option value="">كل الأنواع</option>
                    <?php foreach ($types as $code => $label): ?>
                        <option value="<?= e($code) ?>"
                            <?= $filters['service_type'] === $code ? ' selected' : '' ?>>
                            <?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-6">
                <label class="visually-hidden" for="delivery_mode">التنفيذ</label>
                <select class="form-select form-select-sm" id="delivery_mode" name="delivery_mode">
                    <option value="">أي طريقة</option>
                    <?php foreach ($deliveryModes as $code => $label): ?>
                        <option value="<?= e($code) ?>"
                            <?= $filters['delivery_mode'] === $code ? ' selected' : '' ?>>
                            <?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-sm btn-primary">↩</button>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="free_only" name="free_only"
                        <?= $filters['free_only'] !== '' ? ' checked' : '' ?>>
                    <label class="form-check-label fs-sm" for="free_only">المجانية ضمن برامج تنموية فقط</label>
                </div>
            </div>
        </form>

        <?php if ($results['data'] === []): ?>
            <div class="np-card">
                <div class="np-empty">
                    <div class="np-empty__icon" aria-hidden="true">🧭</div>
                    <p class="mb-0">لا توجد خدمات مطابقة.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="np-card">
                <div class="np-card__body p-0">
                    <?php foreach ($results['data'] as $offering): ?>
                        <div class="p-3 border-bottom border-np">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <a class="fw-bold" target="_blank" rel="noopener"
                                       href="<?= e(url('/services/' . $offering['slug'])) ?>">
                                        <?= e($offering['name_ar']) ?></a>
                                    <div class="fs-xs text-muted-np">
                                        <?= e($offering['trading_name'] ?: $offering['legal_name']) ?>
                                        · <?= e($service->typeLabel((string) $offering['service_type'])) ?>
                                        <?php if (!empty($offering['is_demo'])): ?>
                                            <span class="np-demo-tag"><?= __e('common.demo_data') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <a class="btn btn-sm btn-primary"
                                   href="<?= e(url('/app/services/browse/' . $offering['id'] . '/request')) ?>">
                                    طلب الخدمة</a>
                            </div>

                            <?php if (!empty($offering['short_description'])): ?>
                                <p class="fs-sm text-muted-np mt-2 mb-1">
                                    <?= e(str_excerpt($offering['short_description'], 140)) ?></p>
                            <?php endif; ?>

                            <div class="fs-sm fw-bold text-primary numeric">
                                <?= e($service->priceLabel($offering)) ?></div>
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
                    'hrefPrefix'   => '/services/',
                    'emptyMessage' => 'أكمل تقييم احتياجات مشروعك لتظهر لك خدمات تعالج أولوياتك.',
                ]) ?>
            </div>
        </div>
    </div>
</div>
