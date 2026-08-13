<?php
/**
 * تخطيط البوابة العامة | Public portal layout.
 *
 * @var string      $content
 * @var string|null $pageTitle
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

    <?php // بيانات SEO عربية | Arabic SEO metadata ?>
    <meta name="description" content="<?= e($metaDescription ?? __('portal.tagline')) ?>">
    <meta property="og:title" content="<?= e($title) ?>">
    <meta property="og:description" content="<?= e($metaDescription ?? __('portal.tagline')) ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ar_EG">

    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.rtl.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body>
<a class="skip-link" href="#main-content"><?= __e('common.skip_to_content') ?></a>

<?= $view->partial('partials/portal-header') ?>

<main id="main-content">
    <?= $view->partial('partials/flash', ['flash' => $flash ?? []]) ?>
    <?= $content ?>
</main>

<?= $view->partial('partials/portal-footer') ?>

<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>" defer></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
