<?php
/**
 * الفاتورة | The invoice (§4.10, §14).
 *
 * الشاشة تتبدّل بحسب الحالة: المسودة تُحرَّر، والصادرة تُقرأ وتُحصَّل. زرّ
 * التحرير لا يُعرض أصلاً بعد الإصدار، فلا يُدعى المستخدم لإجراء سترفضه الخدمة.
 *
 * @var array<string,mixed> $invoice
 * @var array<int,array<string,mixed>> $lines
 * @var array<int,array<string,mixed>> $payments
 * @var array<int,array<string,mixed>> $items
 * @var array<int,array<string,mixed>> $customers
 * @var bool $editable
 * @var string $disclaimer
 * @var \App\Services\InvoiceService $service
 */
$invoiceId = (int) $invoice['id'];
$status    = (string) $invoice['status'];
$balance   = (float) $invoice['balance_due'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">
            فاتورة <span class="numeric" dir="ltr"><?= e($invoice['invoice_number']) ?></span>
            <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
                <?= e($service->statusLabel($status)) ?></span>
        </h1>
        <p class="fs-sm text-muted-np mb-0">
            <?= e($invoice['customer_name_ar']) ?>
            <?php if ($invoice['customer_id'] !== null): ?>
                · <a href="<?= e(url('/app/customers/' . $invoice['customer_id'])) ?>">ملفّ العميل</a>
            <?php endif; ?>
            · صدرت <?= e(format_date((string) $invoice['issue_date'])) ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($status !== 'draft'): ?>
            <a class="btn btn-sm btn-outline-primary"
               href="<?= e(url('/app/invoices/' . $invoiceId . '/print')) ?>">طباعة</a>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/app/invoices')) ?>">رجوع</a>
    </div>
</div>

<?php if ($status === 'cancelled'): ?>
    <div class="alert alert-warning" role="alert">
        <strong>فاتورة ملغاة:</strong> <?= e($invoice['cancelled_reason_ar'] ?? '') ?>
        <?php if ($invoice['cancelled_at'] !== null): ?>
            <span class="fs-xs">(<?= e(format_date((string) $invoice['cancelled_at'], true)) ?>)</span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <!-- البنود | Lines -->
        <div class="np-card mb-3">
            <div class="np-card__header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">البنود</h2>
                <?php if (!$editable): ?>
                    <span class="fs-xs text-muted-np">لا تُعدَّل بعد الإصدار</span>
                <?php endif; ?>
            </div>
            <div class="np-card__body p-0">
                <?php if ($lines === []): ?>
                    <div class="np-empty"><p class="mb-0 fs-sm">لا بنود بعد.</p></div>
                <?php else: ?>
                    <div class="table-scroll" style="border:0">
                        <table class="np-table">
                            <thead><tr>
                                <th>البند</th><th>الكمية</th><th>سعر الوحدة</th>
                                <th>الضريبة</th><th>الإجمالي</th><?= $editable ? '<th></th>' : '' ?>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($lines as $line): ?>
                                    <tr>
                                        <td class="fs-sm"><?= e($line['name_ar']) ?></td>
                                        <td class="numeric fs-sm">
                                            <?= e(number_ar((float) $line['quantity'], 2)) ?>
                                            <span class="fs-xs text-muted-np"><?= e($line['unit_of_measure'] ?? '') ?></span>
                                        </td>
                                        <td class="numeric fs-sm"><?= e(money((float) $line['unit_price'])) ?></td>
                                        <td class="numeric fs-xs text-muted-np">
                                            <?= e(number_ar((float) $line['vat_rate'], 2)) ?>٪</td>
                                        <td class="numeric fs-sm fw-bold"><?= e(money((float) $line['line_total'])) ?></td>
                                        <?php if ($editable): ?>
                                            <td>
                                                <form method="post"
                                                      action="<?= e(url('/app/invoices/' . $invoiceId . '/lines/remove')) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="line_id"
                                                           value="<?= e((string) $line['id']) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">حذف</button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($editable): ?>
                <div class="np-card__footer">
                    <form method="post" action="<?= e(url('/app/invoices/' . $invoiceId . '/lines')) ?>"
                          class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <div class="col-md-4">
                            <label class="form-label fs-sm" for="item_id">صنف من مخزونك</label>
                            <select class="form-select form-select-sm" id="item_id" name="item_id">
                                <option value="">— بند حرّ —</option>
                                <?php foreach ($items as $item): ?>
                                    <option value="<?= e((string) $item['id']) ?>">
                                        <?= e($item['name_ar']) ?>
                                        <?php if ((int) $item['track_stock'] === 1): ?>
                                            (متاح <?= e(number_ar((float) $item['quantity_on_hand'], 2)) ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fs-sm" for="line_name">أو اسم البند</label>
                            <input type="text" class="form-control form-control-sm" id="line_name"
                                   name="name_ar" maxlength="200">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fs-sm" for="line_quantity">الكمية</label>
                            <input type="number" step="0.001" min="0.001" dir="ltr"
                                   class="form-control form-control-sm numeric"
                                   id="line_quantity" name="quantity" required value="1">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fs-sm" for="line_price">سعر الوحدة</label>
                            <input type="number" step="0.01" min="0" dir="ltr"
                                   class="form-control form-control-sm numeric"
                                   id="line_price" name="unit_price">
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-sm btn-primary w-100">إضافة</button>
                        </div>
                    </form>
                    <p class="fs-xs text-muted-np mb-0 mt-2">
                        اترك السعر فارغاً ليُؤخذ سعر بيع الصنف. السعر يُنسخ في البند، فتغييره لاحقاً
                        لا يمسّ هذه الفاتورة.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- المقبوضات | Receipts -->
        <div class="np-card">
            <div class="np-card__header"><h2 class="h6 mb-0">المقبوضات</h2></div>
            <div class="np-card__body">
                <?php if ($payments === []): ?>
                    <p class="fs-sm text-muted-np">لا مقبوضات مسجَّلة.</p>
                <?php else: ?>
                    <div class="table-scroll mb-3" style="border:0">
                        <table class="np-table">
                            <thead><tr>
                                <th>الإيصال</th><th>التاريخ</th><th>المبلغ</th>
                                <th>الوسيلة</th><th>المرجع</th><th></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td class="numeric fs-sm" dir="ltr"><?= e($payment['receipt_number']) ?></td>
                                        <td class="fs-xs text-muted-np">
                                            <?= e(format_date((string) $payment['paid_at'])) ?></td>
                                        <td class="numeric fs-sm fw-bold"><?= e(money((float) $payment['amount'])) ?></td>
                                        <td class="fs-xs"><?= e(match ((string) $payment['method']) {
                                            'cash'          => 'نقداً',
                                            'bank_transfer' => 'تحويل بنكي',
                                            'cheque'        => 'شيك',
                                            'wallet'        => 'محفظة',
                                            default         => 'أخرى',
                                        }) ?></td>
                                        <td class="fs-xs text-muted-np"><?= e($payment['reference_ar'] ?? '—') ?></td>
                                        <td>
                                            <form method="post"
                                                  action="<?= e(url('/app/invoices/' . $invoiceId . '/payments/remove')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="payment_id"
                                                       value="<?= e((string) $payment['id']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                        data-confirm="حذف هذا الإيصال؟">حذف</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if (in_array($status, ['issued', 'partially_paid'], true)): ?>
                    <form method="post" action="<?= e(url('/app/invoices/' . $invoiceId . '/payments')) ?>"
                          class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <div class="col-md-3">
                            <label class="form-label fs-sm" for="amount">المبلغ المستلَم</label>
                            <input type="number" step="0.01" min="0.01" dir="ltr"
                                   class="form-control form-control-sm numeric"
                                   id="amount" name="amount" required
                                   max="<?= e(number_format($balance, 2, '.', '')) ?>"
                                   value="<?= e(number_format($balance, 2, '.', '')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fs-sm" for="method">الوسيلة</label>
                            <select class="form-select form-select-sm" id="method" name="method">
                                <?php foreach (['cash' => 'نقداً', 'bank_transfer' => 'تحويل بنكي',
                                                'cheque' => 'شيك', 'wallet' => 'محفظة إلكترونية',
                                                'other' => 'أخرى'] as $key => $label): ?>
                                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fs-sm" for="paid_at">التاريخ</label>
                            <input type="date" class="form-control form-control-sm" dir="ltr"
                                   id="paid_at" name="paid_at" value="<?= e(date('Y-m-d')) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fs-sm" for="reference_ar">المرجع</label>
                            <input type="text" class="form-control form-control-sm" id="reference_ar"
                                   name="reference_ar" maxlength="200" placeholder="رقم الشيك…">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-sm btn-primary w-100">تسجيل</button>
                        </div>
                    </form>
                    <p class="fs-xs text-muted-np mb-0 mt-2">
                        هذا <strong>تسجيل</strong> لمبلغ استلمته خارج المنصة، لا تحصيل إلكتروني.
                        لا يمكن تسجيل مبلغ أكبر من المتبقّي (<?= e(money($balance)) ?>).
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- المجاميع | Totals -->
        <div class="np-card mb-3">
            <div class="np-card__header"><h2 class="h6 mb-0">المجاميع</h2></div>
            <div class="np-card__body">
                <dl class="row mb-0 fs-sm">
                    <dt class="col-7">مجموع البنود</dt>
                    <dd class="col-5 numeric text-end"><?= e(money((float) $invoice['subtotal'])) ?></dd>
                    <dt class="col-7">الخصم</dt>
                    <dd class="col-5 numeric text-end">− <?= e(money((float) $invoice['discount_amount'])) ?></dd>
                    <dt class="col-7">ضريبة القيمة المضافة</dt>
                    <dd class="col-5 numeric text-end"><?= e(money((float) $invoice['vat_amount'])) ?></dd>
                    <dt class="col-7 fw-bold border-top pt-2">الإجمالي</dt>
                    <dd class="col-5 numeric text-end fw-bold border-top pt-2">
                        <?= e(money((float) $invoice['total'])) ?></dd>
                    <dt class="col-7">المسدَّد</dt>
                    <dd class="col-5 numeric text-end"><?= e(money((float) $invoice['amount_paid'])) ?></dd>
                    <dt class="col-7 fw-bold">المتبقّي</dt>
                    <dd class="col-5 numeric text-end fw-bold"><?= e(money($balance)) ?></dd>
                </dl>
            </div>

            <?php if ($editable): ?>
                <div class="np-card__footer">
                    <form method="post" action="<?= e(url('/app/invoices/' . $invoiceId . '/discount')) ?>"
                          class="d-flex gap-2 align-items-end">
                        <?= csrf_field() ?>
                        <div class="flex-grow-1">
                            <label class="form-label fs-sm" for="discount_amount">خصم (ج.م)</label>
                            <input type="number" step="0.01" min="0" dir="ltr"
                                   class="form-control form-control-sm numeric"
                                   id="discount_amount" name="discount_amount"
                                   value="<?= e((string) $invoice['discount_amount']) ?>">
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary">تطبيق</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- الإجراءات | Actions -->
        <?php if ($status === 'draft'): ?>
            <div class="np-card mb-3">
                <div class="np-card__header"><h2 class="h6 mb-0">إصدار الفاتورة</h2></div>
                <div class="np-card__body">
                    <p class="fs-sm text-muted-np">
                        الإصدار نقطة لا عودة منها: تُقفل البنود، ويُصرف المخزون للأصناف المتتبَّعة.
                    </p>
                    <form method="post" action="<?= e(url('/app/invoices/' . $invoiceId . '/issue')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-primary w-100"
                                data-confirm="إصدار الفاتورة؟ لن تُعدَّل بنودها بعدها.">إصدار</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($status !== 'cancelled'): ?>
            <div class="np-card mb-3">
                <div class="np-card__header"><h2 class="h6 mb-0">إلغاء الفاتورة</h2></div>
                <form method="post" action="<?= e(url('/app/invoices/' . $invoiceId . '/cancel')) ?>">
                    <?= csrf_field() ?>
                    <div class="np-card__body">
                        <label class="form-label fs-sm" for="cancelled_reason_ar">
                            سبب الإلغاء <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control form-control-sm" id="cancelled_reason_ar"
                                  name="cancelled_reason_ar" rows="2" maxlength="500" required></textarea>
                        <p class="fs-xs text-muted-np mb-0 mt-1">
                            الإلغاء يعيد المخزون المصروف. لا تُلغى فاتورة سُجّلت عليها مقبوضات.
                        </p>
                    </div>
                    <div class="np-card__footer">
                        <button type="submit" class="btn btn-sm btn-outline-danger w-100"
                                data-confirm="إلغاء هذه الفاتورة؟">إلغاء الفاتورة</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="np-card">
            <div class="np-card__body fs-xs text-muted-np">
                <?= e($disclaimer) ?>
            </div>
        </div>
    </div>
</div>
