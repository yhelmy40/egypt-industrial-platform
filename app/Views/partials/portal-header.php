<?php
/**
 * ترويسة البوابة العامة | Public portal header.
 * روابط المراحل غير المكتملة تظهر مع وسم «قريباً» بدل روابط مكسورة.
 */

use App\Support\TenantContext;

$current = $_SERVER['REQUEST_URI'] ?? '/';

/** @var array<int,array{label:string,url:string,ready:bool}> $navItems */
$navItems = [
    ['label' => __('portal.nav_home'),          'url' => url('/'),             'ready' => true],
    ['label' => __('portal.nav_about'),         'url' => url('/about'),        'ready' => true],
    ['label' => __('portal.nav_marketplace'),   'url' => url('/marketplace'),  'ready' => true],
    ['label' => __('portal.nav_financing'),     'url' => url('/financing'),    'ready' => true],
    ['label' => __('portal.nav_non_financial'), 'url' => url('/services'),     'ready' => true],
    ['label' => __('portal.nav_bds'),           'url' => url('/bds-centers'),  'ready' => false],
    ['label' => __('portal.nav_knowledge'),     'url' => url('/knowledge'),    'ready' => false],
    ['label' => __('portal.nav_contact'),       'url' => url('/contact'),      'ready' => true],
];
?>
<header class="portal-header">
    <div class="container">
        <nav class="navbar navbar-expand-lg px-0" aria-label="<?= __e('common.main_menu') ?>">
            <a class="portal-brand" href="<?= e(url('/')) ?>">
                <?= $view->partial('partials/logo') ?>
                <span><?= __e('portal.platform_name') ?></span>
            </a>

            <button class="navbar-toggler border-0" type="button"
                    data-bs-toggle="collapse" data-bs-target="#portalNav"
                    aria-controls="portalNav" aria-expanded="false"
                    aria-label="<?= __e('common.toggle_menu') ?>">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="portalNav">
                <ul class="navbar-nav portal-nav me-auto mb-2 mb-lg-0">
                    <?php foreach ($navItems as $item): ?>
                        <li class="nav-item">
                            <?php if ($item['ready']): ?>
                                <a class="nav-link<?= $current === parse_url($item['url'], PHP_URL_PATH) ? ' active' : '' ?>"
                                   href="<?= e($item['url']) ?>"><?= e($item['label']) ?></a>
                            <?php else: ?>
                                <span class="nav-link text-muted-np" aria-disabled="true"
                                      title="ستتوفر هذه الخدمة في مرحلة لاحقة">
                                    <?= e($item['label']) ?>
                                    <small class="np-badge np-badge--muted">قريباً</small>
                                </span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="d-flex align-items-center gap-2">
                    <?php if (TenantContext::isAuthenticated()): ?>
                        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/app')) ?>">
                            <?= __e('common.dashboard') ?>
                        </a>
                        <form method="post" action="<?= e(url('/auth/logout')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-link btn-sm text-muted-np">
                                <?= __e('auth.logout') ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/auth/login')) ?>">
                            <?= __e('portal.nav_login') ?>
                        </a>
                        <a class="btn btn-primary btn-sm" href="<?= e(url('/auth/register')) ?>">
                            <?= __e('portal.nav_register') ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
    </div>
</header>
