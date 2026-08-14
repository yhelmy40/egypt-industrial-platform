<?php
/**
 * التقارير الإدارية | Management reports (§4.10, §14).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **التنويه الإلزامي يُعرض أولاً وأخيراً.** المواصفة تشترط أن يعرف صاحب
 * المشروع أن هذه تقارير إدارية يراجعها محاسب مؤهّل، لا قوائم مالية معتمدة.
 * النصّ يأتي من الخدمة (`$report['disclaimer']`) لا مكتوباً هنا، فلا يمكن أن
 * يُضاف تقرير جديد وينسى مطوّر أن يعرضه.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @var array<string,mixed> $report
 * @var array<string,string> $filters
 * @var \App\Services\ExpenseService $expenseService
 * @var \App\Services\CustomerService $customerService
 * @var \App\Services\PipelineService $pipelineService
 */

$sales    = $report['sales'];
$cash     = $report['cash'];
$stock    = $report['stock'];
$expenses = $report['expenses'];
$dues     = $report['receivables'];

$buckets = ['not_due' => 'غير مستحقّة بعد', 'd1_30' => 'متأخّرة ١–٣٠ يوماً',
            'd31_60' => '٣١–٦٠ يوماً', 'd61_90' => '٦١–٩٠ يوماً', 'd90_plus' => 'أكثر من ٩٠ يوماً'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">التقارير الإدارية</h1>
        <p class="fs-sm text-muted-np mb-0">
            من <?= e(format_date($filters['from'])) ?> إلى <?= e(format_date($filters['to'])) ?>
        </p>
    </div>
    <form method="get" action="<?= e(url('/app/reports')) ?>" class="d-flex gap-2 align-items-end">
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

<div class="alert alert-warning" role="alert">
    <strong>تنويه:</strong> <?= e($report['disclaimer']) ?>
</div>

<!-- المبيعات والنقدية | Sales and cash -->
<div class="row g-3 mb-3">
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">مبيعات الفترة (مفوتَرة)</div>
            <div class="stat-value"><?= e(money((float) ($sales['totals']['total'] ?? 0))) ?></div>
            <div class="stat-tile__meta">
                <?= e(number_ar((int) ($sales['totals']['invoice_count'] ?? 0))) ?> فاتورة، عدا الملغاة
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">نقدية داخلة</div>
            <div class="stat-value"><?= e(money($cash['received'])) ?></div>
            <div class="stat-tile__meta">مقبوضات بتاريخ القبض</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">نقدية خارجة</div>
            <div class="stat-value"><?= e(money($cash['spent'])) ?></div>
            <div class="stat-tile__meta">مصروفات بتاريخ الصرف</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">صافي الحركة النقدية</div>
            <div class="stat-value"><?= e(money($cash['net'])) ?></div>
            <div class="stat-tile__meta">ليس ربحاً — انظر التنويه أدناه</div>
        </div>
    </div>
</div>

<div class="alert alert-info fs-sm" role="alert"><?= e($cash['note_ar']) ?></div>

<div class="row g-3">
    <!-- المبيعات شهرياً | Monthly sales -->
    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header"><h2 class="h6 mb-0">المبيعات شهرياً</h2></div>
            <div class="np-card__body p-0">
                <?php if (($sales['monthly'] ?? []) === []): ?>
                    <div class="np-empty"><p class="mb-0 fs-sm">لا مبيعات في هذه الفترة.</p></div>
                <?php else: ?>
                    <table class="np-table">
                        <thead><tr><th>الشهر</th><th>عدد الفواتير</th><th>الإجمالي</th></tr></thead>
                        <tbody>
                            <?php foreach ($sales['monthly'] as $month): ?>
                                <tr>
                                    <td class="numeric fs-sm" dir="ltr"><?= e($month['month']) ?></td>
                                    <td class="numeric fs-sm"><?= e(number_ar((int) $month['invoice_count'])) ?></td>
                                    <td class="numeric fs-sm fw-bold"><?= e(money((float) $month['total'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- أكثر الأصناف مبيعاً | Top items -->
    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header"><h2 class="h6 mb-0">أكثر البنود إيراداً</h2></div>
            <div class="np-card__body p-0">
                <?php if (($sales['top_items'] ?? []) === []): ?>
                    <div class="np-empty"><p class="mb-0 fs-sm">لا بيانات بعد.</p></div>
                <?php else: ?>
                    <table class="np-table">
                        <thead><tr><th>البند</th><th>الكمية</th><th>الإيراد</th></tr></thead>
                        <tbody>
                            <?php foreach ($sales['top_items'] as $item): ?>
                                <tr>
                                    <td class="fs-sm"><?= e($item['name_ar']) ?></td>
                                    <td class="numeric fs-sm"><?= e(number_ar((float) $item['quantity'], 2)) ?></td>
                                    <td class="numeric fs-sm fw-bold"><?= e(money((float) $item['revenue'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- المصروفات ببنودها | Expenses by category -->
    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">المصروفات ببنودها</h2>
                <span class="fs-sm fw-bold numeric"><?= e(money($expenses['total'])) ?></span>
            </div>
            <div class="np-card__body p-0">
                <?php if ($expenses['rows'] === []): ?>
                    <div class="np-empty"><p class="mb-0 fs-sm">لا مصروفات في هذه الفترة.</p></div>
                <?php else: ?>
                    <table class="np-table">
                        <thead><tr><th>البند</th><th>عدد القيود</th><th>المبلغ</th><th>النسبة</th></tr></thead>
                        <tbody>
                            <?php foreach ($expenses['rows'] as $row): ?>
                                <?php
                                $share = $expenses['total'] > 0
                                    ? ((float) $row['total'] / $expenses['total']) * 100 : 0.0;
                                ?>
                                <tr>
                                    <td class="fs-sm">
                                        <?= e($expenseService->categoryLabel((string) $row['category'])) ?></td>
                                    <td class="numeric fs-sm"><?= e(number_ar((int) $row['entry_count'])) ?></td>
                                    <td class="numeric fs-sm fw-bold"><?= e(money((float) $row['total'])) ?></td>
                                    <td class="numeric fs-xs text-muted-np">
                                        <?= e(number_ar($share, 1)) ?>٪</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- أعمار الذمم | Receivables ageing -->
    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">أعمار الذمم المدينة</h2>
                <span class="fs-sm fw-bold numeric"><?= e(money($dues['total'])) ?></span>
            </div>
            <div class="np-card__body p-0">
                <table class="np-table">
                    <thead><tr><th>الشريحة</th><th>عدد الفواتير</th><th>المبلغ</th></tr></thead>
                    <tbody>
                        <?php foreach ($buckets as $key => $label): ?>
                            <tr>
                                <td class="fs-sm"><?= e($label) ?></td>
                                <td class="numeric fs-sm"><?= e(number_ar($dues['buckets'][$key]['count'])) ?></td>
                                <td class="numeric fs-sm fw-bold">
                                    <?= e(money($dues['buckets'][$key]['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($dues['overdue_list'] !== []): ?>
                <div class="np-card__footer">
                    <h3 class="h6 fs-sm mb-2">أقدم الفواتير المتأخّرة</h3>
                    <ul class="list-unstyled mb-0">
                        <?php foreach (array_slice($dues['overdue_list'], 0, 5) as $overdue): ?>
                            <li class="d-flex justify-content-between gap-2 fs-sm mb-1">
                                <a href="<?= e(url('/app/invoices/' . $overdue['id'])) ?>">
                                    <span class="numeric" dir="ltr"><?= e($overdue['invoice_number']) ?></span>
                                    — <?= e($overdue['customer_name_ar']) ?>
                                </a>
                                <span class="numeric text-danger">
                                    <?= e(money((float) $overdue['balance_due'])) ?>
                                    (<?= e(number_ar((int) $overdue['days_overdue'])) ?> يوماً)
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- المخزون | Stock -->
    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header"><h2 class="h6 mb-0">حالة المخزون</h2></div>
            <div class="np-card__body">
                <dl class="row mb-3 fs-sm">
                    <dt class="col-7">عدد الأصناف المتتبَّعة</dt>
                    <dd class="col-5 numeric text-end">
                        <?= e(number_ar((int) $stock['valuation']['item_count'])) ?></dd>
                    <dt class="col-7">القيمة التقديرية بالتكلفة</dt>
                    <dd class="col-5 numeric text-end fw-bold">
                        <?= e(money((float) $stock['valuation']['total_cost'])) ?></dd>
                    <?php if ((int) $stock['valuation']['items_without_cost'] > 0): ?>
                        <dt class="col-7">أصناف بلا تكلفة مسجَّلة</dt>
                        <dd class="col-5 numeric text-end text-danger">
                            <?= e(number_ar((int) $stock['valuation']['items_without_cost'])) ?></dd>
                    <?php endif; ?>
                </dl>

                <p class="fs-xs text-muted-np"><?= e($stock['note_ar']) ?></p>

                <?php if ($stock['low_stock'] !== []): ?>
                    <h3 class="h6 fs-sm mb-2">تحت حدّ إعادة الطلب</h3>
                    <ul class="list-unstyled mb-0">
                        <?php foreach (array_slice($stock['low_stock'], 0, 8) as $item): ?>
                            <li class="d-flex justify-content-between gap-2 fs-sm mb-1">
                                <a href="<?= e(url('/app/inventory/' . $item['id'])) ?>">
                                    <?= e($item['name_ar']) ?></a>
                                <span class="numeric text-muted-np">
                                    <?= e(number_ar((float) $item['quantity_on_hand'], 2)) ?>
                                    / <?= e(number_ar((float) $item['reorder_level'], 2)) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- أفضل العملاء وخطّ الفرص | Top customers and pipeline -->
    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header"><h2 class="h6 mb-0">أفضل العملاء بالإيراد</h2></div>
            <div class="np-card__body p-0">
                <?php if ($report['customers'] === []): ?>
                    <div class="np-empty"><p class="mb-0 fs-sm">لا فواتير مرتبطة بعملاء مسجَّلين.</p></div>
                <?php else: ?>
                    <table class="np-table">
                        <thead><tr><th>العميل</th><th>عدد الفواتير</th><th>الإيراد</th></tr></thead>
                        <tbody>
                            <?php foreach ($report['customers'] as $customer): ?>
                                <tr>
                                    <td class="fs-sm">
                                        <a href="<?= e(url('/app/customers/' . $customer['id'])) ?>">
                                            <?= e($customer['name_ar']) ?></a>
                                    </td>
                                    <td class="numeric fs-sm">
                                        <?= e(number_ar((int) $customer['invoice_count'])) ?></td>
                                    <td class="numeric fs-sm fw-bold">
                                        <?= e(money((float) $customer['revenue'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <div class="np-card__footer">
                <h3 class="h6 fs-sm mb-2">خطّ الفرص الحالي</h3>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($report['pipeline'] as $stage => $data): ?>
                        <span class="np-badge <?= e($pipelineService->stageBadgeClass($stage)) ?>">
                            <?= e($pipelineService->stageLabel($stage)) ?>:
                            <?= e(number_ar($data['count'])) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <p class="fs-xs text-muted-np mb-0 mt-2">
                    قيم الفرص تقديراتك أنت، وليست تنبّؤاً من المنصة.
                </p>
            </div>
        </div>
    </div>
</div>

<div class="np-card mt-3">
    <div class="np-card__body fs-sm text-muted-np">
        <strong>حدود هذه التقارير:</strong> <?= e($report['disclaimer']) ?>
    </div>
</div>
