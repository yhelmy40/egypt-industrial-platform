<?php /** صفحة التواصل | Contact page. */ ?>
<section class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <h1 class="h3 mb-4"><?= __e('portal.nav_contact') ?></h1>

            <div class="np-card">
                <div class="np-card__body">
                    <p class="text-muted-np">
                        لأي استفسار عن المنصة أو خدماتها، يمكنك التواصل معنا عبر البيانات التالية:
                    </p>
                    <dl class="mb-0">
                        <dt><?= __e('auth.email') ?></dt>
                        <dd dir="ltr"><?= e((string) config('app.operator.email')) ?></dd>
                        <?php if (config('app.operator.phone')): ?>
                            <dt class="mt-3"><?= __e('auth.phone') ?></dt>
                            <dd dir="ltr"><?= e((string) config('app.operator.phone')) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
                <div class="np-card__footer fs-sm text-muted-np">
                    نموذج التواصل المباشر سيُفعَّل ضمن مرحلة لاحقة من خطة التطوير.
                </div>
            </div>
        </div>
    </div>
</section>
