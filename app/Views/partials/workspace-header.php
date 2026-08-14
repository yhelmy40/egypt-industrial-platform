<?php
/**
 * ترويسة مساحة العمل | Workspace header.
 * تشمل مبدّل المنشأة للمستخدمين المنتمين لأكثر من منشأة (§7).
 */

use App\Core\Session;
use App\Support\TenantContext;

$user          = Session::get('_user');
$organizations = $organizations ?? [];
?>
<header class="workspace-header">
    <button class="btn btn-link d-lg-none p-0 text-decoration-none" type="button"
            data-sidebar-toggle aria-expanded="false" aria-controls="workspaceSidebar"
            aria-label="<?= __e('common.toggle_menu') ?>">
        <span aria-hidden="true" style="font-size:1.4rem">☰</span>
    </button>

    <h1 class="h5 mb-0 flex-grow-1"><?= e($pageTitle ?? __('common.dashboard')) ?></h1>

    <?php if (count($organizations) > 1): ?>
        <?php // مبدّل المنشأة — يمرّ عبر POST مع CSRF لأنه يغيّر حالة الجلسة ?>
        <form method="post" action="<?= e(url('/app/switch-organization')) ?>" class="d-none d-md-block">
            <?= csrf_field() ?>
            <label class="visually-hidden" for="orgSwitcher"><?= __e('common.switch_organization') ?></label>
            <select class="form-select form-select-sm" id="orgSwitcher" name="organization_id"
                    data-auto-submit style="min-width:14rem">
                <?php foreach ($organizations as $org): ?>
                    <option value="<?= e((string) $org['id']) ?>"
                        <?= (int) $org['id'] === TenantContext::organizationId() ? ' selected' : '' ?>>
                        <?= e($org['trading_name'] ?: $org['legal_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript>
                <button type="submit" class="btn btn-sm btn-outline-primary mt-1"><?= __e('common.confirm') ?></button>
            </noscript>
        </form>
    <?php endif; ?>

    <div class="dropdown">
        <button class="btn btn-link text-decoration-none dropdown-toggle text-muted-np p-0"
                type="button" data-bs-toggle="dropdown" aria-expanded="false"
                aria-label="<?= __e('common.user_menu') ?>">
            <?= e($user['name'] ?? '') ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-start">
            <li class="dropdown-header fs-xs" dir="ltr"><?= e($user['email'] ?? '') ?></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= e(url('/app/account/password')) ?>"><?= __e('auth.new_password') ?></a></li>
            <li><a class="dropdown-item" href="<?= e(url('/')) ?>"><?= __e('common.home') ?></a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <form method="post" action="<?= e(url('/auth/logout')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="dropdown-item text-danger"><?= __e('auth.logout') ?></button>
                </form>
            </li>
        </ul>
    </div>
</header>
