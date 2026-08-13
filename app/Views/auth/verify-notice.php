<?php /** إشعار تأكيد البريد | Verification notice. */ ?>
<div class="text-center">
    <div class="intent-card__icon mx-auto mb-3" style="width:64px;height:64px;font-size:1.8rem">✉️</div>
    <h1 class="h3 mb-3"><?= __e('auth.verify_title') ?></h1>
    <p class="text-muted-np mb-4"><?= __e('auth.verify_pending_notice') ?></p>

    <?php if (config('app.env') !== 'production' && config('mail.driver', 'log') === 'log'): ?>
        <div class="alert alert-info fs-sm text-start" role="alert">
            <strong>ملاحظة للمطوّر:</strong> لم يُضبط مزوّد بريد بعد، لذا تُكتب الرسائل في
            <code dir="ltr">storage/logs/mail-outbox.log</code> بدلاً من إرسالها فعلياً.
        </div>
    <?php endif; ?>

    <a class="btn btn-outline-primary" href="<?= e(url('/auth/login')) ?>"><?= __e('auth.back_to_login') ?></a>
</div>
