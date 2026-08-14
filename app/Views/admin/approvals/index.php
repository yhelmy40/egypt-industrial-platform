<?php
/**
 * طابور الاعتماد | The approval queue (§4.5, §4.6).
 *
 * @var string $kind
 * @var string $basePath
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,int> $counts
 * @var array<string,string> $filters
 * @var \App\Services\ApprovableCatalogService $service
 */
$tabs = ['pending_review' => 'بانتظار الاعتماد', 'published' => 'معتمد',
         'rejected' => 'مرفوض', 'draft' => 'مسودة', 'archived' => 'مسحوب'];
$isFinancing = $kind === 'financing';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-1">
        <?php foreach ($tabs as $value => $label): ?>
            <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= e(url($basePath . '?status=' . $value)) ?>">
                <?= e($label) ?>
                <span class="np-badge np-badge--muted ms-1"><?= e(number_ar($counts[$value] ?? 0)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <a class="btn btn-sm btn-outline-primary"
       href="<?= e(url($isFinancing ? '/admin/services/offerings' : '/admin/finance/products')) ?>">
        <?= $isFinancing ? 'اعتماد باقات الخدمات ←' : 'اعتماد المنتجات التمويلية ←' ?></a>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($results['data'] === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">✅</div>
                <p class="mb-0">لا توجد عناصر في هذه القائمة.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th><?= $isFinancing ? 'المنتج' : 'الباقة' ?></th>
                        <th>الجهة</th>
                        <th><?= $isFinancing ? 'الشريحة' : 'التسعير' ?></th>
                        <th>الحالة</th><th>آخر تحديث</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($results['data'] as $item): ?>
                            <tr>
                                <td>
                                    <a class="fw-bold" href="<?= e(url($basePath . '/' . $item['id'])) ?>">
                                        <?= e($item['name_ar']) ?></a>
                                    <?php if (!empty($item['is_demo'])): ?>
                                        <span class="np-demo-tag"><?= __e('common.demo_data') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="fs-sm">
                                    <?= e($item['trading_name'] ?: $item['legal_name']) ?>
                                    <?php if ($item['provider_status'] !== 'verified'): ?>
                                        <div><span class="np-badge np-badge--danger">جهة غير موثّقة</span></div>
                                    <?php endif; ?>
                                </td>
                                <td class="numeric fs-sm">
                                    <?php if ($isFinancing): ?>
                                        <?= $item['min_amount'] === null ? '—'
                                            : e(money((float) $item['min_amount']) . ' – '
                                                . money((float) $item['max_amount'])) ?>
                                    <?php else: ?>
                                        <?= e($service->priceLabel($item)) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="np-badge <?= e($service->statusBadgeClass((string) $item['status'])) ?>">
                                        <?= e($service->statusLabel((string) $item['status'])) ?></span>
                                </td>
                                <td class="fs-xs text-muted-np"><?= e(time_ago($item['updated_at'])) ?></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url($basePath . '/' . $item['id'])) ?>">مراجعة</a>
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
    <nav class="d-flex justify-content-between align-items-center mt-3" aria-label="صفحات الطابور">
        <span class="fs-sm text-muted-np">
            <?= e(__('common.page_of', ['current' => $results['page'], 'last' => $results['last_page']])) ?>
        </span>
        <div class="d-flex gap-2">
            <?php if ($results['page'] > 1): ?>
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= e(url($basePath . '?status=' . $filters['status'] . '&page=' . ($results['page'] - 1))) ?>">
                    <?= __e('common.previous') ?></a>
            <?php endif; ?>
            <?php if ($results['page'] < $results['last_page']): ?>
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= e(url($basePath . '?status=' . $filters['status'] . '&page=' . ($results['page'] + 1))) ?>">
                    <?= __e('common.next') ?></a>
            <?php endif; ?>
        </div>
    </nav>
<?php endif; ?>
