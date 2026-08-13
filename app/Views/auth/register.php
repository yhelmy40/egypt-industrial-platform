<?php
/**
 * صفحة إنشاء الحساب | Registration page.
 * تسجيل المنشأة يأتي في خطوة تالية (المرحلة الثانية).
 */
?>
<h1 class="h3 mb-1"><?= __e('auth.register_title') ?></h1>
<p class="text-muted-np mb-4"><?= __e('auth.register_subtitle') ?></p>

<form method="post" action="<?= e(url('/auth/register')) ?>" novalidate data-guard>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label class="form-label" for="name">
            <?= __e('auth.name') ?><span class="required" aria-hidden="true">*</span>
        </label>
        <input type="text" class="form-control<?= has_error('name') ? ' is-invalid' : '' ?>"
               id="name" name="name" value="<?= e(old('name')) ?>"
               autocomplete="name" required autofocus maxlength="150">
        <?php if (has_error('name')): ?>
            <div class="invalid-feedback"><?= e(error_for('name')) ?></div>
        <?php endif; ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="email">
            <?= __e('auth.email') ?><span class="required" aria-hidden="true">*</span>
        </label>
        <input type="email" class="form-control<?= has_error('email') ? ' is-invalid' : '' ?>"
               id="email" name="email" value="<?= e(old('email')) ?>"
               autocomplete="email" required maxlength="190" dir="ltr">
        <?php if (has_error('email')): ?>
            <div class="invalid-feedback"><?= e(error_for('email')) ?></div>
        <?php endif; ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="phone">
            <?= __e('auth.phone') ?>
            <span class="text-muted-np fw-normal fs-xs">(<?= __e('common.optional') ?>)</span>
        </label>
        <input type="tel" class="form-control<?= has_error('phone') ? ' is-invalid' : '' ?>"
               id="phone" name="phone" value="<?= e(old('phone')) ?>"
               autocomplete="tel" placeholder="01012345678" dir="ltr">
        <?php if (has_error('phone')): ?>
            <div class="invalid-feedback"><?= e(error_for('phone')) ?></div>
        <?php endif; ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="password">
            <?= __e('auth.password') ?><span class="required" aria-hidden="true">*</span>
        </label>
        <input type="password" class="form-control<?= has_error('password') ? ' is-invalid' : '' ?>"
               id="password" name="password" autocomplete="new-password"
               required aria-describedby="passwordHint" dir="ltr">
        <?php if (has_error('password')): ?>
            <div class="invalid-feedback"><?= e(error_for('password')) ?></div>
        <?php endif; ?>
        <div class="form-text" id="passwordHint"><?= __e('auth.password_hint') ?></div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="password_confirmation">
            <?= __e('auth.password_confirmation') ?><span class="required" aria-hidden="true">*</span>
        </label>
        <input type="password" class="form-control<?= has_error('password_confirmation') ? ' is-invalid' : '' ?>"
               id="password_confirmation" name="password_confirmation"
               autocomplete="new-password" required dir="ltr">
        <?php if (has_error('password_confirmation')): ?>
            <div class="invalid-feedback"><?= e(error_for('password_confirmation')) ?></div>
        <?php endif; ?>
    </div>

    <div class="mb-4 form-check">
        <input type="checkbox" class="form-check-input<?= has_error('accept_terms') ? ' is-invalid' : '' ?>"
               id="accept_terms" name="accept_terms" value="1" required>
        <label class="form-check-label fs-sm" for="accept_terms">
            أوافق على
            <a href="<?= e(url('/terms')) ?>" target="_blank" rel="noopener"><?= __e('auth.terms_link') ?></a>
            و<a href="<?= e(url('/privacy')) ?>" target="_blank" rel="noopener"><?= __e('auth.privacy_link') ?></a>
            <span class="text-muted-np">(النسخة <?= e((string) ($policyVersion ?? '1.0')) ?>)</span>
        </label>
        <?php if (has_error('accept_terms')): ?>
            <div class="invalid-feedback"><?= e(error_for('accept_terms')) ?></div>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary w-100 mb-3" data-busy-label="جارٍ إنشاء الحساب…">
        <?= __e('auth.register_action') ?>
    </button>

    <p class="text-center text-muted-np fs-sm mb-0">
        <?= __e('auth.have_account') ?>
        <a href="<?= e(url('/auth/login')) ?>"><?= __e('auth.login_action') ?></a>
    </p>
</form>
