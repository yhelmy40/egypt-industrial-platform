<?php
/**
 * قائمة طلبات الخدمة | Service request list — shared by both sides (§4.6).
 *
 * @var array<int,array<string,mixed>> $requests
 * @var array<string,int> $counts
 * @var array<string,string> $filters
 * @var \App\Services\ServiceRequestService $service
 * @var string $basePath
 * @var string $counterpartLabel
 * @var string $emptyMessage
 */
$tabs = ['' => 'الكل', 'submitted' => 'جديد', 'provider_review' => 'قيد الدراسة',
         'proposed' => 'عرض مُقدَّم', 'accepted' => 'مقبول', 'in_progress' => 'قيد التنفيذ',
         'delivered' => 'تم التسليم', 'completed' => 'مكتمل'];
?>
<div class="d-flex flex-wrap gap-1 mb-3">
    <?php foreach ($tabs as $value => $label): ?>
        <?php $count = $value === '' ? array_sum($counts) : ($counts[$value] ?? 0); ?>
        <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
           href="<?= e(url($basePath) . ($value === '' ? '' : '?status=' . $value)) ?>">
            <?= e($label) ?> <span class="np-badge np-badge--muted ms-1"><?= e(number_ar($count)) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($requests === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">🤝</div>
                <p class="mb-0"><?= e($emptyMessage) ?></p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>رقم الطلب</th><th>الخدمة</th><th><?= e($counterpartLabel) ?></th>
                        <th>قيمة العرض</th><th>الحالة</th><th>التاريخ</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($requests as $row): ?>
                            <tr>
                                <td>
                                    <a class="fw-bold numeric" dir="ltr"
                                       href="<?= e(url($basePath . '/' . $row['id'])) ?>">
                                        <?= e($row['request_number']) ?></a>
                                </td>
                                <td class="fs-sm"><?= e($row['offering_name_ar']) ?></td>
                                <td class="fs-sm text-muted-np">
                                    <?= e($row['counterpart_trading_name'] ?: $row['counterpart_legal_name']) ?></td>
                                <td class="numeric fs-sm">
                                    <?php if ($row['proposed_price'] === null): ?>
                                        —
                                    <?php elseif ((float) $row['proposed_price'] === 0.0): ?>
                                        مجاناً
                                    <?php else: ?>
                                        <?= e(money((float) $row['proposed_price'])) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="np-badge <?= e($service->statusBadgeClass((string) $row['status'])) ?>">
                                        <?= e($service->statusLabel((string) $row['status'])) ?></span>
                                </td>
                                <td class="fs-xs text-muted-np"><?= e(time_ago($row['created_at'])) ?></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url($basePath . '/' . $row['id'])) ?>">فتح</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
