<?php
/** طلباتي | A registered customer's orders. */
?>
<section class="container py-4">
    <h1 class="h4 mb-3">طلباتي</h1>

    <?php if ($orders === []): ?>
        <div class="np-card"><div class="np-empty">
            <div class="np-empty__icon" aria-hidden="true">📦</div>
            <p class="mb-3">لا توجد طلبات بعد.</p>
            <a class="btn btn-primary" href="<?= e(url('/marketplace')) ?>">تصفّح السوق</a>
        </div></div>
    <?php else: ?>
        <div class="np-card"><div class="np-card__body p-0"><div class="table-scroll" style="border:0">
            <table class="np-table">
                <thead><tr><th>رقم الطلب</th><th>المنشأة</th><th>التاريخ</th><th>الإجمالي</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td dir="ltr"><?= e($order['order_number']) ?></td>
                            <td class="fs-sm"><?= e($order['trading_name'] ?: $order['legal_name']) ?></td>
                            <td class="fs-sm text-muted-np"><?= e(format_date($order['created_at'])) ?></td>
                            <td class="numeric"><?= e(money((float) $order['total'])) ?></td>
                            <td><span class="np-badge <?= e($orderService->statusBadgeClass((string) $order['status'])) ?>">
                                <?= e($orderService->statusLabel((string) $order['status'])) ?></span></td>
                            <td><a class="btn btn-sm btn-outline-primary"
                                   href="<?= e(url('/orders/track/' . $order['tracking_token'])) ?>">تتبّع</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></div></div>
    <?php endif; ?>
</section>
