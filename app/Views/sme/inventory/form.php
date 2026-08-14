<?php
/**
 * نموذج الصنف | Item form (§4.10).
 *
 * **لا حقل للرصيد هنا عند التعديل.** الرصيد يتغيّر بحركة في الدفتر تحمل سببها،
 * لا بكتابة رقم في نموذج. الرصيد الافتتاحي يُقبل عند الإنشاء وحده، ويُسجَّل
 * هو أيضاً كحركة.
 *
 * @var array<string,mixed>|null $item
 * @var array<int,array<string,mixed>> $listings
 * @var \App\Services\InventoryService $service
 */

$isEdit = $item !== null;
$action = $isEdit ? url('/app/inventory/' . $item['id']) : url('/app/inventory');
$value  = static fn (string $key, string $default = ''): string => e((string) ($item[$key] ?? $default));
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= $isEdit ? 'تعديل الصنف' : 'إضافة صنف' ?></h1>
    <a class="btn btn-sm btn-outline-secondary"
       href="<?= e($isEdit ? url('/app/inventory/' . $item['id']) : url('/app/inventory')) ?>">رجوع</a>
</div>

<form method="post" action="<?= e($action) ?>" class="np-card">
    <?= csrf_field() ?>
    <div class="np-card__body row g-3">
        <div class="col-md-6">
            <label class="form-label" for="name_ar">اسم الصنف <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="name_ar" name="name_ar" required
                   maxlength="200" value="<?= $value('name_ar') ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label" for="sku">كود الصنف (SKU) <span class="text-danger">*</span></label>
            <input type="text" class="form-control numeric" dir="ltr" id="sku" name="sku"
                   required maxlength="60" value="<?= $value('sku') ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label" for="item_type">النوع</label>
            <select class="form-select" id="item_type" name="item_type">
                <?php foreach (['product' => 'منتج', 'raw_material' => 'خامة',
                                'supply' => 'مستلزم', 'service' => 'خدمة'] as $key => $label): ?>
                    <option value="<?= e($key) ?>"
                        <?= ($item['item_type'] ?? 'product') === $key ? 'selected' : '' ?>>
                        <?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text fs-xs">الخدمة لا مخزون لها.</div>
        </div>

        <div class="col-md-3">
            <label class="form-label" for="unit_of_measure">وحدة القياس</label>
            <input type="text" class="form-control" id="unit_of_measure" name="unit_of_measure"
                   maxlength="40" value="<?= $value('unit_of_measure', 'قطعة') ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label" for="sale_price">سعر البيع (ج.م)</label>
            <input type="number" step="0.01" min="0" dir="ltr" class="form-control numeric"
                   id="sale_price" name="sale_price" value="<?= $value('sale_price') ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label" for="cost_price">تكلفة الشراء (ج.م)</label>
            <input type="number" step="0.01" min="0" dir="ltr" class="form-control numeric"
                   id="cost_price" name="cost_price" value="<?= $value('cost_price') ?>">
            <div class="form-text fs-xs">تُحدَّث تلقائياً عند استلام مشتريات.</div>
        </div>

        <div class="col-md-3">
            <label class="form-label" for="vat_rate">نسبة الضريبة (٪)</label>
            <input type="number" step="0.01" min="0" max="100" dir="ltr" class="form-control numeric"
                   id="vat_rate" name="vat_rate" value="<?= $value('vat_rate', '0') ?>">
        </div>

        <div class="col-12"><hr class="my-1"></div>

        <div class="col-md-3 d-flex align-items-center">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="track_stock" name="track_stock" value="1"
                    <?= (int) ($item['track_stock'] ?? 1) === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="track_stock">تتبّع المخزون</label>
            </div>
        </div>

        <div class="col-md-3">
            <label class="form-label" for="reorder_level">حدّ إعادة الطلب</label>
            <input type="number" step="0.001" min="0" dir="ltr" class="form-control numeric"
                   id="reorder_level" name="reorder_level" value="<?= $value('reorder_level') ?>">
            <div class="form-text fs-xs">ينبّهك حين يهبط الرصيد إليه.</div>
        </div>

        <?php if (!$isEdit): ?>
            <div class="col-md-3">
                <label class="form-label" for="opening_quantity">الرصيد الافتتاحي</label>
                <input type="number" step="0.001" min="0" dir="ltr" class="form-control numeric"
                       id="opening_quantity" name="opening_quantity" value="0">
                <div class="form-text fs-xs">يُسجَّل كحركة في الدفتر.</div>
            </div>
        <?php else: ?>
            <div class="col-md-3">
                <label class="form-label">الرصيد الحالي</label>
                <p class="form-control-plaintext numeric fw-bold mb-0">
                    <?= e(number_ar((float) $item['quantity_on_hand'], 3)) ?>
                    <span class="fs-xs text-muted-np"><?= e($item['unit_of_measure']) ?></span>
                </p>
                <div class="form-text fs-xs">
                    يُغيَّر بحركة في الدفتر لا من هنا.
                </div>
            </div>
        <?php endif; ?>

        <div class="col-md-3 d-flex align-items-center">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                    <?= (int) ($item['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">نشط</label>
            </div>
        </div>

        <div class="col-md-6">
            <label class="form-label" for="listing_id">ربط بإعلان في السوق</label>
            <select class="form-select" id="listing_id" name="listing_id">
                <option value="">— بلا ربط —</option>
                <?php foreach ($listings as $listing): ?>
                    <option value="<?= e((string) $listing['id']) ?>"
                        <?= (int) ($item['listing_id'] ?? 0) === (int) $listing['id'] ? 'selected' : '' ?>>
                        <?= e($listing['name_ar']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text fs-xs">
                عند الربط يصير هذا الصنف مصدر الكمية المتاحة للإعلان، فلا يعرض السوق توفّراً
                يخالف المخزن.
            </div>
        </div>

        <div class="col-12">
            <label class="form-label" for="description_ar">الوصف</label>
            <textarea class="form-control" id="description_ar" name="description_ar" rows="2"
                      maxlength="1000"><?= $value('description_ar') ?></textarea>
        </div>
    </div>

    <div class="np-card__footer d-flex gap-2">
        <button type="submit" class="btn btn-primary">حفظ</button>
        <a class="btn btn-outline-secondary"
           href="<?= e($isEdit ? url('/app/inventory/' . $item['id']) : url('/app/inventory')) ?>">إلغاء</a>
    </div>
</form>
