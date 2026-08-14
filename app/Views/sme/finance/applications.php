<?php
/**
 * طلبات التمويل | The applicant's financing applications (§4.5).
 *
 * @var array<int,array<string,mixed>> $applications
 * @var array<string,int> $counts
 * @var array<string,string> $filters
 * @var \App\Services\FinancingApplicationService $service
 */
$tabs = ['' => 'الكل', 'submitted' => 'مُقدَّم', 'screening' => 'قيد الفرز',
         'forwarded' => 'محال', 'provider_review' => 'قيد الدراسة',
         'info_requested' => 'بانتظار مستندات', 'approved' => 'مقبول', 'rejected' => 'مرفوض'];
?>
<div class="d-flex flex-wrap gap-1 mb-3">
    <?php foreach ($tabs as $value => $label): ?>
        <?php $count = $value === '' ? array_sum($counts) : ($counts[$value] ?? 0); ?>
        <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
           href="<?= e(url('/app/finance/applications') . ($value === '' ? '' : '?status=' . $value)) ?>">
            <?= e($label) ?> <span class="np-badge np-badge--muted ms-1"><?= e(number_ar($count)) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($applications === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">📋</div>
                <p class="mb-3">لا توجد طلبات تمويل.</p>
                <a class="btn btn-primary" href="<?= e(url('/app/finance/opportunities')) ?>">
                    تصفّح فرص التمويل</a>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>رقم الطلب</th><th>المنتج</th><th>المؤسسة</th><th>المبلغ</th>
                        <th>الحالة</th><th>التاريخ</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($applications as $application): ?>
                            <tr>
                                <td>
                                    <a class="fw-bold numeric" dir="ltr"
                                       href="<?= e(url('/app/finance/applications/' . $application['id'])) ?>">
                                        <?= e($application['application_number']) ?></a>
                                </td>
                                <td class="fs-sm"><?= e($application['product_name_ar']) ?></td>
                                <td class="fs-sm text-muted-np">
                                    <?= e($application['provider_trading_name'] ?: $application['provider_legal_name']) ?>
                                </td>
                                <td class="numeric fs-sm">
                                    <?= e(money((float) $application['requested_amount'],
                                        (string) $application['currency_code'])) ?>
                                    <?php if ($application['approved_amount'] !== null): ?>
                                        <div class="fs-xs text-muted-np">
                                            المعتمد: <?= e(money((float) $application['approved_amount'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="np-badge <?= e($service->statusBadgeClass((string) $application['status'])) ?>">
                                        <?= e($service->statusLabel((string) $application['status'])) ?></span>
                                </td>
                                <td class="fs-xs text-muted-np"><?= e(format_date($application['created_at'])) ?></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/app/finance/applications/' . $application['id'])) ?>">فتح</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<p class="fs-xs text-muted-np mt-3">
    حالة «مقبول» تعني أن المؤسسة المالية سجّلت موافقتها. المنصة لا تسجّل موافقة نيابةً عن أي جهة.
</p>
