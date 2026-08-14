<?php
/**
 * مركز المعرفة | The knowledge centre (§4.11).
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<int,array<string,mixed>> $categories
 * @var array<string,string> $filters
 * @var \App\Services\ContentService $service
 */
?>
<section class="hero" style="padding:2.5rem 0">
    <div class="container">
        <h1>مركز المعرفة</h1>
        <p class="mb-0">أدلة إرشادية عملية لأصحاب المشروعات الصغيرة والمتوسطة.</p>
    </div>
</section>

<section class="container py-5">
    <form method="get" action="<?= e(url('/knowledge')) ?>" class="row g-2 align-items-end mb-4">
        <div class="col-md-5">
            <label class="form-label fs-sm" for="q">بحث</label>
            <input type="search" class="form-control" id="q" name="q"
                   value="<?= e($filters['q']) ?>" placeholder="ابحث في المقالات…">
        </div>
        <div class="col-md-4">
            <label class="form-label fs-sm" for="category">التصنيف</label>
            <select class="form-select" id="category" name="category">
                <option value="">كل التصنيفات</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= e($category['code']) ?>"
                        <?= $filters['category'] === $category['code'] ? 'selected' : '' ?>>
                        <?= e($category['name_ar']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary w-100">بحث</button>
        </div>
    </form>

    <?php if ($results['data'] === []): ?>
        <div class="np-empty">
            <div class="np-empty__icon" aria-hidden="true">📚</div>
            <p class="mb-1">لا توجد مقالات مطابقة.</p>
            <p class="fs-sm mb-0">جرّب كلمة بحث أخرى أو تصفّح كل التصنيفات.</p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($results['data'] as $article): ?>
                <div class="col-md-6 col-lg-4">
                    <article class="np-card h-100">
                        <div class="np-card__body d-flex flex-column">
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <?php if (!empty($article['category_name'])): ?>
                                    <span class="np-badge np-badge--info"><?= e($article['category_name']) ?></span>
                                <?php endif; ?>
                                <?php if ((int) $article['is_demo'] === 1): ?>
                                    <span class="np-badge np-badge--muted">بيانات تجريبية</span>
                                <?php endif; ?>
                            </div>

                            <h2 class="h6 mb-2">
                                <a href="<?= e(url('/knowledge/' . $article['slug'])) ?>">
                                    <?= e($article['title_ar']) ?></a>
                            </h2>

                            <p class="fs-sm text-muted-np flex-grow-1"><?= e($article['excerpt_ar']) ?></p>

                            <div class="d-flex justify-content-between fs-xs text-muted-np">
                                <span><?= e(format_date((string) $article['published_at'])) ?></span>
                                <?php if ($article['reading_minutes'] !== null): ?>
                                    <span><?= e(number_ar((int) $article['reading_minutes'])) ?> دقائق قراءة</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($results['last_page'] > 1): ?>
        <nav class="mt-4" aria-label="تصفّح الصفحات">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php for ($page = 1; $page <= $results['last_page']; $page++): ?>
                    <li class="page-item <?= $page === $results['page'] ? 'active' : '' ?>">
                        <a class="page-link"
                           href="<?= e(url('/knowledge?page=' . $page
                                . ($filters['category'] !== '' ? '&category=' . $filters['category'] : ''))) ?>">
                            <?= e(number_ar($page)) ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <p class="text-center fs-sm text-muted-np mt-4 mb-0">
        لديك سؤال سريع؟ راجع <a href="<?= e(url('/faq')) ?>">الأسئلة الشائعة</a>.
    </p>
</section>
