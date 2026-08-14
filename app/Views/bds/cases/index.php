<?php
/**
 * طابور حالات الدعم | The centre's case queue (§4.8).
 *
 * @var array<int,array<string,mixed>> $cases
 * @var array<string,int> $counts
 * @var array<string,mixed> $summary
 * @var array<int,array<string,mixed>> $specialists
 * @var array<string,string> $filters
 * @var \App\Services\BdsCaseService $service
 */
?>
<div class="row g-3 mb-3">
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">بانتظار الفرز</div>
            <div class="stat-value"><?= e(number_ar((int) ($summary['awaiting_triage'] ?? 0))) ?></div>
            <div class="stat-tile__meta">آخر ٩٠ يوماً</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">حالات نشطة</div>
            <div class="stat-value"><?= e(number_ar((int) ($summary['active_cases'] ?? 0))) ?></div>
            <div class="stat-tile__meta">مُسندة أو قيد المتابعة</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">بلا إسناد</div>
            <div class="stat-value"><?= e(number_ar((int) ($summary['unassigned'] ?? 0))) ?></div>
            <div class="stat-tile__meta">تحتاج أخصائياً</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">متوسط رضا المشروعات</div>
            <div class="stat-value">
                <?= $summary['average_rating'] === null
                    ? '—' : e(number_ar((float) $summary['average_rating'], 1)) ?>
            </div>
            <div class="stat-tile__meta">من ٥، بعد إغلاق الحالات</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-9">
        <form method="get" action="<?= e(url('/app/bds/cases')) ?>" class="row g-2 mb-3">
            <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
            <div class="col-md-6">
                <label class="visually-hidden" for="q">بحث</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['q']) ?>" placeholder="رقم الحالة أو اسم المشروع…">
            </div>
            <div class="col-md-4 col-8">
                <label class="visually-hidden" for="assigned_to">الأخصائي</label>
                <select class="form-select form-select-sm" id="assigned_to" name="assigned_to">
                    <option value="">كل الأخصائيين</option>
                    <option value="me"<?= $filters['assigned_to'] === 'me' ? ' selected' : '' ?>>
                        حالاتي أنا</option>
                    <?php foreach ($specialists as $specialist): ?>
                        <option value="<?= e((string) $specialist['id']) ?>"
                            <?= $filters['assigned_to'] === (string) $specialist['id'] ? ' selected' : '' ?>>
                            <?= e($specialist['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-4 d-grid">
                <button type="submit" class="btn btn-sm btn-primary"><?= __e('common.search') ?></button>
            </div>
        </form>

        <?= $view->partial('partials/bds-case-list', [
            'cases'            => $cases,
            'counts'           => $counts,
            'filters'          => $filters,
            'service'          => $service,
            'basePath'         => '/app/bds/cases',
            'counterpartLabel' => 'المشروع',
            'emptyMessage'     => 'لا توجد حالات في هذه القائمة.',
            'showSpecialist'   => true,
        ]) ?>
    </div>

    <div class="col-lg-3">
        <div class="np-card">
            <div class="np-card__header">أحمال الأخصائيين</div>
            <div class="np-card__body p-0">
                <?php if ($specialists === []): ?>
                    <div class="np-empty py-4">
                        <p class="fs-sm mb-0">لا أعضاء في المركز بعد.</p>
                    </div>
                <?php else: ?>
                    <table class="np-table">
                        <tbody>
                            <?php foreach ($specialists as $specialist): ?>
                                <tr>
                                    <td class="fs-sm"><?= e($specialist['name']) ?></td>
                                    <td class="numeric fs-sm text-end">
                                        <?= e(number_ar((int) $specialist['active_cases'])) ?>
                                        <span class="fs-xs text-muted-np">نشطة</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <p class="fs-xs text-muted-np mt-3 mb-0">
            توزيع الحالات قرار بشري: المنصة تعرض الأحمال ولا تُسند آلياً.
        </p>
    </div>
</div>
