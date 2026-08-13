<?php /** تعيين كلمة مرور جديدة | Reset password. @var string $token */ ?>
<h1 class="h3 mb-1"><?= __e('auth.reset_title') ?></h1>
<p class="text-muted-np mb-4"><?= __e('auth.password_hint') ?></p>

<form method="post" action="<?= e(url('/auth/reset-password')) ?>" novalidate data-guard>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <div class="mb-3">
        <label class="form-label" for="password"><?= __e('auth.new_password') ?><span class="required" aria-hidden="true">*</span></label>
        <input type="password" class="form-control<?= has_error('password') ? ' is-invalid' : '' ?>"
               id="password" name="password" autocomplete="new-password" required autofocus dir="ltr">
        <?php if (has_error('password')): ?><div class="invalid-feedback"><?= e(error_for('password')) ?></div><?php endif; ?>
    </div>

    <div class="mb-4">
        <label class="form-label" for="password_confirmation"><?= __e('auth.password_confirmation') ?><span class="required" aria-hidden="true">*</span></label>
        <input type="password" class="form-control<?= has_error('password_confirmation') ? ' is-invalid' : '' ?>"
               id="password_confirmation" name="password_confirmation" autocomplete="new-password" required dir="ltr">
        <?php if (has_error('password_confirmation')): ?><div class="invalid-feedback"><?= e(error_for('password_confirmation')) ?></div><?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary w-100 mb-3"><?= __e('auth.reset_action') ?></button>
    <p class="text-center fs-sm mb-0"><a href="<?= e(url('/auth/login')) ?>"><?= __e('auth.back_to_login') ?></a></p>
</form>
