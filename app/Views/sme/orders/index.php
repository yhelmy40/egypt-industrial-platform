<?php
/**
 * طلبات المنشأة | Seller order list (§4.4).
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,int> $counts
 * @var array<string,mixed> $summary
 * @var array<string,string> $filters
 * @var \App\Services\OrderService $orderService
 */

$tabs = ['' => 'الكل', 'new' => 'جديد', 'confirmed' => 'مؤكَّد', 'preparing' => 'قيد التجهيز',
         'ready' => 'جاهز', 'shipped' => 'تم الشحن', 'delivered' => 'تم التسليم',
         'completed' => 'مكتمل', 'disputed' => 'محل نزاع', 'cancelled' => 'ملغي'];
?>
<div class="row g-3 mb-3">
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">مبيعات محقّقة (٣٠ يوماً)</div>
            <div class="stat-value"><?= e(money((float) ($summary['realised_sales'] ?? 0))) ?></div>
            <div class="stat-tile__meta">الطلبات المسلَّمة والمكتملة فقط</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">قيمة قيد التنفيذ</div>
            <div class="stat-value"><?= e(money((float) ($summary['pipeline_value'] ?? 0))) ?></div>
            <div class="stat-tile__meta">عدا الملغي والمسترد</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">طلبات جديدة</div>
            <div class="stat-value"><?= e(number_ar((int) ($summary['new_orders'] ?? 0))) ?></div>
            <div class="stat-tile__meta">بانتظار التأكيد</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">محل نزاع</div>
            <div class="stat-value"><?= e(number_ar((int) ($summary['disputed_orders'] ?? 0))) ?></div>
            <div class="stat-tile__meta">تحتاج معالجة</div>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-1">
        <?php foreach ($tabs as $value => $label): ?>
            <?php $count = $value === '' ? array_sum($counts) : ($counts[$value] ?? 0); ?>
            <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= e(url('/app/orders') . ($value === '' ? '' : '?status=' . $value)) ?>">
                <?= e($label) ?> <span class="np-badge np-badge--muted ms-1"><?= e(number_ar($count)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="<?= e(url('/app/orders')) ?>" class="d-flex gap-2">
        <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        <label class="visually-hidden" for="q">بحث في الطلبات</label>
        <input type="search" class="form-control form-control-sm" id="q" name="q"
               value="<?= e($filters['q']) ?>" placeholder="رقم الطلب أو اسم العميل…" style="min-width:14rem">
        <button type="submit" class="btn btn-sm btn-outline-primary"><?= __e('common.search') ?></button>
    </form>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($results['data'] === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">🧾</div>
                <p class="mb-1">لا توجد طلبات مطابقة.</p>
                <p class="fs-sm mb-0">تظهر الطلبات هنا فور شراء العملاء من أصنافك المنشورة.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>رقم الطلب</th><th>العميل</th><th>الأصناف</th><th>الإجمالي</th>
                        <th>الحالة</th><th>الدفع</th><th>التاريخ</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($results['data'] as $order): ?>
                            <tr>
                                <td>
                                    <a class="fw-bold numeric" dir="ltr"
                                       href="<?= e(url('/app/orders/' . $order['id'])) ?>">
                                        <?= e($order['order_number']) ?></a>
                                </td>
                                <td class="fs-sm">
                                    <?= e($order['customer_name']) ?>
                                    <?php if (!empty($order['governorate_name'])): ?>
                                        <div class="fs-xs text-muted-np"><?= e($order['governorate_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="numeric fs-sm"><?= e(number_ar((int) $order['item_count'])) ?></td>
                                <td class="numeric fs-sm fw-bold">
                                    <?= e(money((float) $order['total'], (string) $order['currency_code'])) ?></td>
                                <td>
                                    <span class="np-badge <?= e($orderService->statusBadgeClass((string) $order['status'])) ?>">
                                        <?= e($orderService->statusLabel((string) $order['status'])) ?></span>
                                </td>
                                <td class="fs-xs text-muted-np">
                                    <?= e(match ((string) $order['payment_status']) {
                                        'paid'            => 'مدفوع',
                                        'proof_submitted' => 'إثبات مُرسَل',
                                        'refunded'        => 'مُسترد',
                                        default           => 'غير مدفوع',
                                    }) ?>
                                </td>
                                <td class="fs-xs text-muted-np"><?= e(format_date($order['created_at'])) ?></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/app/orders/' . $order['id'])) ?>">فتح</a>
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
    <?php
    $pageUrl = static function (int $page) use ($filters): string {
        $params = array_filter($filters + ['page' => $page], static fn ($v) => $v !== '' && $v !== null);

        return url('/app/orders') . '?' . http_build_query($params);
    };
    ?>
    <nav class="d-flex justify-content-between align-items-center mt-3" aria-label="صفحات الطلبات">
        <span class="fs-sm text-muted-np">
            <?= e(__('common.page_of', ['current' => $results['page'], 'last' => $results['last_page']])) ?>
        </span>
        <div class="d-flex gap-2">
            <?php if ($results['page'] > 1): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= e($pageUrl($results['page'] - 1)) ?>">
                    <?= __e('common.previous') ?></a>
            <?php endif; ?>
            <?php if ($results['page'] < $results['last_page']): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= e($pageUrl($results['page'] + 1)) ?>">
                    <?= __e('common.next') ?></a>
            <?php endif; ?>
        </div>
    </nav>
<?php endif; ?>
