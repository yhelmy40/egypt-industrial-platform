<?php
/**
 * طابور مراجعة التوثيق | Verification queue (§3.1, §3.2).
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,string> $filters
 * @var array<string,int> $statusCounts
 */

$statusTabs = [
    'submitted'          => 'بانتظار المراجعة',
    'under_review'       => 'قيد المراجعة',
    'more_info_required' => 'مطلوب استكمال',
    'verified'           => 'موثّقة',
    'rejected'           => 'مرفوضة',
    'suspended'          => 'موقوفة',
    ''                   => 'الكل',
];

$statusBadges = [
    'draft'              => 'np-badge--draft',
    'submitted'          => 'np-badge--pending',
    'under_review'       => 'np-badge--review',
    'more_info_required' => 'np-badge--warning',
    'verified'           => 'np-badge--success',
    'rejected'           => 'np-badge--danger',
    'suspended'          => 'np-badge--danger',
];
?>

<?php // ─── تبويبات الحالة مع العدّادات ─── ?>
<div class="np-card mb-3">
    <div class="np-card__body">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($statusTabs as $value => $label): ?>
                <?php
                $isActive = $filters['status'] === $value;
                $count    = $value === '' ? array_sum($statusCounts) : ($statusCounts[$value] ?? 0);
                $query    = http_build_query(array_filter([
                    'status' => $value,
                    'type'   => $filters['type'],
                    'q'      => $filters['q'],
                ], static fn ($v) => $v !== '' && $v !== null));
                ?>
                <a class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-outline-primary' ?>"
                   href="<?= e(url('/admin/verifications') . ($query !== '' ? '?' . $query : '')) ?>">
                    <?= e($label) ?>
                    <span class="np-badge <?= $isActive ? 'np-badge--info' : 'np-badge--muted' ?> ms-1">
                        <?= e(number_ar($count)) ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="<?= e(url('/admin/verifications')) ?>" class="row g-2 align-items-end">
            <input type="hidden" name="status" value="<?= e($filters['status']) ?>">

            <div class="col-md-4">
                <label class="form-label fs-sm" for="q">بحث بالاسم</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['q']) ?>" placeholder="اسم المنشأة">
            </div>

            <div class="col-md-3">
                <label class="form-label fs-sm" for="type">نوع المنشأة</label>
                <select class="form-select form-select-sm" id="type" name="type">
                    <option value="">الكل</option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?= e((string) $type['code']) ?>"
                            <?= $filters['type'] === (string) $type['code'] ? ' selected' : '' ?>>
                            <?= e($type['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fs-sm" for="governorate_id">المحافظة</label>
                <select class="form-select form-select-sm" id="governorate_id" name="governorate_id">
                    <option value="">الكل</option>
                    <?php foreach ($governorates as $governorate): ?>
                        <option value="<?= e((string) $governorate['id']) ?>">
                            <?= e($governorate['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><?= __e('common.filter') ?></button>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/verifications')) ?>">↺</a>
            </div>
        </form>
    </div>
</div>

<?php // ─── الجدول ─── ?>
<div class="np-card">
    <div class="np-card__header">
        النتائج
        <span class="text-muted-np fs-sm fw-normal">
            <?= e(number_ar($results['total'])) ?> منشأة
        </span>
    </div>

    <div class="np-card__body p-0">
        <?php if ($results['data'] === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">✓</div>
                <p class="mb-0">لا توجد منشآت مطابقة للتصفية الحالية.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead>
                        <tr>
                            <th>المنشأة</th>
                            <th>النوع</th>
                            <th>القطاع</th>
                            <th>المحافظة</th>
                            <th>الاكتمال</th>
                            <th>الحالة</th>
                            <th>تاريخ الإرسال</th>
                            <th><?= __e('common.actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['data'] as $row): ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('/admin/verifications/' . $row['id'])) ?>" class="fw-bold">
                                        <?= e($row['trading_name'] ?: $row['legal_name']) ?>
                                    </a>
                                    <?php if (!empty($row['is_demo'])): ?>
                                        <span class="np-demo-tag"><?= __e('common.demo_data') ?></span>
                                    <?php endif; ?>
                                    <div class="fs-xs text-muted-np"><?= e($row['owner_name'] ?? '—') ?></div>
                                </td>
                                <td class="fs-sm text-muted-np"><?= e($row['type_name']) ?></td>
                                <td class="fs-sm text-muted-np"><?= e($row['sector_name'] ?? '—') ?></td>
                                <td class="fs-sm text-muted-np"><?= e($row['governorate_name'] ?? '—') ?></td>
                                <td class="numeric"><?= e((string) $row['completion_score']) ?>%</td>
                                <td>
                                    <span class="np-badge <?= e($statusBadges[$row['status']] ?? 'np-badge--muted') ?>">
                                        <?= e($statusTabs[$row['status']] ?? $row['status']) ?>
                                    </span>
                                </td>
                                <td class="fs-sm text-muted-np"><?= e(format_date($row['submitted_at'])) ?></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/admin/verifications/' . $row['id'])) ?>">مراجعة</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($results['last_page'] > 1): ?>
        <div class="np-card__footer d-flex justify-content-between align-items-center">
            <span class="fs-sm text-muted-np">
                <?= e(__('common.page_of', ['current' => $results['page'], 'last' => $results['last_page']])) ?>
            </span>
            <div class="d-flex gap-2">
                <?php
                $baseQuery = array_filter([
                    'status' => $filters['status'],
                    'type'   => $filters['type'],
                    'q'      => $filters['q'],
                ], static fn ($v) => $v !== '' && $v !== null);
                ?>
                <?php if ($results['page'] > 1): ?>
                    <a class="btn btn-sm btn-outline-primary"
                       href="<?= e(url('/admin/verifications') . '?' . http_build_query($baseQuery + ['page' => $results['page'] - 1])) ?>">
                        <?= __e('common.previous') ?>
                    </a>
                <?php endif; ?>
                <?php if ($results['page'] < $results['last_page']): ?>
                    <a class="btn btn-sm btn-outline-primary"
                       href="<?= e(url('/admin/verifications') . '?' . http_build_query($baseQuery + ['page' => $results['page'] + 1])) ?>">
                        <?= __e('common.next') ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
