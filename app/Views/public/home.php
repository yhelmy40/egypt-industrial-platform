<?php
/**
 * الصفحة الرئيسية | Home page (§4.1).
 *
 * @var array<string,int> $stats
 * @var array<int,array<string,mixed>> $sectors
 */

/**
 * نقاط الدخول حسب النيّة | Intent entry points.
 * المسارات غير الجاهزة توجّه إلى التسجيل بدل رابط مكسور، مع توضيح ذلك للمستخدم.
 */
$intents = [
    ['icon' => '🚀', 'title' => __('portal.intent_start'),    'desc' => __('portal.intent_start_desc'),    'url' => url('/auth/register')],
    ['icon' => '📈', 'title' => __('portal.intent_grow'),     'desc' => __('portal.intent_grow_desc'),     'url' => url('/auth/register')],
    ['icon' => '🏦', 'title' => __('portal.intent_finance'),  'desc' => __('portal.intent_finance_desc'),  'url' => url('/auth/register')],
    ['icon' => '🧭', 'title' => __('portal.intent_consult'),  'desc' => __('portal.intent_consult_desc'),  'url' => url('/auth/register')],
    ['icon' => '🛒', 'title' => __('portal.intent_sell'),     'desc' => __('portal.intent_sell_desc'),     'url' => url('/auth/register')],
    ['icon' => '💻', 'title' => __('portal.intent_digitize'), 'desc' => __('portal.intent_digitize_desc'), 'url' => url('/auth/register')],
];

$steps = [
    ['n' => '1', 'title' => __('portal.step_register'), 'desc' => __('portal.step_register_desc')],
    ['n' => '2', 'title' => __('portal.step_verify'),   'desc' => __('portal.step_verify_desc')],
    ['n' => '3', 'title' => __('portal.step_publish'),  'desc' => __('portal.step_publish_desc')],
    ['n' => '4', 'title' => __('portal.step_grow'),     'desc' => __('portal.step_grow_desc')],
];
?>

<section class="hero">
    <div class="container">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <h1><?= __e('portal.hero_title') ?></h1>
                <p class="mb-4"><?= __e('portal.hero_subtitle') ?></p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-light btn-lg" href="<?= e(url('/auth/register')) ?>">
                        <?= __e('portal.hero_cta_primary') ?>
                    </a>
                    <a class="btn btn-outline-light btn-lg" href="<?= e(url('/about')) ?>">
                        <?= __e('portal.nav_about') ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php // ── نقاط الدخول حسب النيّة ── ?>
<section class="container py-5">
    <div class="text-center mb-4">
        <h2><?= __e('portal.intent_heading') ?></h2>
        <p class="text-muted-np mb-0"><?= __e('portal.intent_subheading') ?></p>
    </div>

    <div class="row g-3">
        <?php foreach ($intents as $intent): ?>
            <div class="col-md-6 col-lg-4">
                <a class="intent-card" href="<?= e($intent['url']) ?>">
                    <span class="intent-card__icon" aria-hidden="true"><?= e($intent['icon']) ?></span>
                    <span class="intent-card__title d-block"><?= e($intent['title']) ?></span>
                    <p class="intent-card__desc"><?= e($intent['desc']) ?></p>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php // ── المنصة في أرقام ── ?>
<section class="bg-white border-top border-bottom border-np py-5">
    <div class="container">
        <h2 class="text-center mb-4"><?= __e('portal.stats_heading') ?></h2>
        <div class="row g-3">
            <?php
            $tiles = [
                ['value' => $stats['total_smes'],    'label' => __('portal.stat_smes')],
                ['value' => $stats['verified_smes'], 'label' => __('portal.stat_verified')],
                ['value' => $stats['providers'],     'label' => __('portal.stat_providers')],
                ['value' => $stats['governorates'],  'label' => __('portal.stat_governorates')],
                ['value' => $stats['sectors'],       'label' => __('portal.stat_sectors')],
            ];
            ?>
            <?php foreach ($tiles as $tile): ?>
                <div class="col-6 col-lg">
                    <div class="stat-tile text-center">
                        <div class="stat-value d-block"><?= e(number_ar($tile['value'])) ?></div>
                        <div class="stat-tile__label"><?= e($tile['label']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php // ── كيف تعمل المنصة ── ?>
<section class="container py-5">
    <h2 class="text-center mb-4"><?= __e('portal.how_it_works') ?></h2>
    <div class="row g-4">
        <?php foreach ($steps as $step): ?>
            <div class="col-md-6 col-lg-3">
                <div class="np-card h-100">
                    <div class="np-card__body">
                        <div class="intent-card__icon mb-3"><?= e($step['n']) ?></div>
                        <h3 class="h5 mb-2"><?= e($step['title']) ?></h3>
                        <p class="text-muted-np fs-sm mb-0"><?= e($step['desc']) ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php // ── القطاعات المدعومة ── ?>
<section class="bg-white border-top border-np py-5">
    <div class="container">
        <h2 class="text-center mb-4">القطاعات المدعومة</h2>
        <div class="row g-2 justify-content-center">
            <?php foreach ($sectors as $sector): ?>
                <div class="col-auto">
                    <span class="np-badge np-badge--info px-3 py-2"><?= e($sector['name_ar']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="text-center text-muted-np fs-sm mt-4 mb-0">
            وغيرها من القطاعات — يغطي التصنيف <?= e(number_ar($stats['sectors'])) ?> قطاعاً اقتصادياً
            في <?= e(number_ar($stats['governorates'])) ?> محافظة.
        </p>
    </div>
</section>

<?php // ── دعوة للتسجيل ── ?>
<section class="container py-5">
    <div class="np-card">
        <div class="np-card__body text-center py-5">
            <h2 class="mb-3">ابدأ رحلة تطوير مشروعك اليوم</h2>
            <p class="text-muted-np mb-4">
                التسجيل مجاني، ويمنحك صفحة تعريفية لمشروعك وأدوات إدارة يومية
                ووصولاً إلى الخدمات المالية وغير المالية.
            </p>
            <a class="btn btn-primary btn-lg" href="<?= e(url('/auth/register')) ?>">
                <?= __e('portal.hero_cta_primary') ?>
            </a>
        </div>
    </div>
</section>
