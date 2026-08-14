<?php
/**
 * أمر الشراء | The purchase order (§4.10).
 *
 * نموذج الاستلام يظهر بعد الإرسال وحده، والكمية القصوى فيه هي المتبقّي من كل
 * بند: لا يُدعى المستخدم لإدخال رقم سترفضه الخدمة.
 *
 * @var array<string,mixed> $order
 * @var array<int,array<string,mixed>> $lines
 * @var array<int,array<string,mixed>> $items
 * @var bool $editable
 * @var bool $canReceive
 * @var \App\Services\PurchaseOrderService $service
 */
$poId   = (int) $order['id'];
$status = (string) $order['status'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">
            أمر شراء <span class="numeric" dir="ltr"><?= e($order['po_number']) ?></span>
            <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
                <?= e($service->statusLabel($status)) ?></span>
        </h1>
        <p class="fs-sm text-muted-np mb-0">
            <?= e($order['supplier_name']) ?>
            · <?= e(format_date((string) $order['order_date'])) ?>
            <?php if ($order['expected_date'] !== null): ?>
                · توريد متوقّع <?= e(format_date((string) $order['expected_date'])) ?>
            <?php endif; ?>
        </p>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/app/purchasing/orders')) ?>">رجوع</a>
</div>

<?php if ($status === 'cancelled'): ?>
    <div class="alert alert-warning" role="alert">
        <strong>أمر ملغى:</strong> <?= e($order['cancelled_reason_ar'] ?? '') ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="np-card mb-3">
            <div class="np-card__header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">البنود</h2>
                <?php if (!$editable && $status !== 'cancelled'): ?>
                    <span class="fs-xs text-muted-np">لا تُعدَّل بعد الإرسال</span>
                <?php endif; ?>
            </div>
            <div class="np-card__body p-0">
                <?php if ($lines === []): ?>
                    <div class="np-empty"><p class="mb-0 fs-sm">لا بنود بعد.</p></div>
                <?php else: ?>
                    <div class="table-scroll" style="border:0">
                        <table class="np-table">
                            <thead><tr>
                                <th>الصنف</th><th>المطلوب</th><th>المستلَم</th><th>المتبقّي</th>
                                <th>تكلفة الوحدة</th><th>الإجمالي</th><?= $editable ? '<th></th>' : '' ?>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($lines as $line): ?>
                                    <?php
                                    $outstanding = (float) $line['quantity_ordered']
                                        - (float) $line['quantity_received'];
                                    ?>
                                    <tr>
                                        <td class="fs-sm"><?= e($line['name_ar']) ?></td>
                                        <td class="numeric fs-sm">
                                            <?= e(number_ar((float) $line['quantity_ordered'], 2)) ?>
                                            <span class="fs-xs text-muted-np"><?= e($line['unit_of_measure'] ?? '') ?></span>
                                        </td>
                                        <td class="numeric fs-sm">
                                            <?= e(number_ar((float) $line['quantity_received'], 2)) ?></td>
                                        <td class="numeric fs-sm <?= $outstanding > 0 ? 'fw-bold' : 'text-muted-np' ?>">
                                            <?= e(number_ar($outstanding, 2)) ?></td>
                                        <td class="numeric fs-sm"><?= e(money((float) $line['unit_cost'])) ?></td>
                                        <td class="numeric fs-sm fw-bold"><?= e(money((float) $line['line_total'])) ?></td>
                                        <?php if ($editable): ?>
                                            <td>
                                                <form method="post"
                                                      action="<?= e(url('/app/purchasing/orders/' . $poId . '/lines/remove')) ?>">
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
                    <form method="post" action="<?= e(url('/app/purchasing/orders/' . $poId . '/lines')) ?>"
                          class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <div class="col-md-5">
                            <label class="form-label fs-sm" for="item_id">الصنف <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="item_id" name="item_id" required>
                                <option value="">— اختر —</option>
                                <?php foreach ($items as $item): ?>
                                    <option value="<?= e((string) $item['id']) ?>"><?= e($item['name_ar']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fs-sm" for="quantity_ordered">الكمية</label>
                            <input type="number" step="0.001" min="0.001" dir="ltr"
                                   class="form-control form-control-sm numeric"
                                   id="quantity_ordered" name="quantity_ordered" required value="1">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fs-sm" for="unit_cost">تكلفة الوحدة</label>
                            <input type="number" step="0.01" min="0" dir="ltr"
                                   class="form-control form-control-sm numeric"
                                   id="unit_cost" name="unit_cost">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-sm btn-primary w-100">إضافة</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($canReceive): ?>
            <div class="np-card">
                <div class="np-card__header"><h2 class="h6 mb-0">تسجيل استلام</h2></div>
                <form method="post" action="<?= e(url('/app/purchasing/orders/' . $poId . '/receive')) ?>">
                    <?= csrf_field() ?>
                    <div class="np-card__body">
                        <p class="fs-sm text-muted-np">
                            اكتب ما استلمته فعلاً من كل بند. كل كمية تُنتج حركة مخزون بتكلفتها،
                            والبنود الفارغة تُتخطّى فيمكن الاستلام على دفعات.
                        </p>
                        <div class="table-scroll" style="border:0">
                            <table class="np-table">
                                <thead><tr><th>الصنف</th><th>المتبقّي</th><th>المستلَم الآن</th></tr></thead>
                                <tbody>
                                    <?php foreach ($lines as $line): ?>
                                        <?php
                                        $outstanding = (float) $line['quantity_ordered']
                                            - (float) $line['quantity_received'];
                                        if ($outstanding <= 0) {
                                            continue;
                                        }
                                        ?>
                                        <tr>
                                            <td class="fs-sm"><?= e($line['name_ar']) ?></td>
                                            <td class="numeric fs-sm"><?= e(number_ar($outstanding, 3)) ?></td>
                                            <td>
                                                <label class="visually-hidden"
                                                       for="recv_<?= e((string) $line['id']) ?>">الكمية المستلمة</label>
                                                <input type="number" step="0.001" min="0" dir="ltr"
                                                       max="<?= e(number_format($outstanding, 3, '.', '')) ?>"
                                                       class="form-control form-control-sm numeric"
                                                       id="recv_<?= e((string) $line['id']) ?>"
                                                       name="received[<?= e((string) $line['id']) ?>]"
                                                       placeholder="0">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="np-card__footer">
                        <button type="submit" class="btn btn-sm btn-primary">تسجيل الاستلام</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="np-card mb-3">
            <div class="np-card__header"><h2 class="h6 mb-0">المجاميع</h2></div>
            <div class="np-card__body">
                <dl class="row mb-0 fs-sm">
                    <dt class="col-7">مجموع البنود</dt>
                    <dd class="col-5 numeric text-end"><?= e(money((float) $order['subtotal'])) ?></dd>
                    <dt class="col-7">الضريبة</dt>
                    <dd class="col-5 numeric text-end"><?= e(money((float) $order['vat_amount'])) ?></dd>
                    <dt class="col-7 fw-bold border-top pt-2">الإجمالي</dt>
                    <dd class="col-5 numeric text-end fw-bold border-top pt-2">
                        <?= e(money((float) $order['total'])) ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($status === 'draft'): ?>
            <div class="np-card mb-3">
                <div class="np-card__header"><h2 class="h6 mb-0">إرسال للمورّد</h2></div>
                <div class="np-card__body">
                    <p class="fs-sm text-muted-np">
                        الإرسال يقفل البنود ولا يحرّك المخزون. المخزون يزيد بتسجيل الاستلام.
                    </p>
                    <form method="post" action="<?= e(url('/app/purchasing/orders/' . $poId . '/send')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-primary w-100"
                                data-confirm="إرسال أمر الشراء؟ لن تُعدَّل بنوده بعدها.">إرسال</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($status !== 'cancelled' && $status !== 'received'): ?>
            <div class="np-card">
                <div class="np-card__header"><h2 class="h6 mb-0">إلغاء الأمر</h2></div>
                <form method="post" action="<?= e(url('/app/purchasing/orders/' . $poId . '/cancel')) ?>">
                    <?= csrf_field() ?>
                    <div class="np-card__body">
                        <label class="form-label fs-sm" for="cancelled_reason_ar">
                            سبب الإلغاء <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control form-control-sm" id="cancelled_reason_ar"
                                  name="cancelled_reason_ar" rows="2" maxlength="500" required></textarea>
                        <p class="fs-xs text-muted-np mb-0 mt-1">
                            لا يُلغى أمر استُلم جزء منه: البضاعة دخلت المخزن فعلاً.
                        </p>
                    </div>
                    <div class="np-card__footer">
                        <button type="submit" class="btn btn-sm btn-outline-danger w-100"
                                data-confirm="إلغاء أمر الشراء؟">إلغاء الأمر</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
