<?php
/**
 * أوامر الشراء | Purchase orders (§4.10).
 *
 * @var array<int,array<string,mixed>> $orders
 * @var array<int,array<string,mixed>> $suppliers
 * @var array<string,string> $filters
 * @var \App\Services\PurchaseOrderService $service
 */

$tabs = ['' => 'الكل', 'draft' => 'مسودة', 'sent' => 'مُرسَل',
         'partially_received' => 'مستلَم جزئياً', 'received' => 'مستلَم', 'cancelled' => 'ملغى'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">أوامر الشراء</h1>
        <p class="fs-sm text-muted-np mb-0">
            المخزون يزيد بتسجيل الاستلام لا بإرسال الأمر.
        </p>
    </div>
    <div class="d-flex flex-wrap gap-1">
        <?php foreach ($tabs as $value => $label): ?>
            <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= e(url('/app/purchasing/orders') . ($value === '' ? '' : '?status=' . $value)) ?>">
                <?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($suppliers === []): ?>
    <div class="alert alert-info" role="alert">
        سجّل مورّداً أولاً — أمر الشراء يُوجَّه إلى مورّد.
        <a href="<?= e(url('/app/purchasing/suppliers')) ?>">إضافة مورّد</a>
    </div>
<?php else: ?>
    <div class="np-card mb-3">
        <div class="np-card__header"><h2 class="h6 mb-0">أمر شراء جديد</h2></div>
        <form method="post" action="<?= e(url('/app/purchasing/orders')) ?>">
            <?= csrf_field() ?>
            <div class="np-card__body row g-2">
                <div class="col-md-4">
                    <label class="form-label fs-sm" for="supplier_id">المورّد <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" id="supplier_id" name="supplier_id" required>
                        <option value="">— اختر —</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= e((string) $supplier['id']) ?>"><?= e($supplier['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-sm" for="order_date">تاريخ الأمر</label>
                    <input type="date" class="form-control form-control-sm" dir="ltr"
                           id="order_date" name="order_date" value="<?= e(date('Y-m-d')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-sm" for="expected_date">التوريد المتوقّع</label>
                    <input type="date" class="form-control form-control-sm" dir="ltr"
                           id="expected_date" name="expected_date">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-sm btn-primary w-100">إنشاء</button>
                </div>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($orders === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">📥</div>
                <p class="mb-1">لا أوامر شراء مطابقة.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>الرقم</th><th>المورّد</th><th>تاريخ الأمر</th>
                        <th>التوريد المتوقّع</th><th>الإجمالي</th><th>الحالة</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td>
                                    <a class="numeric fw-bold" dir="ltr"
                                       href="<?= e(url('/app/purchasing/orders/' . $order['id'])) ?>">
                                        <?= e($order['po_number']) ?></a>
                                </td>
                                <td class="fs-sm"><?= e($order['supplier_name']) ?></td>
                                <td class="fs-xs text-muted-np">
                                    <?= e(format_date((string) $order['order_date'])) ?></td>
                                <td class="fs-xs text-muted-np">
                                    <?= $order['expected_date'] !== null
                                        ? e(format_date((string) $order['expected_date'])) : '—' ?>
                                </td>
                                <td class="numeric fs-sm fw-bold"><?= e(money((float) $order['total'])) ?></td>
                                <td>
                                    <span class="np-badge <?= e($service->statusBadgeClass((string) $order['status'])) ?>">
                                        <?= e($service->statusLabel((string) $order['status'])) ?></span>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/app/purchasing/orders/' . $order['id'])) ?>">فتح</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
