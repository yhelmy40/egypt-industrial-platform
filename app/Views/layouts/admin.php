<?php
/**
 * تخطيط لوحة إدارة المنصة | Platform admin layout.
 * يعيد استخدام هيكل مساحة العمل مع قائمة جانبية إدارية.
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
<a class="skip-link" href="#main-content"><?= __e('common.skip_to_content') ?></a>

<div class="workspace">
    <?= $view->partial('partials/admin-sidebar') ?>
    <div class="sidebar-backdrop"></div>

    <div class="workspace-main">
        <?= $view->partial('partials/workspace-header', ['pageTitle' => $pageTitle, 'organizations' => []]) ?>

        <main class="workspace-content" id="main-content">
            <?php foreach (($flash ?? []) as $message): ?>
                <?php $type = in_array($message['type'], ['success','danger','warning','info'], true) ? $message['type'] : 'info'; ?>
                <div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert">
                    <?= e($message['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= __e('common.close') ?>"></button>
                </div>
            <?php endforeach; ?>

            <?= $content ?>
        </main>
    </div>
</div>

<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>" defer></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
