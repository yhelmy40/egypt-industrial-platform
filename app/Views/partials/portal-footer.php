<?php
/**
 * تذييل البوابة | Public portal footer.
 * يتضمّن التعريف المؤسسي وروابط السياسات — دون أي ادعاء اعتماد رسمي إضافي (§2).
 */
?>
<footer class="portal-footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <h5><?= __e('portal.platform_full') ?></h5>
                <p class="fs-sm mb-2"><?= __e('portal.tagline') ?></p>
                <p class="fs-xs mb-0" style="opacity:.6">
                    الجهة المشغّلة: <?= e((string) config('app.operator.name_ar')) ?>
                </p>
            </div>

            <div class="col-6 col-lg-2">
                <h5><?= __e('portal.footer_about') ?></h5>
                <ul class="list-unstyled d-grid gap-2 mb-0">
                    <li><a href="<?= e(url('/about')) ?>"><?= __e('portal.nav_about') ?></a></li>
                    <li><a href="<?= e(url('/contact')) ?>"><?= __e('portal.nav_contact') ?></a></li>
                </ul>
            </div>

            <div class="col-6 col-lg-3">
                <h5><?= __e('portal.footer_services') ?></h5>
                <ul class="list-unstyled d-grid gap-2 mb-0">
                    <li><span style="opacity:.5"><?= __e('portal.nav_marketplace') ?> — قريباً</span></li>
                    <li><span style="opacity:.5"><?= __e('portal.nav_financing') ?> — قريباً</span></li>
                    <li><span style="opacity:.5"><?= __e('portal.nav_non_financial') ?> — قريباً</span></li>
                    <li><span style="opacity:.5"><?= __e('portal.nav_bds') ?> — قريباً</span></li>
                </ul>
            </div>

            <div class="col-lg-3">
                <h5><?= __e('portal.footer_legal') ?></h5>
                <ul class="list-unstyled d-grid gap-2 mb-0">
                    <li><a href="<?= e(url('/terms')) ?>"><?= __e('portal.footer_terms') ?></a></li>
                    <li><a href="<?= e(url('/privacy')) ?>"><?= __e('portal.footer_privacy') ?></a></li>
                </ul>
            </div>
        </div>

        <div class="portal-footer__bottom d-flex flex-wrap justify-content-between gap-2">
            <span>© <?= e(date('Y')) ?> <?= e((string) config('app.operator.name_ar')) ?> — <?= __e('portal.footer_rights') ?></span>
            <span style="opacity:.6">الإصدار <?= e((string) config('app.version')) ?></span>
        </div>
    </div>
</footer>
