<?php
/**
 * تفاصيل الطلب | Seller order detail (§4.4).
 *
 * @var array<string,mixed> $order
 * @var array<int,string> $availableActions
 * @var \App\Services\OrderService $orderService
 * @var array<int,array<string,mixed>> $payments
 */

// إجراءات العميل (مثل فتح نزاع) تُعرض للاطّلاع لا للتنفيذ من هنا
$sellerActions = array_values(array_filter(
    $availableActions,
    static fn (string $action): bool => !$orderService->isCustomerAction($action),
));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <a class="fs-sm text-muted-np" href="<?= e(url('/app/orders')) ?>">→ كل الطلبات</a>
        <h1 class="h5 mb-0 mt-1">
            الطلب <span class="numeric" dir="ltr"><?= e($order['order_number']) ?></span>
            <span class="np-badge <?= e($orderService->statusBadgeClass((string) $order['status'])) ?>">
                <?= e($orderService->statusLabel((string) $order['status'])) ?></span>
        </h1>
    </div>
    <span class="fs-sm text-muted-np"><?= e(format_date($order['created_at'], true)) ?></span>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <?php // ─── الأصناف ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">أصناف الطلب</div>
            <div class="np-card__body p-0">
                <div class="table-scroll" style="border:0">
                    <table class="np-table">
                        <thead><tr>
                            <th>الصنف</th><th>الكمية</th><th>سعر الوحدة</th><th>الضريبة</th><th>الإجمالي</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($order['items'] as $item): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($item['listing_slug'])): ?>
                                            <a href="<?= e(url('/marketplace/' . $item['listing_slug'])) ?>"
                                               target="_blank" rel="noopener"><?= e($item['name_ar']) ?></a>
                                        <?php else: ?>
                                            <?= e($item['name_ar']) ?>
                                        <?php endif; ?>
                                        <?php if (!empty($item['sku'])): ?>
                                            <div class="fs-xs text-muted-np" dir="ltr"><?= e($item['sku']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="numeric fs-sm">
                                        <?= e(number_ar((float) $item['quantity'], 0)) ?>
                                        <?= e($item['unit_of_measure'] ?? '') ?>
                                    </td>
                                    <td class="numeric fs-sm"><?= e(money((float) $item['unit_price'])) ?></td>
                                    <td class="numeric fs-sm"><?= e(money((float) $item['line_vat'])) ?></td>
                                    <td class="numeric fs-sm fw-bold"><?= e(money((float) $item['line_total'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-start fs-sm">المجموع قبل الضريبة</td>
                                <td class="numeric fs-sm"><?= e(money((float) $order['subtotal'])) ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-start fs-sm">ضريبة القيمة المضافة</td>
                                <td class="numeric fs-sm"><?= e(money((float) $order['vat_amount'])) ?></td>
                            </tr>
                            <?php if ((float) $order['delivery_fee'] > 0): ?>
                                <tr>
                                    <td colspan="4" class="text-start fs-sm">رسوم التوصيل</td>
                                    <td class="numeric fs-sm"><?= e(money((float) $order['delivery_fee'])) ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td colspan="4" class="text-start fw-bold">الإجمالي المستحق</td>
                                <td class="numeric fw-bold">
                                    <?= e(money((float) $order['total'], (string) $order['currency_code'])) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <?php // ─── سجل الحالة ─── ?>
        <div class="np-card">
            <div class="np-card__header">سجل الحالة</div>
            <div class="np-card__body">
                <?php foreach ($order['history'] as $entry): ?>
                    <div class="step-item">
                        <span class="step-item__marker" aria-hidden="true">•</span>
                        <div>
                            <div class="step-item__title fs-sm">
                                <?= e($orderService->statusLabel((string) $entry['to_status'])) ?>
                                <?php if (!empty($entry['from_status'])): ?>
                                    <span class="fs-xs text-muted-np fw-normal">
                                        (من <?= e($orderService->statusLabel((string) $entry['from_status'])) ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="step-item__desc mb-0">
                                <?= e(match ((string) $entry['actor_type']) {
                                    'customer' => 'العميل',
                                    'platform' => 'إدارة المنصة',
                                    'system'   => 'النظام',
                                    default    => 'المنشأة',
                                }) ?>
                                <?php if (!empty($entry['actor_name'])): ?>
                                    · <?= e($entry['actor_name']) ?>
                                <?php endif; ?>
                                · <?= e(format_date($entry['created_at'], true)) ?>
                                <?php if (!empty($entry['note'])): ?>
                                    <br><?= e($entry['note']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php // ─── الإجراءات ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">الإجراءات المتاحة</div>
            <div class="np-card__body">
                <?php if ($sellerActions === []): ?>
                    <p class="fs-sm text-muted-np mb-0">لا توجد إجراءات متاحة على هذه الحالة.</p>
                <?php else: ?>
                    <?php foreach ($sellerActions as $action): ?>
                        <?php $needsReason = $orderService->requiresReason($action); ?>
                        <form method="post" action="<?= e(url('/app/orders/' . $order['id'] . '/status')) ?>"
                              class="mb-2" data-guard>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= e($action) ?>">
                            <?php if ($needsReason): ?>
                                <details>
                                    <summary class="btn btn-sm btn-outline-danger w-100" style="cursor:pointer">
                                        <?= e($orderService->actionLabel($action)) ?>
                                    </summary>
                                    <label class="form-label fs-sm mt-2" for="note_<?= e($action) ?>">
                                        السبب <span class="text-danger">*</span>
                                    </label>
                                    <textarea class="form-control form-control-sm" id="note_<?= e($action) ?>"
                                              name="note" rows="2" required maxlength="500"></textarea>
                                    <button type="submit" class="btn btn-sm btn-danger w-100 mt-2">
                                        تأكيد: <?= e($orderService->actionLabel($action)) ?>
                                    </button>
                                </details>
                            <?php else: ?>
                                <button type="submit" class="btn btn-sm btn-primary w-100">
                                    <?= e($orderService->actionLabel($action)) ?>
                                </button>
                            <?php endif; ?>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (in_array('dispute', $availableActions, true)): ?>
                    <p class="fs-xs text-muted-np mb-0 mt-2">
                        فتح النزاع حق للعميل وحده، ولا يمكن للمنشأة تنفيذه نيابةً عنه.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <?php // ─── العميل ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">بيانات العميل</div>
            <div class="np-card__body">
                <dl class="row fs-sm mb-0">
                    <dt class="col-5 fw-normal text-muted-np">الاسم</dt>
                    <dd class="col-7"><?= e($order['customer_name']) ?></dd>

                    <dt class="col-5 fw-normal text-muted-np">الهاتف</dt>
                    <dd class="col-7 numeric" dir="ltr"><?= e($order['customer_phone']) ?></dd>

                    <?php if (!empty($order['customer_email'])): ?>
                        <dt class="col-5 fw-normal text-muted-np">البريد</dt>
                        <dd class="col-7" dir="ltr" style="word-break:break-all"><?= e($order['customer_email']) ?></dd>
                    <?php endif; ?>

                    <?php if (!empty($order['governorate_name'])): ?>
                        <dt class="col-5 fw-normal text-muted-np">المحافظة</dt>
                        <dd class="col-7">
                            <?= e($order['governorate_name']) ?>
                            <?= !empty($order['city_name']) ? ' — ' . e($order['city_name']) : '' ?>
                        </dd>
                    <?php endif; ?>

                    <?php if (!empty($order['delivery_address'])): ?>
                        <dt class="col-5 fw-normal text-muted-np">العنوان</dt>
                        <dd class="col-7"><?= e($order['delivery_address']) ?></dd>
                    <?php endif; ?>
                </dl>

                <?php if (!empty($order['customer_note'])): ?>
                    <div class="alert alert-info mt-3 mb-0">
                        <strong class="fs-sm">ملاحظة العميل:</strong>
                        <p class="fs-sm mb-0"><?= e($order['customer_note']) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php // ─── الدفع ─── ?>
        <div class="np-card">
            <div class="np-card__header">الدفع</div>
            <div class="np-card__body">
                <dl class="row fs-sm mb-0">
                    <dt class="col-5 fw-normal text-muted-np">الوسيلة</dt>
                    <dd class="col-7"><?= e($order['payment_method_name'] ?? 'غير محدَّدة') ?></dd>

                    <dt class="col-5 fw-normal text-muted-np">الحالة</dt>
                    <dd class="col-7">
                        <?= e(match ((string) $order['payment_status']) {
                            'paid'            => 'مدفوع',
                            'proof_submitted' => 'إثبات مُرسَل — بانتظار المراجعة',
                            'refunded'        => 'مُسترد',
                            default           => 'غير مدفوع',
                        }) ?>
                    </dd>
                </dl>

                <?php if ($payments !== []): ?>
                    <hr class="my-3">
                    <?php foreach ($payments as $payment): ?>
                        <div class="fs-sm d-flex justify-content-between">
                            <span><?= e(money((float) $payment['amount'])) ?></span>
                            <span class="text-muted-np fs-xs"><?= e(format_date($payment['created_at'])) ?></span>
                        </div>
                        <?php if (!empty($payment['reference'])): ?>
                            <div class="fs-xs text-muted-np" dir="ltr"><?= e($payment['reference']) ?></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>

                <p class="fs-xs text-muted-np mb-0 mt-3">
                    التحصيل يتم خارج المنصة وفق الوسيلة المتفق عليها. المنصة لا تحتفظ ببيانات بطاقات
                    ولا تنفّذ تحصيلاً إلكترونياً في هذه المرحلة.
                </p>
            </div>
        </div>
    </div>
</div>
