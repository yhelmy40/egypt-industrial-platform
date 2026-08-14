<?php
/**
 * مراكز تطوير الأعمال | BDS centre directory for SMEs (§4.8).
 *
 * @var array<int,array<string,mixed>> $centers
 * @var array<int,array<string,mixed>> $myCases
 * @var array<string,string> $filters
 * @var \App\Services\BdsCaseService $service
 */
?>
<div class="row g-3">
    <div class="col-lg-8">
        <form method="get" action="<?= e(url('/app/bds/centers')) ?>" class="row g-2 mb-3">
            <div class="col-md-9">
                <label class="visually-hidden" for="governorate_id">المحافظة</label>
                <select class="form-select form-select-sm" id="governorate_id" name="governorate_id"
                        data-auto-submit>
                    <option value="">كل المحافظات</option>
                    <?php foreach ($governorates as $governorate): ?>
                        <option value="<?= e((string) $governorate['id']) ?>"
                            <?= $filters['governorate_id'] === (string) $governorate['id'] ? ' selected' : '' ?>>
                            <?= e($governorate['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-grid">
                <noscript><button type="submit" class="btn btn-sm btn-primary">تصفية</button></noscript>
            </div>
        </form>

        <?php if ($centers === []): ?>
            <div class="np-card">
                <div class="np-empty">
                    <div class="np-empty__icon" aria-hidden="true">🎓</div>
                    <p class="mb-1">لا توجد مراكز تخدم هذه المحافظة حالياً.</p>
                    <p class="fs-sm mb-0">جرّب إزالة التصفية لعرض المراكز التي تخدم كل المحافظات.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($centers as $center): ?>
                <div class="np-card mb-3">
                    <div class="np-card__body">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <h2 class="h6 mb-1">
                                    <?= e($center['trading_name'] ?: $center['legal_name']) ?>
                                    <span class="np-verified"><span aria-hidden="true">✓</span> موثّق</span>
                                    <?php if (!empty($center['is_demo'])): ?>
                                        <span class="np-demo-tag"><?= __e('common.demo_data') ?></span>
                                    <?php endif; ?>
                                </h2>
                                <p class="fs-xs text-muted-np mb-0">
                                    <?php if (!empty($center['host_entity'])): ?>
                                        <?= e($center['host_entity']) ?> ·
                                    <?php endif; ?>
                                    <?= (int) $center['serves_all_governorates'] === 1
                                        ? 'يخدم كل المحافظات'
                                        : e($center['governorate_name'] ?? '') ?>
                                </p>
                            </div>
                            <a class="btn btn-sm btn-primary"
                               href="<?= e(url('/app/bds/centers/' . $center['id'] . '/request')) ?>">
                                طلب دعم</a>
                        </div>

                        <?php if (!empty($center['short_description'])): ?>
                            <p class="fs-sm mt-2 mb-1"><?= e($center['short_description']) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($center['services_offered'])): ?>
                            <p class="fs-sm text-muted-np mb-1">
                                <strong>الخدمات:</strong> <?= e(str_excerpt($center['services_offered'], 200)) ?></p>
                        <?php endif; ?>

                        <div class="fs-xs text-muted-np">
                            <?php if (!empty($center['working_hours'])): ?>
                                <?= e($center['working_hours']) ?>
                            <?php endif; ?>
                            <?php if ((int) ($center['appointment_required'] ?? 0) === 1): ?>
                                · بموعد مسبق
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="np-card">
            <div class="np-card__header">
                طلبات دعمك
                <a class="fs-xs fw-normal" href="<?= e(url('/app/bds/my-cases')) ?>">الكل</a>
            </div>
            <div class="np-card__body p-0">
                <?php if ($myCases === []): ?>
                    <div class="np-empty py-4">
                        <p class="fs-sm mb-0">لم تطلب دعماً بعد.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($myCases as $case): ?>
                        <div class="p-3 border-bottom border-np">
                            <a class="fw-bold fs-sm"
                               href="<?= e(url('/app/bds/my-cases/' . $case['id'])) ?>">
                                <?= e(str_excerpt($case['title_ar'], 50)) ?></a>
                            <div class="fs-xs text-muted-np mt-1">
                                <?= e($case['center_trading_name'] ?: $case['center_legal_name']) ?>
                            </div>
                            <span class="np-badge <?= e($service->statusBadgeClass((string) $case['status'])) ?>">
                                <?= e($service->statusLabel((string) $case['status'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="alert alert-info fs-sm mt-3 mb-0">
            الدعم الفني والإرشادي خدمة تقدّمها المراكز، والمنصة تنقل الطلب وتنظّم المتابعة.
            ما يكتبه الأخصائي كملاحظة داخلية لا يظهر لك؛ ما يشاركه معك يظهر في صفحة الحالة.
        </div>
    </div>
</div>
