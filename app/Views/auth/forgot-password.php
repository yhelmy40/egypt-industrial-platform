<?php /** استعادة كلمة المرور | Forgot password. */ ?>
<h1 class="h3 mb-1"><?= __e('auth.forgot_title') ?></h1>
<p class="text-muted-np mb-4"><?= __e('auth.forgot_subtitle') ?></p>

<form method="post" action="<?= e(url('/auth/forgot-password')) ?>" novalidate data-guard>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="email"><?= __e('auth.email') ?><span class="required" aria-hidden="true">*</span></label>
        <input type="email" class="form-control<?= has_error('email') ? ' is-invalid' : '' ?>"
               id="email" name="email" value="<?= e(old('email')) ?>" autocomplete="email" required autofocus dir="ltr">
        <?php if (has_error('email')): ?><div class="invalid-feedback"><?= e(error_for('email')) ?></div><?php endif; ?>
    </div>
    <button type="submit" class="btn btn-primary w-100 mb-3" data-busy-label="جارٍ الإرسال…"><?= __e('auth.send_reset_link') ?></button>
    <p class="text-center fs-sm mb-0"><a href="<?= e(url('/auth/login')) ?>"><?= __e('auth.back_to_login') ?></a></p>
</form>
