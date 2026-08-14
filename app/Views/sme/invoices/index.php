<?php
/**
 * فواتير البيع | Sales invoices (§4.10).
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,mixed> $filters
 * @var array<string,array{count:int,amount:float}> $ageing
 * @var array<int,array<string,mixed>> $customers
 * @var \App\Services\InvoiceService $service
 */

$tabs    = ['' => 'الكل', 'draft' => 'مسودة', 'issued' => 'صادرة',
            'partially_paid' => 'مسدَّدة جزئياً', 'paid' => 'مسدَّدة', 'cancelled' => 'ملغاة'];
$buckets = ['not_due' => 'غير مستحقّة بعد', 'd1_30' => 'متأخّرة ١–٣٠ يوماً',
            'd31_60' => '٣١–٦٠ يوماً', 'd61_90' => '٦١–٩٠ يوماً', 'd90_plus' => 'أكثر من ٩٠ يوماً'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">فواتير البيع</h1>
        <p class="fs-sm text-muted-np mb-0">
            المنصة تسجّل الفاتورة والمقبوض، ولا تحصّل نيابةً عنك.
        </p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('/app/invoices/new')) ?>">فاتورة جديدة</a>
</div>

<div class="row g-2 mb-3">
    <?php foreach ($buckets as $key => $label): ?>
        <div class="col-md col-6">
            <div class="stat-tile">
                <div class="stat-tile__label"><?= e($label) ?></div>
                <div class="stat-value"><?= e(money($ageing[$key]['amount'])) ?></div>
                <div class="stat-tile__meta"><?= e(number_ar($ageing[$key]['count'])) ?> فاتورة</div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-1">
        <?php foreach ($tabs as $value => $label): ?>
            <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= e(url('/app/invoices') . ($value === '' ? '' : '?status=' . $value)) ?>">
                <?= e($label) ?></a>
        <?php endforeach; ?>
        <a class="btn btn-sm <?= !empty($filters['overdue']) ? 'btn-danger' : 'btn-outline-danger' ?>"
           href="<?= e(url('/app/invoices?overdue=1')) ?>">المتأخّرة</a>
    </div>

    <form method="get" action="<?= e(url('/app/invoices')) ?>" class="d-flex gap-2">
        <label class="visually-hidden" for="q">بحث</label>
        <input type="search" class="form-control form-control-sm" id="q" name="q"
               value="<?= e((string) $filters['q']) ?>" placeholder="رقم الفاتورة أو اسم العميل…"
               style="min-width:14rem">
        <button type="submit" class="btn btn-sm btn-outline-primary">بحث</button>
    </form>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($results['data'] === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">🧾</div>
                <p class="mb-1">لا فواتير مطابقة.</p>
                <p class="fs-sm mb-0">أنشئ مسودة، أضف بنودها، ثم أصدرها.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>الرقم</th><th>العميل</th><th>الإصدار</th><th>الاستحقاق</th>
                        <th>الإجمالي</th><th>المسدَّد</th><th>المتبقّي</th><th>الحالة</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($results['data'] as $invoice): ?>
                            <?php
                            $overdue = in_array((string) $invoice['status'], ['issued', 'partially_paid'], true)
                                && $invoice['due_date'] !== null
                                && (string) $invoice['due_date'] < date('Y-m-d');
                            ?>
                            <tr>
                                <td>
                                    <a class="numeric fw-bold" dir="ltr"
                                       href="<?= e(url('/app/invoices/' . $invoice['id'])) ?>">
                                        <?= e($invoice['invoice_number']) ?></a>
                                </td>
                                <td class="fs-sm"><?= e($invoice['customer_name_ar']) ?></td>
                                <td class="fs-xs text-muted-np">
                                    <?= e(format_date((string) $invoice['issue_date'])) ?></td>
                                <td class="fs-xs <?= $overdue ? 'text-danger fw-bold' : 'text-muted-np' ?>">
                                    <?= $invoice['due_date'] !== null
                                        ? e(format_date((string) $invoice['due_date'])) : '—' ?>
                                </td>
                                <td class="numeric fs-sm fw-bold"><?= e(money((float) $invoice['total'])) ?></td>
                                <td class="numeric fs-sm"><?= e(money((float) $invoice['amount_paid'])) ?></td>
                                <td class="numeric fs-sm"><?= e(money((float) $invoice['balance_due'])) ?></td>
                                <td>
                                    <span class="np-badge <?= e($service->statusBadgeClass((string) $invoice['status'])) ?>">
                                        <?= e($service->statusLabel((string) $invoice['status'])) ?></span>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/app/invoices/' . $invoice['id'])) ?>">فتح</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($results['last_page'] > 1): ?>
    <nav class="mt-3" aria-label="تصفّح الصفحات">
        <ul class="pagination pagination-sm justify-content-center mb-0">
            <?php for ($page = 1; $page <= $results['last_page']; $page++): ?>
                <li class="page-item <?= $page === $results['page'] ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e(url('/app/invoices?page=' . $page)) ?>">
                        <?= e(number_ar($page)) ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
