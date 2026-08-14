<?php
/**
 * تقارير المنصة | Platform reports (§4.12).
 *
 * @var array<string,mixed> $report
 * @var array<string,string> $filters
 * @var array<string,string> $datasets
 */

$orgs      = $report['organizations'];
$market    = $report['marketplace'];
$financing = $report['financing'];
$services  = $report['services'];
$bds       = $report['bds'];
$content   = $report['content'];

$statusLabels = ['draft' => 'مسودة', 'submitted' => 'مُقدَّم', 'under_review' => 'قيد المراجعة',
                 'more_info_required' => 'بيانات ناقصة', 'verified' => 'موثّق',
                 'rejected' => 'مرفوض', 'suspended' => 'موقوف'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">تقارير المنصة</h1>
        <p class="fs-sm text-muted-np mb-0">
            من <?= e(format_date($filters['from'])) ?> إلى <?= e(format_date($filters['to'])) ?>
        </p>
    </div>
    <form method="get" action="<?= e(url('/admin/reports')) ?>" class="d-flex gap-2 align-items-end">
        <div>
            <label class="form-label fs-sm mb-0" for="from">من</label>
            <input type="date" class="form-control form-control-sm" dir="ltr" id="from" name="from"
                   value="<?= e($filters['from']) ?>">
        </div>
        <div>
            <label class="form-label fs-sm mb-0" for="to">إلى</label>
            <input type="date" class="form-control form-control-sm" dir="ltr" id="to" name="to"
                   value="<?= e($filters['to']) ?>">
        </div>
        <button type="submit" class="btn btn-sm btn-outline-primary">عرض</button>
    </form>
</div>

<div class="alert alert-info fs-sm" role="alert"><?= e($report['note']) ?></div>

<!-- الطوابير | Queues -->
<h2 class="h6 mb-2">الطوابير المعلّقة</h2>
<div class="row g-2 mb-4">
    <?php foreach ($report['queues'] as $queue): ?>
        <div class="col-md-3 col-6">
            <a class="stat-tile d-block text-decoration-none" href="<?= e(url($queue['url'])) ?>">
                <div class="stat-tile__label"><?= e($queue['label']) ?></div>
                <div class="stat-value <?= $queue['count'] > 0 ? 'text-danger' : '' ?>">
                    <?= e(number_ar($queue['count'])) ?>
                </div>
                <div class="stat-tile__meta">
                    <?= $queue['oldest_days'] !== null && $queue['count'] > 0
                        ? 'أقدمها منذ ' . e(number_ar($queue['oldest_days'])) . ' يوماً'
                        : 'لا انتظار' ?>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <!-- المنشآت | Organizations -->
    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header"><h2 class="h6 mb-0">المنشآت والتوثيق</h2></div>
            <div class="np-card__body">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="stat-tile">
                            <div class="stat-tile__label">سُجّلت في الفترة</div>
                            <div class="stat-value">
                                <?= e(number_ar((int) $orgs['registered_in_period'])) ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-tile">
                            <div class="stat-tile__label">متوسط أيام البتّ</div>
                            <div class="stat-value">
                                <?= $orgs['verification']['avg_days'] !== null
                                    ? e(number_ar((float) $orgs['verification']['avg_days'], 1)) : '—' ?>
                            </div>
                            <div class="stat-tile__meta">
                                <?= e(number_ar((int) ($orgs['verification']['decided'] ?? 0))) ?> قراراً
                            </div>
                        </div>
                    </div>
                </div>

                <table class="np-table">
                    <thead><tr><th>نوع الحساب</th><th>الإجمالي</th><th>الموثّق</th></tr></thead>
                    <tbody>
                        <?php foreach ($orgs['by_type'] as $row): ?>
                            <tr>
                                <td class="fs-sm"><?= e($row['name_ar']) ?></td>
                                <td class="numeric fs-sm"><?= e(number_ar((int) $row['total'])) ?></td>
                                <td class="numeric fs-sm"><?= e(number_ar((int) ($row['verified'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="d-flex flex-wrap gap-1 mt-3">
                    <?php foreach ($orgs['by_status'] as $status => $count): ?>
                        <span class="np-badge np-badge--muted">
                            <?= e($statusLabels[$status] ?? $status) ?>: <?= e(number_ar($count)) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- الانتشار الجغرافي | Geography -->
    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header"><h2 class="h6 mb-0">الانتشار الجغرافي</h2></div>
            <div class="np-card__body p-0" style="max-height:24rem;overflow-y:auto">
                <table class="np-table">
                    <thead><tr><th>المحافظة</th><th>المنشآت</th><th>الموثّقة</th></tr></thead>
                    <tbody>
                        <?php foreach ($report['geography'] as $row): ?>
                            <tr>
                                <td class="fs-sm"><?= e($row['name_ar']) ?></td>
                                <td class="numeric fs-sm"><?= e(number_ar((int) $row['total'])) ?></td>
                                <td class="numeric fs-sm"><?= e(number_ar((int) ($row['verified'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- التمويل | Financing -->
    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header"><h2 class="h6 mb-0">التمويل</h2></div>
            <div class="np-card__body">
                <table class="np-table mb-3">
                    <tbody>
                        <tr><td class="fs-sm">إجمالي الطلبات في الفترة</td>
                            <td class="numeric fs-sm fw-bold">
                                <?= e(number_ar((int) ($financing['applications']['total'] ?? 0))) ?></td></tr>
                        <tr><td class="fs-sm">بانتظار فرز المنصة</td>
                            <td class="numeric fs-sm">
                                <?= e(number_ar((int) ($financing['applications']['awaiting_screening'] ?? 0))) ?></td></tr>
                        <tr><td class="fs-sm"><strong>أحالتها المنصة</strong> للجهات</td>
                            <td class="numeric fs-sm">
                                <?= e(number_ar((int) ($financing['applications']['forwarded_by_platform'] ?? 0))) ?></td></tr>
                        <tr><td class="fs-sm"><strong>قبلتها الجهة المموّلة</strong></td>
                            <td class="numeric fs-sm">
                                <?= e(number_ar((int) ($financing['applications']['approved_by_provider'] ?? 0))) ?></td></tr>
                        <tr><td class="fs-sm"><strong>رفضتها الجهة المموّلة</strong></td>
                            <td class="numeric fs-sm">
                                <?= e(number_ar((int) ($financing['applications']['rejected_by_provider'] ?? 0))) ?></td></tr>
                    </tbody>
                </table>

                <p class="fs-xs text-muted-np mb-2"><?= e($report['decisions_note']) ?></p>

                <div class="d-flex flex-wrap gap-1">
                    <span class="np-badge np-badge--success">
                        منتجات منشورة: <?= e(number_ar((int) ($financing['products']['published'] ?? 0))) ?></span>
                    <span class="np-badge np-badge--pending">
                        بانتظار الاعتماد: <?= e(number_ar((int) ($financing['products']['pending'] ?? 0))) ?></span>
                    <span class="np-badge np-badge--muted">
                        منها تجريبية: <?= e(number_ar((int) ($financing['products']['demo'] ?? 0))) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- السوق | Marketplace -->
    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header"><h2 class="h6 mb-0">السوق</h2></div>
            <div class="np-card__body">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="stat-tile">
                            <div class="stat-tile__label">طلبات الفترة</div>
                            <div class="stat-value">
                                <?= e(number_ar((int) ($market['orders']['total'] ?? 0))) ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-tile">
                            <div class="stat-tile__label">قيمتها</div>
                            <div class="stat-value">
                                <?= e(money((float) ($market['orders']['value'] ?? 0))) ?></div>
                            <div class="stat-tile__meta">عدا الملغي والمسترد</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-1">
                    <span class="np-badge np-badge--success">
                        إعلانات منشورة: <?= e(number_ar((int) ($market['listings']['published'] ?? 0))) ?></span>
                    <span class="np-badge np-badge--pending">
                        بانتظار المراجعة: <?= e(number_ar((int) ($market['listings']['pending'] ?? 0))) ?></span>
                    <span class="np-badge np-badge--info">
                        طلبات مكتملة: <?= e(number_ar((int) ($market['orders']['completed'] ?? 0))) ?></span>
                    <span class="np-badge np-badge--danger">
                        محل نزاع: <?= e(number_ar((int) ($market['orders']['disputed'] ?? 0))) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- الخدمات ومراكز الأعمال | Services and BDS -->
    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header"><h2 class="h6 mb-0">الخدمات ومراكز تطوير الأعمال</h2></div>
            <div class="np-card__body">
                <div class="d-flex flex-wrap gap-1 mb-3">
                    <span class="np-badge np-badge--info">
                        طلبات خدمة: <?= e(number_ar((int) ($services['requests']['total'] ?? 0))) ?></span>
                    <span class="np-badge np-badge--success">
                        مكتملة: <?= e(number_ar((int) ($services['requests']['completed'] ?? 0))) ?></span>
                    <span class="np-badge np-badge--pending">
                        باقات بانتظار الاعتماد: <?= e(number_ar((int) ($services['offerings']['pending'] ?? 0))) ?></span>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <div class="stat-tile">
                            <div class="stat-tile__label">حالات دعم</div>
                            <div class="stat-value"><?= e(number_ar((int) ($bds['cases']['total'] ?? 0))) ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-tile">
                            <div class="stat-tile__label">مكتملة</div>
                            <div class="stat-value"><?= e(number_ar((int) ($bds['cases']['completed'] ?? 0))) ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-tile">
                            <div class="stat-tile__label">لم تُسنَد</div>
                            <div class="stat-value"><?= e(number_ar((int) ($bds['cases']['unassigned'] ?? 0))) ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($bds['centres'] !== []): ?>
                    <table class="np-table">
                        <thead><tr><th>المركز</th><th>الحالات</th><th>المكتملة</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($bds['centres'], 0, 8) as $centre): ?>
                                <tr>
                                    <td class="fs-sm">
                                        <?= e($centre['trading_name'] ?: $centre['legal_name']) ?></td>
                                    <td class="numeric fs-sm"><?= e(number_ar((int) $centre['cases'])) ?></td>
                                    <td class="numeric fs-sm"><?= e(number_ar((int) $centre['completed'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- المحتوى والتصدير | Content and export -->
    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header"><h2 class="h6 mb-0">المحتوى</h2></div>
            <div class="np-card__body">
                <div class="d-flex flex-wrap gap-1 mb-4">
                    <span class="np-badge np-badge--success">
                        مقالات منشورة: <?= e(number_ar((int) ($content['articles']['published'] ?? 0))) ?></span>
                    <span class="np-badge np-badge--pending">
                        بانتظار النشر: <?= e(number_ar((int) ($content['articles']['pending'] ?? 0))) ?></span>
                    <span class="np-badge np-badge--info">
                        مشاهدات: <?= e(number_ar((int) ($content['articles']['views'] ?? 0))) ?></span>
                    <span class="np-badge np-badge--muted">
                        أسئلة منشورة: <?= e(number_ar((int) ($content['faqs']['published'] ?? 0))) ?></span>
                </div>

                <h3 class="h6 fs-sm mb-2">تصدير البيانات</h3>
                <p class="fs-xs text-muted-np">
                    مجموعات مجمّعة على مستوى المحافظة والقطاع والحالة.
                    <strong>لا تحتوي أي بيانات شخصية</strong>، وكل تصدير يُسجَّل في سجلّ التدقيق.
                </p>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($datasets as $key => $label): ?>
                        <li class="mb-1">
                            <a class="fs-sm"
                               href="<?= e(url('/admin/reports/export?dataset=' . $key)) ?>">
                                ⬇ <?= e($label) ?> (CSV)
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
