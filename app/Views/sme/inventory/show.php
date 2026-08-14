<?php
/**
 * الصنف ودفتر حركاته | An item and its movement ledger (§4.10).
 *
 * الدفتر **مضاف إليه فقط**: لا زرّ حذف ولا تعديل لحركة. التصحيح حركة تسوية
 * جديدة، فيبقى أثر ما جرى قابلاً للمراجعة.
 *
 * @var array<string,mixed> $item
 * @var array<int,array<string,mixed>> $movements
 * @var \App\Services\InventoryService $service
 */
$itemId   = (int) $item['id'];
$tracked  = (int) $item['track_stock'] === 1;
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><?= e($item['name_ar']) ?></h1>
        <p class="fs-sm text-muted-np mb-0">
            <span class="numeric" dir="ltr"><?= e($item['sku']) ?></span>
            · <?= e($item['unit_of_measure']) ?>
            <?php if (!empty($item['listing_name'])): ?>
                · مرتبط بإعلان «<?= e($item['listing_name']) ?>»
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary"
           href="<?= e(url('/app/inventory/' . $itemId . '/edit')) ?>">تعديل</a>
        <form method="post" action="<?= e(url('/app/inventory/' . $itemId . '/archive')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-outline-danger"
                    data-confirm="أرشفة هذا الصنف؟">أرشفة</button>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">الرصيد الحالي</div>
            <div class="stat-value">
                <?= $tracked ? e(number_ar((float) $item['quantity_on_hand'], 3)) : '—' ?>
            </div>
            <div class="stat-tile__meta">
                <?= $tracked ? e($item['unit_of_measure']) : 'صنف غير متتبَّع المخزون' ?>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">سعر البيع</div>
            <div class="stat-value">
                <?= $item['sale_price'] !== null ? e(money((float) $item['sale_price'])) : '—' ?>
            </div>
            <div class="stat-tile__meta">
                ضريبة <?= e(number_ar((float) $item['vat_rate'], 2)) ?>٪
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">آخر تكلفة معروفة</div>
            <div class="stat-value">
                <?= $item['cost_price'] !== null ? e(money((float) $item['cost_price'])) : '—' ?>
            </div>
            <div class="stat-tile__meta">تُحدَّث عند الاستلام</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">حدّ إعادة الطلب</div>
            <div class="stat-value">
                <?= $item['reorder_level'] !== null
                    ? e(number_ar((float) $item['reorder_level'], 2)) : '—' ?>
            </div>
            <div class="stat-tile__meta">تنبيه الشراء</div>
        </div>
    </div>
</div>

