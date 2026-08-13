<?php
/**
 * لوحة تحكم المنشأة | Organization dashboard (Phase 1 shell).
 *
 * البطاقات المعلّقة تُعرض كعناصر نائبة صريحة وليست بأرقام وهمية — الرقم صفر
 * الذي لا مصدر له خلفه يضلّل صاحب المشروع.
 * Pending cards are shown as explicit placeholders rather than fabricated
 * zeroes — an unsourced zero misleads the owner.
 *
 * @var array<string,mixed> $organization
 * @var array<int,array{title:string,description:string,url:string,done:bool}> $nextSteps
 * @var array{type:string,text:string} $statusMessage
 */

$status  = (string) ($organization['status'] ?? 'draft');
$score   = (int) ($organization['completion_score'] ?? 0);

$statusBadges = [
    'draft'              => ['np-badge--draft',   'مسودة'],
    'submitted'          => ['np-badge--pending', 'بانتظار المراجعة'],
    'under_review'       => ['np-badge--review',  'قيد المراجعة'],
    'more_info_required' => ['np-badge--warning', 'مطلوب استكمال بيانات'],
    'verified'           => ['np-badge--success', 'موثّقة'],
    'rejected'           => ['np-badge--danger',  'مرفوضة'],
    'suspended'          => ['np-badge--danger',  'موقوفة'],
];
[$badgeClass, $badgeLabel] = $statusBadges[$status] ?? ['np-badge--muted', $status];
?>

<?php if (($statusMessage['text'] ?? '') !== ''): ?>
    <div class="alert alert-<?= e($statusMessage['type']) ?>" role="alert">
        <?= e($statusMessage['text']) ?>
    </div>
<?php endif; ?>

<?php if (!empty($organization['is_demo'])): ?>
    <p><span class="np-demo-tag"><?= __e('common.demo_data') ?></span></p>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="np-card h-100">
            <div class="np-card__body">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                    <div>
                        <h2 class="h4 mb-1"><?= e($organization['trading_name'] ?: $organization['legal_name']) ?></h2>
                        <p class="text-muted-np fs-sm mb-0">
                            <?= e($organization['type_name'] ?? '') ?>
                            <?php if (!empty($organization['sector_name'])): ?>
                                · <?= e($organization['sector_name']) ?>
                            <?php endif; ?>
                            <?php if (!empty($organization['governorate_name'])): ?>
                                · <?= e($organization['governorate_name']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <span class="np-badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span>
                </div>

                <label class="form-label fs-sm mb-2" for="completionMeter">نسبة اكتمال ملف المنشأة</label>
                <div class="completion" id="completionMeter">
                    <div class="completion__track" role="progressbar"
                         aria-valuenow="<?= e((string) $score) ?>" aria-valuemin="0" aria-valuemax="100"
                         aria-label="نسبة اكتمال الملف">
                        <div class="completion__fill" style="width: <?= e((string) $score) ?>%"></div>
                    </div>
                    <span class="completion__value"><?= e((string) $score) ?>%</span>
                </div>
                <p class="form-text mb-0 mt-2">
                    كلّما اكتمل ملفك زادت دقة ترشيحات التمويل والخدمات المناسبة لمشروعك.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="np-card h-100">
            <div class="np-card__header">معلومات سريعة</div>
            <div class="np-card__body">
                <dl class="mb-0 fs-sm">
                    <div class="d-flex justify-content-between py-1">
                        <dt class="fw-normal text-muted-np">المعرّف العام</dt>
                        <dd class="mb-0" dir="ltr"><?= e($organization['slug'] ?? '—') ?></dd>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <dt class="fw-normal text-muted-np">تاريخ التسجيل</dt>
                        <dd class="mb-0"><?= e(format_date($organization['created_at'] ?? null)) ?></dd>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <dt class="fw-normal text-muted-np">تاريخ التوثيق</dt>
                        <dd class="mb-0"><?= e(format_date($organization['verified_at'] ?? null)) ?></dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="np-card h-100">
            <div class="np-card__header">الخطوات التالية</div>
            <div class="np-card__body">
                <?php foreach ($nextSteps as $index => $step): ?>
                    <div class="step-item<?= $step['done'] ? ' step-item--done' : '' ?>">
                        <span class="step-item__marker" aria-hidden="true">
                            <?= $step['done'] ? '✓' : (string) ($index + 1) ?>
                        </span>
                        <div class="flex-grow-1">
                            <div class="step-item__title"><?= e($step['title']) ?></div>
                            <p class="step-item__desc"><?= e($step['description']) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="np-card h-100">
            <div class="np-card__header">وحدات ستُفعَّل تباعاً</div>
            <div class="np-card__body">
                <p class="text-muted-np fs-sm">
                    تُبنى المنصة على مراحل. الوحدات التالية قيد التنفيذ وستظهر في قائمتك
                    الجانبية فور جاهزيتها:
                </p>
                <ul class="fs-sm mb-0 ps-3">
                    <li>تسجيل المنشأة والتوثيق ورفع المستندات</li>
                    <li>الصفحة التعريفية العامة وسوق المنتجات والخدمات</li>
                    <li>فرص التمويل وطلبات التمويل ومتابعتها</li>
                    <li>الخدمات غير المالية وطلبات الخدمة وعروض الأسعار</li>
                    <li>الدعم الإرشادي من مراكز تطوير الأعمال</li>
                    <li>إدارة العملاء والمخزون والفواتير والتقارير</li>
                </ul>
            </div>
        </div>
    </div>
</div>
