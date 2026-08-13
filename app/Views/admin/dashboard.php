<?php
/**
 * لوحة تحكم المنصة | Platform admin dashboard (§4.13).
 *
 * @var array<string,int> $counts
 * @var array<string,int> $statusCounts
 * @var array<int,array<string,mixed>> $byGovernorate
 * @var array<int,array<string,mixed>> $byType
 * @var array<int,array<string,mixed>> $recentActivity
 * @var array<int,array<string,mixed>> $pendingQueue
 */

$statusLabels = [
    'draft'              => 'مسودة',
    'submitted'          => 'بانتظار المراجعة',
    'under_review'       => 'قيد المراجعة',
    'more_info_required' => 'مطلوب استكمال',
    'verified'           => 'موثّقة',
    'rejected'           => 'مرفوضة',
    'suspended'          => 'موقوفة',
];

$tiles = [
    ['label' => 'إجمالي المستخدمين', 'value' => $counts['users'] ?? 0,          'meta' => 'نشط: ' . number_ar($counts['active_users'] ?? 0)],
    ['label' => 'إجمالي المنشآت',    'value' => $counts['organizations'] ?? 0,  'meta' => 'موثّقة: ' . number_ar($counts['verified'] ?? 0)],
    ['label' => 'بانتظار المراجعة',  'value' => $counts['pending_review'] ?? 0, 'meta' => 'طلبات توثيق معلّقة'],
    ['label' => 'أحداث آخر 24 ساعة', 'value' => $counts['events_24h'] ?? 0,     'meta' => 'في سجل التدقيق'],
    ['label' => 'محاولات دخول فاشلة','value' => $counts['failed_logins_24h'] ?? 0, 'meta' => 'خلال 24 ساعة'],
];
?>

<div class="row g-3 mb-4">
    <?php foreach ($tiles as $tile): ?>
        <div class="col-6 col-lg">
            <div class="stat-tile">
                <div class="stat-tile__label"><?= e($tile['label']) ?></div>
                <div class="stat-value"><?= e(number_ar($tile['value'])) ?></div>
                <div class="stat-tile__meta"><?= e($tile['meta']) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="np-card h-100">
            <div class="np-card__header">طابور مراجعة التوثيق</div>
            <div class="np-card__body p-0">
                <?php if ($pendingQueue === []): ?>
                    <div class="np-empty">
                        <div class="np-empty__icon" aria-hidden="true">✓</div>
                        <p class="mb-0">لا توجد طلبات توثيق معلّقة حالياً.</p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll" style="border:0">
                        <table class="np-table">
                            <thead>
                                <tr>
                                    <th>المنشأة</th>
                                    <th>النوع</th>
                                    <th>المحافظة</th>
                                    <th>الاكتمال</th>
                                    <th>تاريخ الإرسال</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingQueue as $row): ?>
                                    <tr>
                                        <td><?= e($row['legal_name']) ?></td>
                                        <td class="text-muted-np fs-sm"><?= e($row['type_name']) ?></td>
                                        <td class="text-muted-np fs-sm"><?= e($row['governorate_name'] ?? '—') ?></td>
                                        <td class="numeric"><?= e((string) $row['completion_score']) ?>%</td>
                                        <td class="fs-sm"><?= e(format_date($row['submitted_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="np-card h-100">
            <div class="np-card__header">المنشآت حسب الحالة</div>
            <div class="np-card__body">
                <?php if ($statusCounts === []): ?>
                    <p class="text-muted-np mb-0"><?= __e('common.no_data_yet') ?></p>
                <?php else: ?>
                    <?php $total = array_sum($statusCounts) ?: 1; ?>
                    <?php foreach ($statusCounts as $status => $count): ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between fs-sm mb-1">
                                <span><?= e($statusLabels[$status] ?? $status) ?></span>
                                <span class="numeric fw-bold"><?= e(number_ar($count)) ?></span>
                            </div>
                            <div class="completion__track">
                                <div class="completion__fill" style="width: <?= e((string) round($count / $total * 100)) ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="np-card h-100">
            <div class="np-card__header">المنشآت حسب النوع</div>
            <div class="np-card__body p-0">
                <div class="table-scroll" style="border:0">
                    <table class="np-table">
                        <thead>
                            <tr><th>النوع</th><th>الإجمالي</th><th>موثّقة</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($byType as $row): ?>
                                <tr>
                                    <td><?= e($row['type_name']) ?></td>
                                    <td class="numeric"><?= e(number_ar((int) $row['total'])) ?></td>
                                    <td class="numeric"><?= e(number_ar((int) ($row['verified'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="np-card h-100">
            <div class="np-card__header">
                آخر الأحداث في سجل التدقيق
                <span class="np-badge np-badge--muted">آخر 12 حدثاً</span>
            </div>
            <div class="np-card__body p-0">
                <?php if ($recentActivity === []): ?>
                    <div class="np-empty"><p class="mb-0"><?= __e('common.no_data_yet') ?></p></div>
                <?php else: ?>
                    <div class="table-scroll" style="border:0">
                        <table class="np-table">
                            <thead>
                                <tr><th>الحدث</th><th>المستخدم</th><th>الوقت</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentActivity as $event): ?>
                                    <tr>
                                        <td>
                                            <?= e($event['description'] ?? $event['action']) ?>
                                            <?php if (($event['severity'] ?? 'info') === 'warning'): ?>
                                                <span class="np-badge np-badge--warning">تنبيه</span>
                                            <?php elseif (($event['severity'] ?? 'info') === 'critical'): ?>
                                                <span class="np-badge np-badge--danger">حرج</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fs-sm text-muted-np"><?= e($event['user_name'] ?? 'النظام') ?></td>
                                        <td class="fs-sm text-muted-np"><?= e(time_ago($event['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
