<?php
/**
 * نتيجة التقييم والاقتراحات | Assessment result and suggestions (§4.7).
 *
 * @var array<string,mixed>|null $latest
 * @var array<string,string> $sections
 * @var array<int,array<string,mixed>> $financing
 * @var array<int,array<string,mixed>> $services
 * @var \App\Services\AssessmentService $service
 */
$priorities = $latest !== null && !empty($latest['priority_sections'])
    ? explode(',', (string) $latest['priority_sections'])
    : [];
?>
<?php if ($latest === null): ?>
    <div class="np-card mb-3">
        <div class="np-empty">
            <div class="np-empty__icon" aria-hidden="true">📊</div>
            <p class="mb-1">لم تُجرِ تقييم احتياجات بعد.</p>
            <p class="fs-sm">
                استبيان قصير عن ممارسات مشروعك الفعلية، ينتج عنه ترتيب لأولوياتك واقتراحات
                تمويل وخدمات مبنية عليها.
            </p>
            <a class="btn btn-primary" href="<?= e(url('/app/assessment/form')) ?>">ابدأ التقييم</a>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h5 mb-0">نتيجة التقييم</h1>
            <span class="fs-sm text-muted-np">
                اكتمل في <?= e(format_date($latest['completed_at'], true)) ?></span>
        </div>
        <div class="d-flex gap-2">
            <form method="post" action="<?= e(url('/app/assessment/refresh')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-outline-primary">تحديث الاقتراحات</button>
            </form>
            <a class="btn btn-sm btn-primary" href="<?= e(url('/app/assessment/form')) ?>">تقييم جديد</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="stat-tile">
                <div class="stat-tile__label">المؤشّر العام</div>
                <div class="stat-value"><?= e(number_ar((int) $latest['score_overall'])) ?>٪</div>
                <div class="stat-tile__meta"><?= e($service->scoreLabel((int) $latest['score_overall'])) ?></div>
            </div>
        </div>
        <div class="col-md-9">
            <div class="np-card h-100">
                <div class="np-card__body">
                    <?php foreach ($sections as $key => $label): ?>
                        <?php $score = $latest['score_' . $key] === null ? null : (int) $latest['score_' . $key]; ?>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <span class="fs-sm" style="min-width:11rem"><?= e($label) ?></span>
                            <div class="flex-grow-1" style="background:#eef1f4;border-radius:99px;height:8px">
                                <div style="width:<?= e((string) ($score ?? 0)) ?>%;height:8px;border-radius:99px;
                                            background:var(--np-primary)"></div>
                            </div>
                            <span class="np-badge <?= e($service->scoreBadgeClass($score)) ?>">
                                <?= $score === null ? 'غير مُقاس' : e(number_ar($score)) . '٪' ?></span>
                            <?php if (in_array($key, $priorities, true)): ?>
                                <span class="np-badge np-badge--warning">أولوية</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <p class="fs-xs text-muted-np mb-0 mt-3">
                        الدرجات مبنية على إجاباتك المعلنة دون تحقّق مستندي. هي وصف لحالة مشروعك
                        كما ذكرتها، وليست تقييماً ائتمانياً ولا تصنيف جدارة.
                    </p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="np-card">
            <div class="np-card__header">
                فرص تمويل مقترحة
                <a class="fs-xs fw-normal" href="<?= e(url('/app/finance/opportunities')) ?>">كل الفرص</a>
            </div>
            <div class="np-card__body p-0">
                <?= $view->partial('partials/suggestion-list', [
                    'suggestions'  => $financing,
                    'hrefPrefix'   => '/financing/',
                    'emptyMessage' => 'لا توجد اقتراحات تمويل بعد. أكمل التقييم وبيانات مشروعك.',
                ]) ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="np-card">
            <div class="np-card__header">
                خدمات مقترحة
                <a class="fs-xs fw-normal" href="<?= e(url('/app/services/browse')) ?>">كل الخدمات</a>
            </div>
            <div class="np-card__body p-0">
                <?= $view->partial('partials/suggestion-list', [
                    'suggestions'  => $services,
                    'hrefPrefix'   => '/services/',
                    'emptyMessage' => 'لا توجد اقتراحات خدمات بعد.',
                ]) ?>
            </div>
        </div>
    </div>
</div>
