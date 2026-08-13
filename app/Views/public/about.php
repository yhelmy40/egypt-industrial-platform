<?php
/**
 * صفحة عن المبادرة | About page (§2).
 * المحتوى المؤسسي مطابق للسياق المُعطى دون إضافات أو ادعاءات.
 * @var array<int,string> $objectives
 */
?>
<section class="hero" style="padding:3rem 0">
    <div class="container">
        <h1><?= __e('portal.about_title') ?></h1>
        <p class="mb-0"><?= __e('portal.tagline') ?></p>
    </div>
</section>

<section class="container py-5">
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="np-card">
                <div class="np-card__body">
                    <h2 class="h4 mb-3">نبذة عن المبادرة</h2>
                    <p class="mb-0"><?= __e('portal.about_intro') ?></p>
                </div>
            </div>

            <div class="np-card mt-4">
                <div class="np-card__header"><?= __e('portal.bds_title') ?></div>
                <div class="np-card__body">
                    <p class="mb-0"><?= __e('portal.bds_intro') ?></p>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="np-card h-100">
                <div class="np-card__header"><?= __e('portal.objectives_title') ?></div>
                <div class="np-card__body">
                    <ol class="mb-0 ps-3 fs-sm" style="line-height:2">
                        <?php foreach ($objectives as $objective): ?>
                            <li><?= e($objective) ?></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</section>
