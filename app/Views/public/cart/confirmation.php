<?php
/**
 * تأكيد الطلب | Order confirmation.
 * رابط التتبّع هو وسيلة الزائر الوحيدة لمتابعة طلبه، لذا يُعرض بوضوح.
 * @var array<int,array<string,mixed>> $orders
 */
?>
<section class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="np-card">
                <div class="np-card__body text-center py-4">
                    <div class="intent-card__icon mx-auto mb-3" style="width:64px;height:64px;font-size:1.8rem">✅</div>
                    <h1 class="h4 mb-2">تم استلام طلبك</h1>
                    <p class="text-muted-np mb-0">
                        <?php if (count($orders) > 1): ?>
                            تم إنشاء <?= e(number_ar(count($orders))) ?> طلبات — طلب لكل منشأة بائعة.
                        <?php else: ?>
                            ستتواصل معك المنشأة لتأكيد التفاصيل.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php foreach ($orders as $order): ?>
                <div class="np-card mt-3">
                    <div class="np-card__header">
                        <span>الطلب <span dir="ltr"><?= e($order['order_number']) ?></span></span>
                        <span class="numeric fw-bold"><?= e(money((float) $order['total'])) ?></span>
                    </div>
                    <div class="np-card__body">
                        <p class="fs-sm mb-2">
                            المنشأة: <strong><?= e($order['trading_name'] ?: $order['legal_name']) ?></strong>
                        </p>
                        <p class="fs-sm text-muted-np mb-3">
                            احفظ رابط التتبّع التالي — تحتاجه لمتابعة طلبك إن لم يكن لديك حساب:
                        </p>
                        <div class="d-flex gap-2 flex-wrap">
                            <a class="btn btn-sm btn-primary"
                               href="<?= e(url('/orders/track/' . $order['tracking_token'])) ?>">
                                تتبّع الطلب
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="text-center mt-4">
                <a class="btn btn-outline-primary" href="<?= e(url('/marketplace')) ?>">متابعة التسوّق</a>
            </div>
        </div>
    </div>
</section>
