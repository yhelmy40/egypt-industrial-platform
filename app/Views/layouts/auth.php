<?php
/**
 * تخطيط صفحات المصادقة | Authentication layout.
 * @var string $content
 * @var App\Core\View $view
 */

$appName = (string) config('app.name');
$title   = $pageTitle !== null && $pageTitle !== '' ? $pageTitle . ' — ' . $appName : $appName;
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.rtl.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body>
<div class="auth-page">
    <aside class="auth-page__aside">
        <a class="portal-brand text-white mb-4" href="<?= e(url('/')) ?>">
            <?= $view->partial('partials/logo') ?>
            <span class="text-white"><?= __e('portal.platform_name') ?></span>
        </a>

        <h2><?= __e('portal.platform_full') ?></h2>
        <p class="mb-0" style="color:rgba(255,255,255,.9)"><?= __e('portal.tagline') ?></p>

        <ul>
            <li><span aria-hidden="true">✓</span> صفحة تعريفية احترافية لمشروعك</li>
            <li><span aria-hidden="true">✓</span> عرض منتجاتك وخدماتك واستقبال الطلبات</li>
            <li><span aria-hidden="true">✓</span> الوصول إلى الخدمات المالية وغير المالية</li>
            <li><span aria-hidden="true">✓</span> أدوات مبسّطة لإدارة العملاء والمخزون والفواتير</li>
            <li><span aria-hidden="true">✓</span> دعم فني وإرشادي من مراكز تطوير الأعمال</li>
        </ul>
    </aside>

    <main class="auth-page__form" id="main-content">
        <div class="auth-page__form-inner">
            <?php foreach (($flash ?? []) as $message): ?>
                <?php $type = in_array($message['type'], ['success','danger','warning','info'], true) ? $message['type'] : 'info'; ?>
                <div class="alert alert-<?= e($type) ?>" role="alert"><?= e($message['message']) ?></div>
            <?php endforeach; ?>

            <?= $content ?>
        </div>
    </main>
</div>

<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>" defer></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
