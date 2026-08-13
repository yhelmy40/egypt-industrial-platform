<?php /** تغيير كلمة المرور من داخل الحساب | Change password. */ ?>
<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="np-card">
        <div class="np-card__header"><?= __e('auth.new_password') ?></div>
        <div class="np-card__body">
            <form method="post" action="<?= e(url('/app/account/password')) ?>" novalidate data-guard>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="current_password"><?= __e('auth.current_password') ?><span class="required" aria-hidden="true">*</span></label>
                    <input type="password" class="form-control<?= has_error('current_password') ? ' is-invalid' : '' ?>"
                           id="current_password" name="current_password" autocomplete="current-password" required dir="ltr">
                    <?php if (has_error('current_password')): ?><div class="invalid-feedback"><?= e(error_for('current_password')) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password"><?= __e('auth.new_password') ?><span class="required" aria-hidden="true">*</span></label>
                    <input type="password" class="form-control<?= has_error('password') ? ' is-invalid' : '' ?>"
                           id="password" name="password" autocomplete="new-password" required aria-describedby="pwHint" dir="ltr">
                    <?php if (has_error('password')): ?><div class="invalid-feedback"><?= e(error_for('password')) ?></div><?php endif; ?>
                    <div class="form-text" id="pwHint"><?= __e('auth.password_hint') ?></div>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="password_confirmation"><?= __e('auth.password_confirmation') ?><span class="required" aria-hidden="true">*</span></label>
                    <input type="password" class="form-control<?= has_error('password_confirmation') ? ' is-invalid' : '' ?>"
                           id="password_confirmation" name="password_confirmation" autocomplete="new-password" required dir="ltr">
                    <?php if (has_error('password_confirmation')): ?><div class="invalid-feedback"><?= e(error_for('password_confirmation')) ?></div><?php endif; ?>
                </div>
                <button type="submit" class="btn btn-primary"><?= __e('common.save') ?></button>
            </form>
        </div>
    </div>
  </div>
</div>
