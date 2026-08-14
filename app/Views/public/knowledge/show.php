<?php
/**
 * مقال | A knowledge-centre article (§4.11).
 *
 * `body_ar` يُطبع دون هروب لأنه **نُقّي عند الحفظ** بقائمة سماح في
 * `HtmlSanitizer`، ولا يصل هنا إلا محتوى منشور مرّ بمراجعة.
 *
 * @var array<string,mixed> $article
 * @var array<int,array<string,mixed>> $related
 * @var \App\Services\ContentService $service
 */
?>
<section class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <nav aria-label="مسار التصفّح" class="fs-sm mb-3">
                <a href="<?= e(url('/knowledge')) ?>">مركز المعرفة</a>
                <?php if (!empty($article['category_name'])): ?>
                    <span aria-hidden="true">›</span>
                    <a href="<?= e(url('/knowledge?category=' . $article['category_code'])) ?>">
                        <?= e($article['category_name']) ?></a>
                <?php endif; ?>
            </nav>

            <h1 class="h3 mb-2"><?= e($article['title_ar']) ?></h1>

            <p class="text-muted-np fs-sm mb-4">
                <?= e(format_date((string) $article['published_at'])) ?>
                <?php if (!empty($article['author_name_ar'])): ?>
                    · <?= e($article['author_name_ar']) ?>
                <?php endif; ?>
                <?php if ($article['reading_minutes'] !== null): ?>
                    · <?= e(number_ar((int) $article['reading_minutes'])) ?> دقائق قراءة
                <?php endif; ?>
            </p>

            <?php if ((int) $article['is_demo'] === 1): ?>
                <div class="alert alert-warning fs-sm" role="alert">
                    هذه مادة <strong>تجريبية</strong> أُضيفت لعرض المنصة، ولا تمثّل إرشاداً معتمداً.
                </div>
            <?php endif; ?>

            <div class="np-card mb-4">
                <div class="np-card__body np-prose">
                    <?= $article['body_ar'] ?>
                </div>
            </div>

            <div class="alert alert-info fs-sm" role="alert">
                هذه المادة إرشادية عامة، ولا تُغني عن استشارة مختصّ في حالتك تحديداً.
                أي أرقام أو شروط تمويل تُذكر هنا للتوضيح فقط، والشروط الفعلية تحدّدها
                الجهة المموّلة.
            </div>

            <?php if ($related !== []): ?>
                <h2 class="h6 mt-4 mb-3">مواد ذات صلة</h2>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($related as $item): ?>
                        <li class="mb-2">
                            <a class="fw-bold fs-sm" href="<?= e(url('/knowledge/' . $item['slug'])) ?>">
                                <?= e($item['title_ar']) ?></a>
                            <div class="fs-xs text-muted-np"><?= e($item['excerpt_ar']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</section>
