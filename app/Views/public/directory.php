<?php
/** دليل الأعمال | Verified business directory (§4.1). */
?>
<section class="bg-white border-bottom border-np py-4">
    <div class="container">
        <h1 class="h4 mb-1">دليل الأعمال</h1>
        <p class="text-muted-np fs-sm mb-3">المشروعات الموثّقة التي نشرت صفحاتها التعريفية على المنصة.</p>

        <form method="get" action="<?= e(url('/directory')) ?>" class="row g-2">
            <div class="col-md-5">
                <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>"
                       placeholder="ابحث باسم المنشأة أو نشاطها…" aria-label="بحث">
            </div>
            <div class="col-md-3 col-6">
                <select class="form-select" name="sector_id" aria-label="القطاع">
                    <option value="">كل القطاعات</option>
                    <?php foreach ($sectors as $sector): ?>
                        <option value="<?= e((string) $sector['id']) ?>"
                            <?= (int) $filters['sector_id'] === (int) $sector['id'] ? ' selected' : '' ?>>
                            <?= e($sector['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-6">
                <select class="form-select" name="governorate_id" aria-label="المحافظة">
                    <option value="">كل المحافظات</option>
                    <?php foreach ($governorates as $governorate): ?>
                        <option value="<?= e((string) $governorate['id']) ?>"
                            <?= (int) $filters['governorate_id'] === (int) $governorate['id'] ? ' selected' : '' ?>>
                            <?= e($governorate['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1"><button type="submit" class="btn btn-primary w-100">بحث</button></div>
        </form>
    </div>
</section>

<section class="container py-4">
    <p class="text-muted-np fs-sm"><?= e(number_ar($results['total'])) ?> منشأة</p>

    <?php if ($results['data'] === []): ?>
        <div class="np-card"><div class="np-empty">
            <div class="np-empty__icon" aria-hidden="true">🏢</div>
            <p class="mb-0">لا توجد منشآت مطابقة.</p>
        </div></div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($results['data'] as $org): ?>
                <div class="col-md-6 col-lg-4">
                    <a class="intent-card h-100" href="<?= e(url('/business/' . $org['slug'])) ?>">
                        <div class="d-flex gap-3 align-items-start mb-2">
                            <?php if (!empty($org['logo_media_id'])): ?>
                                <img src="<?= e(url('/files/' . $org['logo_media_id'])) ?>" alt="" width="48" height="48"
                                     loading="lazy" style="width:48px;height:48px;object-fit:cover;border-radius:var(--np-radius-sm)">
                            <?php else: ?>
                                <span class="intent-card__icon mb-0" style="width:48px;height:48px;font-size:1.2rem">🏢</span>
                            <?php endif; ?>
                            <div>
                                <span class="intent-card__title d-block"><?= e($org['trading_name'] ?: $org['legal_name']) ?></span>
                                <span class="np-verified fs-xs"><span aria-hidden="true">✓</span> موثّقة</span>
                            </div>
                        </div>
                        <p class="intent-card__desc"><?= e(str_excerpt($org['short_description'], 100)) ?></p>
                        <p class="fs-xs text-muted-np mb-0 mt-2">
                            <?= e($org['sector_name'] ?? '') ?>
                            <?php if (!empty($org['governorate_name'])): ?> · <?= e($org['governorate_name']) ?><?php endif; ?>
                            <?php if ((int) $org['listing_count'] > 0): ?>
                                · <?= e(number_ar((int) $org['listing_count'])) ?> صنف
                            <?php endif; ?>
                        </p>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
