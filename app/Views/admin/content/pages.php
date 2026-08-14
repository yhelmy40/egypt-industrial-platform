<?php
/**
 * الصفحات الثابتة | Static pages (§4.11).
 *
 * الصفحات تُحرَّر ولا تُنشأ ولا تُحذف: مفاتيحها يعرفها الكود، وحذف صفحة الشروط
 * يكسر رابطاً في تذييل كل صفحة في المنصة.
 *
 * @var array<int,array<string,mixed>> $pages
 * @var bool $canPublish
 * @var \App\Services\ContentService $service
 */
?>
<div class="mb-3">
    <h1 class="h4 mb-1">الصفحات الثابتة</h1>
    <p class="fs-sm text-muted-np mb-0">
        صفحات المنصة التعريفية والقانونية. تُحرَّر ولا تُحذف، لأن روابطها ثابتة في
        تذييل كل صفحة.
    </p>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <div class="table-scroll" style="border:0">
            <table class="np-table">
                <thead><tr>
                    <th>الصفحة</th><th>المُعرّف</th><th>الحالة</th>
                    <th>المراجعة القانونية</th><th>آخر تحديث</th><th></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($pages as $page): ?>
                        <?php $status = (string) $page['status']; ?>
                        <tr>
                            <td class="fw-bold fs-sm"><?= e($page['title_ar']) ?></td>
                            <td class="numeric fs-sm" dir="ltr">/<?= e($page['slug']) ?></td>
                            <td>
                                <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
                                    <?= e($service->statusLabel($status)) ?></span>
                            </td>
                            <td>
                                <?php if ((int) $page['needs_legal_review'] === 1): ?>
                                    <span class="np-badge np-badge--warning">مطلوبة</span>
                                <?php else: ?>
                                    <span class="np-badge np-badge--success">تمّت</span>
                                <?php endif; ?>
                            </td>
                            <td class="fs-xs text-muted-np">
                                <?= e(format_date((string) $page['updated_at'], true)) ?>
                                <?php if (!empty($page['editor_name'])): ?>
                                    <div><?= e($page['editor_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/admin/pages/' . $page['slug'])) ?>">تحرير</a>
                                    <?php if ($status === 'published'): ?>
                                        <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                                           href="<?= e(url('/' . $page['slug'])) ?>">معاينة</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="alert alert-info fs-sm mt-3" role="alert">
    صياغات الشروط والخصوصية <strong>أولية</strong> وتحتاج مراجعة قانونية قبل الإطلاق
    الرسمي. أزل وسم «المراجعة القانونية مطلوبة» من الصفحة بعد اعتمادها من الجهة المختصة،
    ليختفي التنويه المعروض للزائر.
</div>
