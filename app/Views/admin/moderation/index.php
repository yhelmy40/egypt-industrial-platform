<?php
/**
 * قائمة مراجعة الإعلانات | Listing moderation queue (§3.1, §4.4).
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,string> $filters
 * @var array<string,int> $counts
 * @var \App\Services\ListingService $service
 */

$tabs = ['pending_review' => 'بانتظار المراجعة', 'published' => 'منشور',
         'rejected' => 'مرفوض', 'draft' => 'مسودة', 'archived' => 'مؤرشف'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-1">
        <?php foreach ($tabs as $value => $label): ?>
            <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= e(url('/admin/moderation?status=' . $value)) ?>">
                <?= e($label) ?>
                <span class="np-badge np-badge--muted ms-1"><?= e(number_ar($counts[$value] ?? 0)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/complaints')) ?>">الشكاوى والنزاعات ←</a>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($results['data'] === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">✅</div>
                <p class="mb-0">لا توجد إعلانات في هذه القائمة.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>الإعلان</th><th>المنشأة</th><th>التصنيف</th><th>السعر</th>
                        <th>الحالة</th><th>آخر تحديث</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($results['data'] as $listing): ?>
                            <tr>
                                <td>
                                    <a class="fw-bold" href="<?= e(url('/admin/moderation/' . $listing['id'])) ?>">
                                        <?= e($listing['name_ar']) ?></a>
                                    <div class="fs-xs text-muted-np">
                                        <?= $listing['listing_type'] === 'service' ? 'خدمة' : 'منتج' ?></div>
                                </td>
                                <td class="fs-sm">
                                    <?= e($listing['trading_name'] ?: $listing['legal_name']) ?></td>
                                <td class="fs-sm text-muted-np"><?= e($listing['category_name'] ?? '—') ?></td>
                                <td class="numeric fs-sm">
                                    <?= $listing['pricing_mode'] === 'quote'
                                        ? '<span class="np-badge np-badge--info">عرض سعر</span>'
                                        : e(money((float) $listing['price'])) ?>
                                </td>
                                <td><span class="np-badge <?= e($service->statusBadgeClass((string) $listing['status'])) ?>">
                                    <?= e($service->statusLabel((string) $listing['status'])) ?></span></td>
                                <td class="fs-xs text-muted-np"><?= e(time_ago($listing['updated_at'])) ?></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/admin/moderation/' . $listing['id'])) ?>">مراجعة</a>
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
    <nav class="d-flex justify-content-between align-items-center mt-3" aria-label="صفحات المراجعة">
        <span class="fs-sm text-muted-np">
            <?= e(__('common.page_of', ['current' => $results['page'], 'last' => $results['last_page']])) ?>
        </span>
        <div class="d-flex gap-2">
            <?php if ($results['page'] > 1): ?>
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= e(url('/admin/moderation?status=' . $filters['status'] . '&page=' . ($results['page'] - 1))) ?>">
                    <?= __e('common.previous') ?></a>
            <?php endif; ?>
            <?php if ($results['page'] < $results['last_page']): ?>
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= e(url('/admin/moderation?status=' . $filters['status'] . '&page=' . ($results['page'] + 1))) ?>">
                    <?= __e('common.next') ?></a>
            <?php endif; ?>
        </div>
    </nav>
<?php endif; ?>
