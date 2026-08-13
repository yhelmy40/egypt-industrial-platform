<?php
/**
 * صفحة الخطأ | Error page.
 *
 * في الإنتاج تُعرض رسالة عربية عامة فقط؛ التفاصيل التقنية تظهر في وضع التطوير
 * حصراً ولا تصل للمستخدم النهائي أبداً (§9).
 *
 * @var int       $status
 * @var string    $message
 * @var bool      $debug
 * @var Throwable $exception
 */
?>
<section class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="np-card text-center">
                <div class="np-card__body py-5">
                    <div class="stat-value" style="font-size:3.5rem"><?= e((string) $status) ?></div>
                    <p class="fs-5 mb-4"><?= e($message) ?></p>

                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                        <a class="btn btn-primary" href="<?= e(url('/')) ?>"><?= __e('common.home') ?></a>
                        <?php if ($status === 401 || $status === 403): ?>
                            <a class="btn btn-outline-primary" href="<?= e(url('/auth/login')) ?>">
                                <?= __e('auth.login_action') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($debug && isset($exception)): ?>
                    <div class="np-card__footer text-start" dir="ltr">
                        <p class="fw-bold mb-1 fs-sm">
                            <?= e($exception::class) ?>: <?= e($exception->getMessage()) ?>
                        </p>
                        <p class="fs-xs text-muted-np mb-2">
                            <?= e($exception->getFile()) ?>:<?= e((string) $exception->getLine()) ?>
                        </p>
                        <pre class="fs-xs mb-0" style="white-space:pre-wrap;max-height:18rem;overflow:auto"><?= e($exception->getTraceAsString()) ?></pre>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
