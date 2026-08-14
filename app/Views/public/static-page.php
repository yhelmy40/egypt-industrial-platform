<?php
/**
 * صفحة ثابتة | A platform static page (§4.11, §10).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * `body_ar` يُطبع **دون هروب** ليظهر بتنسيقه، وهذا مقصود وآمن لأن النصّ
 * **نُقّي عند الحفظ** بقائمة سماح في `HtmlSanitizer` — لا عند العرض. التنقية
 * عند العرض كانت ستجعل كل قالب جديد ينسى استدعاءها ثغرة.
 *
 * ولا يصل إلى هنا إلا محتوى **منشور** مرّ بمراجعة، ومُعرّفات الصفحات محصورة
 * في `ContentService::CORE_PAGES`.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @var array<string,mixed> $page
 */
?>
<section class="hero" style="padding:2.5rem 0">
    <div class="container">
        <h1><?= e($page['title_ar']) ?></h1>
        <p class="mb-0"><?= __e('portal.tagline') ?></p>
    </div>
</section>

<section class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <p class="text-muted-np fs-sm mb-3">
                النسخة <?= e((string) App\Services\SettingsService::get('general', 'policy_version', '1.0')) ?>
                <?php if ($page['published_at'] !== null): ?>
                    · آخر تحديث: <?= e(format_date((string) $page['published_at'])) ?>
                <?php endif; ?>
            </p>

            <?php if ((int) $page['needs_legal_review'] === 1): ?>
                <div class="alert alert-warning fs-sm" role="alert">
                    هذه صياغة أولية لأغراض التشغيل التجريبي، ويجب مراجعتها واعتمادها من
                    الجهة القانونية المختصة قبل الإطلاق الرسمي.
                </div>
            <?php endif; ?>

            <div class="np-card">
                <div class="np-card__body np-prose">
                    <?= $page['body_ar'] ?>
                </div>
            </div>
        </div>
    </div>
</section>
