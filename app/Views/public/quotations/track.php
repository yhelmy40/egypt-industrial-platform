<?php
/**
 * متابعة عرض السعر والرد عليه | Track and respond to a quotation.
 * @var array<string,mixed> $quotation
 * @var array<int,array<string,mixed>> $items
 * @var App\Services\QuotationService $quotationService
 */
$status  = (string) $quotation['status'];
$expired = $quotation['valid_until'] !== null && strtotime((string) $quotation['valid_until']) < strtotime('today');
?>
<section class="container py-4">
    <div class="row justify-content-center"><div class="col-lg-8">
        <div class="np-card">
            <div class="np-card__header">
                <span>عرض سعر <span dir="ltr"><?= e($quotation['quotation_number']) ?></span></span>
                <span class="np-badge <?= e($quotationService->statusBadgeClass($status)) ?>">
                    <?= e($quotationService->statusLabel($status)) ?>
                </span>
            </div>
            <div class="np-card__body">
                <p class="fs-sm text-muted-np">
                    المنشأة: <a href="<?= e(url('/business/' . $quotation['seller_slug'])) ?>">
                        <?= e($quotation['trading_name'] ?: $quotation['legal_name']) ?></a>
                    · تاريخ الطلب: <?= e(format_date($quotation['created_at'])) ?>
                </p>

                <div class="np-card mb-3">
                    <div class="np-card__body">
                        <h2 class="h6">طلبك</h2>
                        <p class="fs-sm mb-0" style="white-space:pre-line"><?= e($quotation['request_details']) ?></p>
                    </div>
                </div>

                <?php if ($status === 'requested'): ?>
                    <div class="alert alert-info mb-0" role="alert">
                        طلبك قيد المراجعة لدى المنشأة، وسيصلك عرض السعر قريباً.
                    </div>
                <?php else: ?>
                    <h2 class="h6 mb-2">تفاصيل العرض</h2>
                    <div class="table-scroll mb-3">
                        <table class="np-table">
                            <thead><tr><th>البند</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?= e($item['description']) ?></td>
                                        <td class="numeric"><?= e(number_ar((float) $item['quantity'], 0)) ?>
                                            <?= e($item['unit_of_measure'] ?? '') ?></td>
                                        <td class="numeric"><?= e(money((float) $item['unit_price'])) ?></td>
                                        <td class="numeric"><?= e(money((float) $item['line_total'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <dl class="row fs-sm">
                        <dt class="col-8 fw-normal text-muted-np text-start">الأصناف</dt>
                        <dd class="col-4 numeric text-start"><?= e(money((float) $quotation['subtotal'])) ?></dd>
                        <dt class="col-8 fw-normal text-muted-np text-start">الضريبة</dt>
                        <dd class="col-4 numeric text-start"><?= e(money((float) $quotation['vat_amount'])) ?></dd>
                        <dt class="col-8 fw-normal text-muted-np text-start">التوصيل</dt>
                        <dd class="col-4 numeric text-start"><?= e(money((float) $quotation['delivery_fee'])) ?></dd>
                        <dt class="col-8 text-start">الإجمالي</dt>
                        <dd class="col-4 numeric fw-bold text-primary text-start"><?= e(money((float) $quotation['total'])) ?></dd>
                    </dl>

                    <?php if (!empty($quotation['terms'])): ?>
                        <div class="np-card mb-3"><div class="np-card__body">
                            <h3 class="h6">الشروط</h3>
                            <p class="fs-sm mb-0" style="white-space:pre-line"><?= e($quotation['terms']) ?></p>
                        </div></div>
                    <?php endif; ?>

                    <p class="fs-sm text-muted-np">
                        <?php if ($quotation['lead_time_days'] !== null): ?>
                            مدة التنفيذ: <?= e(number_ar((int) $quotation['lead_time_days'])) ?> يوم ·
                        <?php endif; ?>
                        <?php if ($quotation['valid_until'] !== null): ?>
                            صالح حتى: <?= e(format_date($quotation['valid_until'])) ?>
                        <?php endif; ?>
                    </p>

                    <?php if ($status === 'quoted' && !$expired): ?>
                        <div class="d-flex gap-2">
                            <form method="post" action="<?= e(url('/quotations/track/' . $quotation['tracking_token'] . '/respond')) ?>" data-guard>
                                <?= csrf_field() ?>
                                <input type="hidden" name="decision" value="accept">
                                <button type="submit" class="btn btn-accent"
                                        data-confirm="سيتحوّل العرض إلى طلب مؤكَّد. هل تريد المتابعة؟">
                                    قبول العرض وتحويله إلى طلب
                                </button>
                            </form>
                            <form method="post" action="<?= e(url('/quotations/track/' . $quotation['tracking_token'] . '/respond')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="decision" value="reject">
                                <button type="submit" class="btn btn-outline-danger">اعتذار عن العرض</button>
                            </form>
                        </div>
                    <?php elseif ($expired && $status === 'quoted'): ?>
                        <div class="alert alert-warning mb-0" role="alert">انتهت صلاحية هذا العرض.</div>
                    <?php elseif ($status === 'accepted'): ?>
                        <div class="alert alert-success mb-0" role="alert">
                            تم قبول العرض وتحويله إلى طلب.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div></div>
</section>
