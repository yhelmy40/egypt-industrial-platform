<?php
/**
 * عرض السعر | Quotation detail and quote builder (§4.4).
 *
 * @var array<string,mixed> $quotation
 * @var array<int,array<string,mixed>> $items
 * @var \App\Services\QuotationService $quotationService
 * @var float $defaultVat
 */

$status     = (string) $quotation['status'];
$canQuote   = $status === 'requested' || $status === 'quoted';
// صفوف فارغة تُرسم من الخادم ليبقى النموذج صالحاً بلا جافاسكربت
$blankRows  = max(1, 3 - count($items));
?>
<a class="fs-sm text-muted-np" href="<?= e(url('/app/quotations')) ?>">→ كل عروض الأسعار</a>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-1 mb-3">
    <h1 class="h5 mb-0">
        عرض سعر <span class="numeric" dir="ltr"><?= e($quotation['quotation_number']) ?></span>
        <span class="np-badge <?= e($quotationService->statusBadgeClass($status)) ?>">
            <?= e($quotationService->statusLabel($status)) ?></span>
    </h1>
    <span class="fs-sm text-muted-np"><?= e(format_date($quotation['created_at'], true)) ?></span>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="np-card mb-3">
            <div class="np-card__header">طلب العميل</div>
            <div class="np-card__body">
                <?php if (!empty($quotation['listing_name'])): ?>
                    <p class="fs-sm text-muted-np mb-2">
                        بخصوص الصنف: <?= e($quotation['listing_name']) ?></p>
                <?php endif; ?>

                <p style="white-space:pre-line"><?= e($quotation['request_details']) ?></p>

                <dl class="row fs-sm mb-0">
                    <?php if ($quotation['requested_quantity'] !== null): ?>
                        <dt class="col-4 fw-normal text-muted-np">الكمية المطلوبة</dt>
                        <dd class="col-8"><?= e(number_ar((float) $quotation['requested_quantity'], 0)) ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($quotation['needed_by'])): ?>
                        <dt class="col-4 fw-normal text-muted-np">مطلوب قبل</dt>
                        <dd class="col-8"><?= e(format_date($quotation['needed_by'])) ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($quotation['governorate_name'])): ?>
                        <dt class="col-4 fw-normal text-muted-np">المحافظة</dt>
                        <dd class="col-8"><?= e($quotation['governorate_name']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if ($canQuote): ?>
            <div class="np-card" data-repeatable data-total-scope>
                <div class="np-card__header">
                    <?= $status === 'quoted' ? 'تعديل العرض المُرسَل' : 'بناء عرض السعر' ?>
                </div>
                <div class="np-card__body">
                    <form method="post" action="<?= e(url('/app/quotations/' . $quotation['id'] . '/quote')) ?>"
                          data-guard>
                        <?= csrf_field() ?>

                        <div data-repeat-body>
                            <?php
                            $rows = $items;
                            for ($i = 0; $i < $blankRows; $i++) {
                                $rows[] = [
                                    'description'     => '',
                                    'quantity'        => '1',
                                    'unit_of_measure' => '',
                                    'unit_price'      => '',
                                    'vat_rate'        => $defaultVat,
                                ];
                            }
                            ?>
                            <?php foreach ($rows as $index => $row): ?>
                                <div class="row g-2 align-items-end mb-2 pb-2 border-bottom border-np"
                                     data-repeat-row>
                                    <div class="col-md-4">
                                        <?php if ($index === 0): ?>
                                            <label class="form-label fs-sm" for="item_description_0">
                                                الوصف <span class="required">*</span></label>
                                        <?php endif; ?>
                                        <input type="text" class="form-control form-control-sm"
                                               <?= $index === 0 ? 'id="item_description_0"' : '' ?>
                                               name="item_description[]" maxlength="300"
                                               value="<?= e($row['description']) ?>">
                                    </div>
                                    <div class="col-md-2 col-4">
                                        <?php if ($index === 0): ?>
                                            <label class="form-label fs-sm" for="item_quantity_0">الكمية</label>
                                        <?php endif; ?>
                                        <input type="number" class="form-control form-control-sm" dir="ltr"
                                               <?= $index === 0 ? 'id="item_quantity_0"' : '' ?>
                                               name="item_quantity[]" min="0" step="0.001"
                                               data-line-quantity data-repeat-default="1"
                                               value="<?= e((string) $row['quantity']) ?>">
                                    </div>
                                    <div class="col-md-2 col-4">
                                        <?php if ($index === 0): ?>
                                            <label class="form-label fs-sm" for="item_unit_0">الوحدة</label>
                                        <?php endif; ?>
                                        <input type="text" class="form-control form-control-sm"
                                               <?= $index === 0 ? 'id="item_unit_0"' : '' ?>
                                               name="item_unit[]" maxlength="40"
                                               value="<?= e((string) ($row['unit_of_measure'] ?? '')) ?>">
                                    </div>
                                    <div class="col-md-2 col-4">
                                        <?php if ($index === 0): ?>
                                            <label class="form-label fs-sm" for="item_price_0">سعر الوحدة</label>
                                        <?php endif; ?>
                                        <input type="number" class="form-control form-control-sm" dir="ltr"
                                               <?= $index === 0 ? 'id="item_price_0"' : '' ?>
                                               name="item_price[]" min="0" step="0.01"
                                               data-line-price
                                               value="<?= e((string) $row['unit_price']) ?>">
                                    </div>
                                    <div class="col-md-1 col-4">
                                        <?php if ($index === 0): ?>
                                            <label class="form-label fs-sm" for="item_vat_0">ض.ق.م %</label>
                                        <?php endif; ?>
                                        <input type="number" class="form-control form-control-sm" dir="ltr"
                                               <?= $index === 0 ? 'id="item_vat_0"' : '' ?>
                                               name="item_vat[]" min="0" max="100" step="0.01"
                                               data-line-vat
                                               data-repeat-default="<?= e((string) $defaultVat) ?>"
                                               value="<?= e((string) $row['vat_rate']) ?>">
                                    </div>
                                    <div class="col-md-1 col-4">
                                        <button type="button" class="btn btn-sm btn-outline-danger w-100"
                                                data-repeat-remove aria-label="حذف السطر">×</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" class="btn btn-sm btn-outline-primary mb-3"
                                data-repeat-add hidden>+ سطر آخر</button>

                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label fs-sm" for="delivery_fee">رسوم التوصيل</label>
                                <input type="number" class="form-control form-control-sm" dir="ltr"
                                       id="delivery_fee" name="delivery_fee" min="0" step="0.01"
                                       data-delivery-fee
                                       value="<?= e((string) ($quotation['delivery_fee'] ?? '0')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fs-sm" for="lead_time_days">مدة التنفيذ (أيام)</label>
                                <input type="number" class="form-control form-control-sm" dir="ltr"
                                       id="lead_time_days" name="lead_time_days" min="0" max="365"
                                       value="<?= e((string) ($quotation['lead_time_days'] ?? '')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fs-sm" for="valid_until">صالح حتى</label>
                                <input type="date" class="form-control form-control-sm" dir="ltr"
                                       id="valid_until" name="valid_until"
                                       value="<?= e((string) ($quotation['valid_until'] ?? '')) ?>">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label fs-sm" for="terms">الشروط والملاحظات</label>
                            <textarea class="form-control form-control-sm" id="terms" name="terms"
                                      rows="3" maxlength="2000"
                            ><?= e((string) ($quotation['terms'] ?? '')) ?></textarea>
                        </div>

                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                            <p class="fs-sm mb-0">
                                إجمالي تقديري:
                                <strong class="numeric" data-total-output>0.00</strong>
                                <span class="text-muted-np"><?= e((string) $quotation['currency_code']) ?></span>
                                <span class="fs-xs text-muted-np d-block">
                                    رقم إرشادي — المبلغ المُعتمد يُحسب على الخادم عند الإرسال.
                                </span>
                            </p>
                            <button type="submit" class="btn btn-primary">
                                <?= $status === 'quoted' ? 'إرسال العرض المُعدَّل' : 'إرسال العرض للعميل' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($items !== []): ?>
            <div class="np-card">
                <div class="np-card__header">العرض المقدَّم</div>
                <div class="np-card__body p-0">
                    <div class="table-scroll" style="border:0">
                        <table class="np-table">
                            <thead><tr>
                                <th>الوصف</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td class="fs-sm"><?= e($item['description']) ?></td>
                                        <td class="numeric fs-sm">
                                            <?= e(number_ar((float) $item['quantity'], 0)) ?>
                                            <?= e((string) ($item['unit_of_measure'] ?? '')) ?>
                                        </td>
                                        <td class="numeric fs-sm"><?= e(money((float) $item['unit_price'])) ?></td>
                                        <td class="numeric fs-sm fw-bold">
                                            <?= e(money((float) $item['line_total'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="np-card mb-3">
            <div class="np-card__header">الطالب</div>
            <div class="np-card__body">
                <dl class="row fs-sm mb-0">
                    <dt class="col-5 fw-normal text-muted-np">الاسم</dt>
                    <dd class="col-7"><?= e($quotation['customer_name']) ?></dd>

                    <dt class="col-5 fw-normal text-muted-np">الهاتف</dt>
                    <dd class="col-7 numeric" dir="ltr"><?= e($quotation['customer_phone']) ?></dd>

                    <?php if (!empty($quotation['customer_email'])): ?>
                        <dt class="col-5 fw-normal text-muted-np">البريد</dt>
                        <dd class="col-7" dir="ltr" style="word-break:break-all">
                            <?= e($quotation['customer_email']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if ($quotation['total'] !== null): ?>
            <div class="np-card mb-3">
                <div class="np-card__header">ملخّص العرض</div>
                <div class="np-card__body">
                    <dl class="row fs-sm mb-0">
                        <dt class="col-6 fw-normal text-muted-np">المجموع</dt>
                        <dd class="col-6 numeric"><?= e(money((float) $quotation['subtotal'])) ?></dd>

                        <dt class="col-6 fw-normal text-muted-np">الضريبة</dt>
                        <dd class="col-6 numeric"><?= e(money((float) $quotation['vat_amount'])) ?></dd>

                        <dt class="col-6 fw-normal text-muted-np">التوصيل</dt>
                        <dd class="col-6 numeric"><?= e(money((float) $quotation['delivery_fee'])) ?></dd>

                        <dt class="col-6 fw-bold">الإجمالي</dt>
                        <dd class="col-6 numeric fw-bold">
                            <?= e(money((float) $quotation['total'], (string) $quotation['currency_code'])) ?></dd>
                    </dl>

                    <?php if (!empty($quotation['valid_until'])): ?>
                        <p class="fs-xs text-muted-np mb-0 mt-2">
                            صالح حتى <?= e(format_date($quotation['valid_until'])) ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($quotation['converted_order_id'])): ?>
            <div class="np-card">
                <div class="np-card__body">
                    <p class="fs-sm mb-2">قبِل العميل هذا العرض وتحوّل إلى طلب.</p>
                    <a class="btn btn-sm btn-primary"
                       href="<?= e(url('/app/orders/' . $quotation['converted_order_id'])) ?>">فتح الطلب</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
