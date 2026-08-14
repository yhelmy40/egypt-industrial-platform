<?php
/**
 * فرز طلبات التمويل | Financing application screening queue (§4.5).
 *
 * @var array<int,array<string,mixed>> $applications
 * @var array<string,int> $counts
 * @var array<string,string> $filters
 * @var \App\Services\FinancingApplicationService $service
 */
$tabs = ['submitted' => 'بانتظار الفرز', 'screening' => 'قيد الفرز', 'forwarded' => 'محال',
         'provider_review' => 'قيد دراسة المؤسسة', 'info_requested' => 'بانتظار مستندات',
         'approved' => 'مقبول', 'rejected' => 'مرفوض', '' => 'الكل'];
?>
<div class="d-flex flex-wrap gap-1 mb-3">
    <?php foreach ($tabs as $value => $label): ?>
        <?php $count = $value === '' ? array_sum($counts) : ($counts[$value] ?? 0); ?>
        <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
           href="<?= e(url('/admin/finance/applications?status=' . $value)) ?>">
            <?= e($label) ?> <span class="np-badge np-badge--muted ms-1"><?= e(number_ar($count)) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($applications === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">🗂️</div>
                <p class="mb-0">لا توجد طلبات في هذه القائمة.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>رقم الطلب</th><th>المشروع</th><th>المؤسسة</th><th>المبلغ</th>
                        <th>الحالة</th><th>التاريخ</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($applications as $application): ?>
                            <tr>
                                <td>
                                    <a class="fw-bold numeric" dir="ltr"
                                       href="<?= e(url('/admin/finance/applications/' . $application['id'])) ?>">
                                        <?= e($application['application_number']) ?></a>
                                </td>
                                <td class="fs-sm">
                                    <?= e($application['applicant_trading_name']
                                        ?: $application['applicant_legal_name']) ?>
                                    <?php if (!empty($application['governorate_name'])): ?>
                                        <div class="fs-xs text-muted-np">
                                            <?= e($application['governorate_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="fs-sm text-muted-np">
                                    <?= e($application['provider_trading_name']
                                        ?: $application['provider_legal_name']) ?></td>
                                <td class="numeric fs-sm">
                                    <?= e(money((float) $application['requested_amount'])) ?></td>
                                <td>
                                    <span class="np-badge <?= e($service->statusBadgeClass((string) $application['status'])) ?>">
                                        <?= e($service->statusLabel((string) $application['status'])) ?></span>
                                </td>
                                <td class="fs-xs text-muted-np"><?= e(time_ago($application['created_at'])) ?></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/admin/finance/applications/' . $application['id'])) ?>">فتح</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-info fs-sm mt-3 mb-0">
    <strong>دور المنصة هنا الفرز والإحالة فقط.</strong>
    لا يوجد في هذه الشاشة إجراء قبول أو رفض: القرار الائتماني من اختصاص المؤسسة المالية
    وحدها، ويُسجَّل باسم موظّفها المسؤول.
</div>
