<?php
/**
 * اختيار نوع المنشأة | Organization type selection (§4.2, step 0).
 * @var array<int,array<string,mixed>> $types
 */

$icons = [
    'sme'              => '🏭',
    'bank'             => '🏦',
    'ngo'              => '🤝',
    'service_provider' => '🧭',
    'bds_center'       => '🎓',
    'government'       => '🏛',
];
?>
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="mb-4">
            <h2 class="h4 mb-1">ما نوع المنشأة التي تريد تسجيلها؟</h2>
            <p class="text-muted-np mb-0">
                يحدّد النوع البيانات المطلوبة والمستندات والخدمات المتاحة لك على المنصة.
            </p>
        </div>

        <div class="row g-3">
            <?php foreach ($types as $type): ?>
                <div class="col-md-6">
                    <a class="intent-card h-100"
                       href="<?= e(url('/app/organization/new/form') . '?type=' . urlencode((string) $type['code'])) ?>">
                        <span class="intent-card__icon" aria-hidden="true">
                            <?= e($icons[$type['code']] ?? '🏢') ?>
                        </span>
                        <span class="intent-card__title d-block"><?= e($type['name_ar']) ?></span>
                        <p class="intent-card__desc"><?= e($type['description_ar']) ?></p>
                        <?php if ((int) $type['requires_verification'] === 1): ?>
                            <span class="np-badge np-badge--info mt-2">يتطلّب توثيقاً من فريق المنصة</span>
                        <?php endif; ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="alert alert-info mt-4 fs-sm" role="alert">
            <strong>ملاحظة:</strong> يمكنك تسجيل أكثر من منشأة بنفس الحساب، والتنقّل بينها
            من مبدّل المنشأة أعلى الصفحة.
        </div>
    </div>
</div>
