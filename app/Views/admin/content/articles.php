<?php
/**
 * مركز المعرفة — الإدارة | Knowledge-centre administration (§4.11).
 *
 * أزرار القرار لا تُعرض لمن لا يملك `content.item.publish`: محرّر يرى «إرسال
 * للمراجعة» ولا يرى «نشر».
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,int> $counts
 * @var array<string,string> $filters
 * @var bool $canPublish
 * @var \App\Services\ContentService $service
 */

$tabs = ['' => 'الكل', 'pending_review' => 'بانتظار النشر', 'draft' => 'مسودة',
         'published' => 'منشور', 'rejected' => 'مُعاد للتعديل', 'archived' => 'مؤرشف'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">مركز المعرفة</h1>
        <p class="fs-sm text-muted-np mb-0">
            الكتابة ليست النشر: المادة تُرسل للمراجعة ثم يقرّر من يملك صلاحية النشر.
        </p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('/admin/articles/new')) ?>">مقال جديد</a>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-1">
        <?php foreach ($tabs as $value => $label): ?>
            <?php $count = $value === '' ? array_sum($counts) : ($counts[$value] ?? 0); ?>
            <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= e(url('/admin/articles') . ($value === '' ? '' : '?status=' . $value)) ?>">
                <?= e($label) ?>
                <span class="np-badge np-badge--muted ms-1"><?= e(number_ar($count)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="<?= e(url('/admin/articles')) ?>" class="d-flex gap-2">
        <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        <label class="visually-hidden" for="q">بحث</label>
        <input type="search" class="form-control form-control-sm" id="q" name="q"
               value="<?= e($filters['q']) ?>" placeholder="عنوان المقال…" style="min-width:14rem">
        <button type="submit" class="btn btn-sm btn-outline-primary">بحث</button>
    </form>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($results['data'] === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">📚</div>
                <p class="mb-1">لا مواد مطابقة.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>العنوان</th><th>التصنيف</th><th>الحالة</th>
                        <th>النشر</th><th>المشاهدات</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($results['data'] as $article): ?>
                            <?php $status = (string) $article['status']; ?>
                            <tr>
                                <td>
                                    <a class="fw-bold fs-sm"
                                       href="<?= e(url('/admin/articles/' . $article['id'] . '/edit')) ?>">
                                        <?= e($article['title_ar']) ?></a>
                                    <?php if ((int) $article['is_featured'] === 1): ?>
                                        <span class="np-badge np-badge--info">مميّز</span>
                                    <?php endif; ?>
                                    <?php if ($status === 'rejected' && !empty($article['review_note_ar'])): ?>
                                        <div class="fs-xs text-danger">
                                            <?= e($article['review_note_ar']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="fs-sm"><?= e($article['category_name'] ?? '—') ?></td>
                                <td>
                                    <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
                                        <?= e($service->statusLabel($status)) ?></span>
                                </td>
                                <td class="fs-xs text-muted-np">
                                    <?php if ($article['published_at'] !== null): ?>
                                        <?= e(format_date((string) $article['published_at'])) ?>
                                        <?php if (!empty($article['publisher_name'])): ?>
                                            <div><?= e($article['publisher_name']) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td class="numeric fs-sm"><?= e(number_ar((int) $article['view_count'])) ?></td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="<?= e(url('/admin/articles/' . $article['id'] . '/edit')) ?>">تحرير</a>

                                        <?php if ($status === 'published'): ?>
                                            <a class="btn btn-sm btn-outline-secondary" target="_blank"
                                               rel="noopener"
                                               href="<?= e(url('/knowledge/' . $article['slug'])) ?>">معاينة</a>
                                        <?php endif; ?>

                                        <?php if ($canPublish && $status === 'pending_review'): ?>
                                            <form method="post"
                                                  action="<?= e(url('/admin/articles/' . $article['id'] . '/decide')) ?>"
                                                  class="d-flex gap-1">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="decision" value="published">
                                                <button type="submit" class="btn btn-sm btn-primary">نشر</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($results['last_page'] > 1): ?>
    <nav class="mt-3" aria-label="تصفّح الصفحات">
        <ul class="pagination pagination-sm justify-content-center mb-0">
            <?php for ($page = 1; $page <= $results['last_page']; $page++): ?>
                <li class="page-item <?= $page === $results['page'] ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e(url('/admin/articles?page=' . $page)) ?>">
                        <?= e(number_ar($page)) ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
