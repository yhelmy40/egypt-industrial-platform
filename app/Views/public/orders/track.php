<?php
/**
 * تتبّع الطلب | Order tracking (guest-accessible via token).
 * @var array<string,mixed> $order
 * @var App\Services\OrderService $orderService
 * @var bool $canReview
 */
$statuses = ['new','confirmed','preparing','ready','shipped','delivered','completed'];
$current  = array_search($order['status'], $statuses, true);
?>
<section class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="np-card mb-3">
                <div class="np-card__header">
                    <span>الطلب <span dir="ltr"><?= e($order['order_number']) ?></span></span>
                    <span class="np-badge <?= e($orderService->statusBadgeClass((string) $order['status'])) ?>">
                        <?= e($orderService->statusLabel((string) $order['status'])) ?>
                    </span>
                </div>
                <div class="np-card__body">
                    <p class="fs-sm text-muted-np mb-3">
                        المنشأة: <a href="<?= e(url('/business/' . $order['seller_slug'])) ?>">
                            <?= e($order['seller_trading_name'] ?: $order['seller_legal_name']) ?></a>
                        · تاريخ الطلب: <?= e(format_date($order['created_at'], true)) ?>
                    </p>

                    <?php if ($current !== false): ?>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <?php foreach ($statuses as $index => $status): ?>
                                <span class="np-badge <?= $index <= $current ? 'np-badge--success' : 'np-badge--draft' ?>">
                                    <?= e($orderService->statusLabel($status)) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (in_array($order['status'], ['cancelled','disputed','refunded'], true)): ?>
                        <div class="alert alert-warning fs-sm" role="alert">
                            حالة الطلب: <?= e($orderService->statusLabel((string) $order['status'])) ?>
                            <?php if (!empty($order['cancelled_reason'])): ?>
                                — <?= e($order['cancelled_reason']) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="table-scroll">
                        <table class="np-table">
                            <thead>
                                <tr><th>الصنف</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order['items'] as $item): ?>
                                    <tr>
                                        <td><?= e($item['name_ar']) ?></td>
                                        <td class="numeric"><?= e(number_ar((float) $item['quantity'], 0)) ?>
                                            <?= e($item['unit_of_measure'] ?? '') ?></td>
                                        <td class="numeric"><?= e(money((float) $item['unit_price'])) ?></td>
                                        <td class="numeric"><?= e(money((float) $item['line_total'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <dl class="row fs-sm mt-3 mb-0">
                        <dt class="col-8 fw-normal text-muted-np text-start">الأصناف</dt>
                        <dd class="col-4 numeric text-start"><?= e(money((float) $order['subtotal'])) ?></dd>
                        <dt class="col-8 fw-normal text-muted-np text-start">الضريبة</dt>
                        <dd class="col-4 numeric text-start"><?= e(money((float) $order['vat_amount'])) ?></dd>
                        <dt class="col-8 fw-normal text-muted-np text-start">التوصيل</dt>
                        <dd class="col-4 numeric text-start"><?= e(money((float) $order['delivery_fee'])) ?></dd>
                        <dt class="col-8 text-start">الإجمالي</dt>
                        <dd class="col-4 numeric fw-bold text-primary text-start"><?= e(money((float) $order['total'])) ?></dd>
                    </dl>

                    <?php if (!empty($order['payment_instructions'])): ?>
                        <div class="alert alert-info fs-sm mt-3 mb-0" role="alert">
                            <strong><?= e($order['payment_method_name']) ?>:</strong>
                            <?= e($order['payment_instructions']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php // ── التقييم بعد الإتمام ── ?>
            <?php if ($canReview): ?>
                <div class="np-card mb-3">
                    <div class="np-card__header">قيّم تجربتك</div>
                    <form method="post" action="<?= e(url('/orders/track/' . $order['tracking_token'] . '/review')) ?>" data-guard>
                        <div class="np-card__body">
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label class="form-label" for="rating">التقييم<span class="required">*</span></label>
                                <select class="form-select" id="rating" name="rating" required style="max-width:12rem">
                                    <option value="5">★★★★★ ممتاز</option>
                                    <option value="4">★★★★ جيد جداً</option>
                                    <option value="3">★★★ جيد</option>
                                    <option value="2">★★ مقبول</option>
                                    <option value="1">★ ضعيف</option>
                                </select>
                            </div>
                            <div class="mb-0">
                                <label class="form-label" for="comment">تعليقك</label>
                                <textarea class="form-control" id="comment" name="comment" rows="3" maxlength="1500"></textarea>
                            </div>
                        </div>
                        <div class="np-card__footer text-start">
                            <button type="submit" class="btn btn-primary">إرسال التقييم</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php // ── فتح نزاع ── ?>
            <?php if (in_array($order['status'], ['shipped','delivered','completed'], true)): ?>
                <div class="np-card mb-3">
                    <div class="np-card__header">هل هناك مشكلة في الطلب؟</div>
                    <form method="post" action="<?= e(url('/orders/track/' . $order['tracking_token'] . '/dispute')) ?>" data-guard>
                        <div class="np-card__body">
                            <?= csrf_field() ?>
                            <label class="form-label" for="reason">اشرح المشكلة</label>
                            <textarea class="form-control" id="reason" name="reason" rows="3"
                                      minlength="10" maxlength="1000" required></textarea>
                            <div class="form-text">سيصل البلاغ إلى المنشأة وإلى فريق المنصة.</div>
                        </div>
                        <div class="np-card__footer text-start">
                            <button type="submit" class="btn btn-outline-danger btn-sm"
                                    data-confirm="سيتم فتح نزاع على هذا الطلب. هل تريد المتابعة؟">
                                فتح نزاع
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <div class="np-card">
                <div class="np-card__header">سجل الطلب</div>
                <div class="np-card__body">
                    <?php foreach ($order['history'] as $entry): ?>
                        <div class="step-item">
                            <span class="step-item__marker" aria-hidden="true">•</span>
                            <div>
                                <div class="step-item__title fs-sm">
                                    <?= e($orderService->statusLabel((string) $entry['to_status'])) ?>
                                </div>
                                <p class="step-item__desc mb-0">
                                    <?= e(format_date($entry['created_at'], true)) ?>
                                    <?php if (!empty($entry['note'])): ?><br><?= e($entry['note']) ?><?php endif; ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>
