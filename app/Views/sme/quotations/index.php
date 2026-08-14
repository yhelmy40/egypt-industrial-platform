<?php
/**
 * طلبات عروض الأسعار | Quotation requests (§4.4).
 *
 * @var array<int,array<string,mixed>> $quotations
 * @var array<string,string> $filters
 * @var \App\Services\QuotationService $quotationService
 */

$tabs = ['' => 'الكل', 'requested' => 'بانتظار عرضك', 'quoted' => 'عرض مُرسَل',
         'accepted' => 'مقبول', 'rejected' => 'مرفوض', 'expired' => 'منتهي'];
?>
<div class="d-flex flex-wrap gap-1 mb-3">
    <?php foreach ($tabs as $value => $label): ?>
        <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
           href="<?= e(url('/app/quotations') . ($value === '' ? '' : '?status=' . $value)) ?>">
            <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($quotations === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">📝</div>
                <p class="mb-1">لا توجد طلبات عروض أسعار.</p>
                <p class="fs-sm mb-0">
                    الأصناف المسعَّرة بوضع «عرض سعر» تُظهر للعميل زر طلب عرض بدلاً من الشراء المباشر.
                </p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>الرقم</th><th>العميل</th><th>الصنف</th><th>الكمية</th>
                        <th>الإجمالي المعروض</th><th>الحالة</th><th>التاريخ</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($quotations as $quotation): ?>
                            <tr>
                                <td>
                                    <a class="fw-bold numeric" dir="ltr"
                                       href="<?= e(url('/app/quotations/' . $quotation['id'])) ?>">
                                        <?= e($quotation['quotation_number']) ?></a>
                                </td>
                                <td class="fs-sm"><?= e($quotation['customer_name']) ?></td>
                                <td class="fs-sm text-muted-np"><?= e($quotation['listing_name'] ?? '—') ?></td>
                                <td class="numeric fs-sm">
                                    <?= $quotation['requested_quantity'] !== null
                                        ? e(number_ar((float) $quotation['requested_quantity'], 0)) : '—' ?>
                                </td>
                                <td class="numeric fs-sm">
                                    <?= $quotation['total'] !== null
                                        ? e(money((float) $quotation['total'], (string) $quotation['currency_code']))
                                        : '—' ?>
                                </td>
                                <td>
                                    <span class="np-badge <?= e($quotationService->statusBadgeClass((string) $quotation['status'])) ?>">
                                        <?= e($quotationService->statusLabel((string) $quotation['status'])) ?></span>
                                </td>
                                <td class="fs-xs text-muted-np"><?= e(time_ago($quotation['created_at'])) ?></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/app/quotations/' . $quotation['id'])) ?>">فتح</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
