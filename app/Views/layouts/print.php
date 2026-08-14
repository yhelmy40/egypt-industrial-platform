<?php
/**
 * تخطيط المستندات القابلة للطباعة | Printable document layout (§4.10).
 *
 * تخطيط مجرّد بلا قوائم جانبية ولا أزرار: الورقة الخارجة للعميل يجب أن تحمل
 * المستند وحده. زرّ الطباعة نفسه يختفي عند الطباعة بقاعدة `@media print`.
 *
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
<body class="print-page">
<main id="main-content" class="print-sheet">
    <div class="print-toolbar no-print">
        <button type="button" class="btn btn-primary btn-sm" data-print="1">طباعة</button>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/app/invoices')) ?>">رجوع</a>
    </div>

    <?= $content ?>
</main>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
