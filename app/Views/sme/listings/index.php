<?php
/** المنتجات والخدمات | Seller listing management (§4.4). */
$tabs = ['' => 'الكل', 'draft' => 'مسودة', 'pending_review' => 'بانتظار المراجعة',
         'published' => 'منشور', 'rejected' => 'مرفوض', 'archived' => 'مؤرشف'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($tabs as $value => $label): ?>
            <?php $count = $value === '' ? array_sum($counts) : ($counts[$value] ?? 0); ?>
            <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= e(url('/app/listings') . ($value === '' ? '' : '?status=' . $value)) ?>">
                <?= e($label) ?> <span class="np-badge np-badge--muted ms-1"><?= e(number_ar($count)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <a class="btn btn-primary" href="<?= e(url('/app/listings/new')) ?>">+ إضافة صنف</a>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($results['data'] === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">📦</div>
                <p class="mb-3">لا توجد أصناف بعد.</p>
                <a class="btn btn-primary" href="<?= e(url('/app/listings/new')) ?>">إضافة أول صنف</a>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>الصنف</th><th>النوع</th><th>السعر</th><th>المخزون</th>
                        <th>الحالة</th><th>الطلبات</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($results['data'] as $listing): ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('/app/listings/' . $listing['id'])) ?>" class="fw-bold">
                                        <?= e($listing['name_ar']) ?></a>
                                    <?php if (!empty($listing['sku'])): ?>
                                        <div class="fs-xs text-muted-np" dir="ltr"><?= e($listing['sku']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="fs-sm text-muted-np">
                                    <?= $listing['listing_type'] === 'service' ? 'خدمة' : 'منتج' ?></td>
                                <td class="numeric fs-sm">
                                    <?= $listing['pricing_mode'] === 'quote'
                                        ? '<span class="np-badge np-badge--info">عرض سعر</span>'
                                        : e(money((float) $listing['price'])) ?>
                                </td>
                                <td class="numeric fs-sm">
                                    <?= (int) $listing['track_inventory'] === 1
                                        ? e(number_ar((float) $listing['available_quantity'], 0)) : '—' ?>
                                </td>
                                <td><span class="np-badge <?= e($service->statusBadgeClass((string) $listing['status'])) ?>">
                                    <?= e($service->statusLabel((string) $listing['status'])) ?></span></td>
                                <td class="numeric fs-sm"><?= e(number_ar((int) $listing['order_count'])) ?></td>
                                <td class="d-flex gap-1">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/app/listings/' . $listing['id'])) ?>">تعديل</a>
                                    <?php if ($listing['status'] === 'published'): ?>
                                        <a class="btn btn-sm btn-link" target="_blank" rel="noopener"
                                           href="<?= e(url('/marketplace/' . $listing['slug'])) ?>">معاينة</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
