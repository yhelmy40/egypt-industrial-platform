<?php
/**
 * قائمة حالات الدعم | BDS case list — shared by both sides (§4.8).
 *
 * @var array<int,array<string,mixed>> $cases
 * @var array<string,int> $counts
 * @var array<string,string> $filters
 * @var \App\Services\BdsCaseService $service
 * @var string $basePath
 * @var string $counterpartLabel
 * @var string $emptyMessage
 * @var bool $showSpecialist
 */
$tabs = ['' => 'الكل', 'requested' => 'طلب جديد', 'triage' => 'قيد الفرز',
         'assigned' => 'مُسندة', 'in_progress' => 'قيد المتابعة', 'on_hold' => 'موقوفة',
         'closed_completed' => 'مكتملة'];
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
        <?php if ($cases === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">🎓</div>
                <p class="mb-0"><?= e($emptyMessage) ?></p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>رقم الحالة</th><th>الموضوع</th><th><?= e($counterpartLabel) ?></th>
                        <?php if ($showSpecialist): ?><th>الأخصائي</th><?php endif; ?>
                        <th>الوضع</th><th>التاريخ</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($cases as $row): ?>
                            <tr>
                                <td>
                                    <a class="fw-bold numeric" dir="ltr"
                                       href="<?= e(url($basePath . '/' . $row['id'])) ?>">
                                        <?= e($row['case_number']) ?></a>
                                    <?php if ($row['priority'] === 'high'): ?>
                                        <div><span class="np-badge np-badge--danger">أولوية عالية</span></div>
                                    <?php endif; ?>
                                </td>
                                <td class="fs-sm"><?= e(str_excerpt($row['title_ar'], 60)) ?></td>
                                <td class="fs-sm text-muted-np">
                                    <?= e($showSpecialist
                                        ? ($row['sme_trading_name'] ?: $row['sme_legal_name'])
                                        : ($row['center_trading_name'] ?: $row['center_legal_name'])) ?>
                                    <?php if ($showSpecialist && !empty($row['governorate_name'])): ?>
                                        <div class="fs-xs"><?= e($row['governorate_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <?php if ($showSpecialist): ?>
                                    <td class="fs-sm">
                                        <?php if (!empty($row['specialist_name'])): ?>
                                            <?= e($row['specialist_name']) ?>
                                        <?php else: ?>
                                            <span class="np-badge np-badge--pending">بلا إسناد</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <span class="np-badge <?= e($service->statusBadgeClass((string) $row['status'])) ?>">
                                        <?= e($service->statusLabel((string) $row['status'])) ?></span>
                                    <?php if ((int) $row['upcoming_sessions'] > 0): ?>
                                        <div class="fs-xs text-muted-np">
                                            <?= e(number_ar((int) $row['upcoming_sessions'])) ?> جلسة مجدولة</div>
                                    <?php endif; ?>
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