<?php if ($tracked): ?>
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header"><h2 class="h6 mb-0">تسجيل حركة</h2></div>
            <form method="post" action="<?= e(url('/app/inventory/' . $itemId . '/movements')) ?>">
                <?= csrf_field() ?>
                <div class="np-card__body row g-2">
                    <div class="col-md-6">
                        <label class="form-label fs-sm" for="movement_type">النوع</label>
                        <select class="form-select form-select-sm" id="movement_type" name="movement_type" required>
                            <?php foreach (['purchase', 'return_in', 'production_in', 'return_out',
                                            'damage', 'transfer_out'] as $type): ?>
                                <option value="<?= e($type) ?>"><?= e($service->movementLabel($type)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fs-sm" for="quantity">الكمية</label>
                        <input type="number" step="0.001" min="0.001" dir="ltr"
                               class="form-control form-control-sm numeric"
                               id="quantity" name="quantity" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fs-sm" for="unit_cost">تكلفة الوحدة (للوارد)</label>
                        <input type="number" step="0.01" min="0" dir="ltr"
                               class="form-control form-control-sm numeric" id="unit_cost" name="unit_cost">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fs-sm" for="moved_at">التاريخ</label>
                        <input type="datetime-local" class="form-control form-control-sm" dir="ltr"
                               id="moved_at" name="moved_at">
                    </div>
                    <div class="col-12">
                        <label class="form-label fs-sm" for="reason_ar">
                            السبب <span class="text-muted-np">(إلزامي للتالف والتحويل)</span>
                        </label>
                        <input type="text" class="form-control form-control-sm" id="reason_ar"
                               name="reason_ar" maxlength="500">
                    </div>
                </div>
                <div class="np-card__footer">
                    <button type="submit" class="btn btn-sm btn-primary">تسجيل الحركة</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="np-card h-100">
            <div class="np-card__header"><h2 class="h6 mb-0">تسوية جرد</h2></div>
            <form method="post" action="<?= e(url('/app/inventory/' . $itemId . '/adjust')) ?>">
                <?= csrf_field() ?>
                <div class="np-card__body">
                    <p class="fs-sm text-muted-np">
                        اكتب <strong>الرصيد الذي عددته فعلاً</strong> في المخزن، وتُحسب الفروق تلقائياً.
                        تُسجَّل التسوية كحركة مستقلّة ولا تمحو أي حركة سابقة.
                    </p>
                    <div class="row g-2">
                        <div class="col-md-5">
                            <label class="form-label fs-sm" for="counted_quantity">الرصيد المعدود</label>
                            <input type="number" step="0.001" min="0" dir="ltr"
                                   class="form-control form-control-sm numeric"
                                   id="counted_quantity" name="counted_quantity" required
                                   value="<?= e((string) $item['quantity_on_hand']) ?>">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fs-sm" for="adjust_reason">سبب التسوية <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="adjust_reason"
                                   name="reason_ar" required maxlength="500"
                                   placeholder="جرد شهري، عجز، فائض…">
                        </div>
                    </div>
                </div>
                <div class="np-card__footer">
                    <button type="submit" class="btn btn-sm btn-outline-primary">تسجيل التسوية</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="np-card">
    <div class="np-card__header">
        <h2 class="h6 mb-0">دفتر الحركات</h2>
    </div>
    <div class="np-card__body p-0">
        <?php if ($movements === []): ?>
            <div class="np-empty"><p class="mb-0 fs-sm">لا حركات مسجَّلة على هذا الصنف.</p></div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>التاريخ</th><th>النوع</th><th>الكمية</th><th>الرصيد بعدها</th>
                        <th>المرجع</th><th>السبب</th><th>سجّلها</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($movements as $movement): ?>
                            <?php $delta = (float) $movement['quantity_delta']; ?>
                            <tr>
                                <td class="fs-xs text-muted-np">
                                    <?= e(format_date((string) $movement['moved_at'], true)) ?></td>
                                <td class="fs-sm"><?= e($service->movementLabel((string) $movement['movement_type'])) ?></td>
                                <td class="numeric fs-sm fw-bold <?= $delta < 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= $delta > 0 ? '+' : '' ?><?= e(number_ar($delta, 3)) ?>
                                </td>
                                <td class="numeric fs-sm"><?= e(number_ar((float) $movement['balance_after'], 3)) ?></td>
                                <td class="fs-xs text-muted-np">
                                    <?= e(match ((string) $movement['reference_type']) {
                                        'invoice'        => 'فاتورة',
                                        'purchase_order' => 'أمر شراء',
                                        'order'          => 'طلب سوق',
                                        default          => 'يدوي',
                                    }) ?>
                                    <?php if ($movement['reference_id'] !== null): ?>
                                        <span class="numeric" dir="ltr">#<?= e((string) $movement['reference_id']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="fs-xs"><?= e($movement['reason_ar'] ?? '—') ?></td>
                                <td class="fs-xs text-muted-np"><?= e($movement['actor_name'] ?? 'النظام') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <div class="np-card__footer fs-xs text-muted-np">
        الدفتر مضاف إليه فقط: لا تُعدَّل حركة ولا تُحذف. التصحيح يكون بتسوية جديدة تحمل سببها،
        فيبقى أثر ما جرى قابلاً للمراجعة.
    </div>
</div>
