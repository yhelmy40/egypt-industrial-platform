<?php
/** صفحة تسجيل الدخول | Login page. */
?>
<h1 class="h3 mb-1"><?= __e('auth.login_title') ?></h1>
<p class="text-muted-np mb-4"><?= __e('auth.login_subtitle') ?></p>

<form method="post" action="<?= e(url('/auth/login')) ?>" novalidate data-guard>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label class="form-label" for="email">
            <?= __e('auth.email') ?><span class="required" aria-hidden="true">*</span>
        </label>
        <input type="email" class="form-control<?= has_error('email') ? ' is-invalid' : '' ?>"
               id="email" name="email" value="<?= e(old('email')) ?>"
               autocomplete="username" required autofocus dir="ltr">
        <?php if (has_error('email')): ?>
            <div class="invalid-feedback"><?= e(error_for('email')) ?></div>
        <?php endif; ?>
    </div>

    <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center">
            <label class="form-label mb-0" for="password">
                <?= __e('auth.password') ?><span class="required" aria-hidden="true">*</span>
            </label>
            <a class="fs-sm" href="<?= e(url('/auth/forgot-password')) ?>">
                <?= __e('auth.forgot_password') ?>
            </a>
        </div>
        <input type="password" class="form-control mt-1<?= has_error('password') ? ' is-invalid' : '' ?>"
               id="password" name="password" autocomplete="current-password" required dir="ltr">
        <?php if (has_error('password')): ?>
            <div class="invalid-feedback"><?= e(error_for('password')) ?></div>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary w-100 mb-3" data-busy-label="جارٍ التحقق…">
        <?= __e('auth.login_action') ?>
    </button>

    <p class="text-center text-muted-np fs-sm mb-0">
        <?= __e('auth.no_account') ?>
        <a href="<?= e(url('/auth/register')) ?>"><?= __e('auth.create_account') ?></a>
    </p>
</form>
