<?php
/**
 * نموذج بيانات العميل | Customer form (§4.9, §10).
 *
 * لا يُجمع رقم قومي ولا بيانات حساب بنكي. الرقم الضريبي وحده يُجمع لأن الفاتورة
 * تحتاجه (§10).
 *
 * @var array<string,mixed>|null $customer
 * @var array<int,array<string,mixed>> $governorates
 * @var \App\Services\CustomerService $service
 */

$isEdit = $customer !== null;
$action = $isEdit ? url('/app/customers/' . $customer['id']) : url('/app/customers');
$value  = static fn (string $key, string $default = ''): string
    => e((string) ($customer[$key] ?? $default));
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= $isEdit ? 'تعديل بيانات العميل' : 'إضافة عميل' ?></h1>
    <a class="btn btn-sm btn-outline-secondary"
       href="<?= e($isEdit ? url('/app/customers/' . $customer['id']) : url('/app/customers')) ?>">رجوع</a>
</div>

<form method="post" action="<?= e($action) ?>" class="np-card">
    <?= csrf_field() ?>
    <div class="np-card__body row g-3">
        <div class="col-md-6">
            <label class="form-label" for="name_ar">اسم العميل <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="name_ar" name="name_ar" required
                   maxlength="200" value="<?= $value('name_ar') ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label" for="code">كود العميل</label>
            <input type="text" class="form-control" id="code" name="code" maxlength="30"
                   value="<?= $value('code') ?>" dir="ltr">
            <div class="form-text fs-xs">يُولَّد تلقائياً إن تُرك فارغاً.</div>
        </div>

        <div class="col-md-3">
            <label class="form-label" for="customer_type">النوع</label>
            <select class="form-select" id="customer_type" name="customer_type">
                <?php foreach (['individual' => 'فرد', 'company' => 'شركة',
                                'government' => 'جهة حكومية', 'ngo' => 'منظمة أهلية'] as $key => $label): ?>
                    <option value="<?= e($key) ?>"
                        <?= ($customer['customer_type'] ?? 'individual') === $key ? 'selected' : '' ?>>
                        <?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label" for="phone">الهاتف</label>
            <input type="tel" class="form-control numeric" id="phone" name="phone" dir="ltr"
                   maxlength="30" value="<?= $value('phone') ?>">
        </div>

        <div class="col-md-4">
            <label class="form-label" for="email">البريد الإلكتروني</label>
            <input type="email" class="form-control" id="email" name="email" dir="ltr"
                   maxlength="190" value="<?= $value('email') ?>">
        </div>

        <div class="col-md-4">
            <label class="form-label" for="tax_number">الرقم الضريبي</label>
            <input type="text" class="form-control numeric" id="tax_number" name="tax_number" dir="ltr"
                   maxlength="30" value="<?= $value('tax_number') ?>">
            <div class="form-text fs-xs">يظهر على الفاتورة عند وجوده.</div>
        </div>

        <div class="col-md-4">
            <label class="form-label" for="governorate_id">المحافظة</label>
            <select class="form-select" id="governorate_id" name="governorate_id">
                <option value="">— اختر —</option>
                <?php foreach ($governorates as $governorate): ?>
                    <option value="<?= e((string) $governorate['id']) ?>"
                        <?= (int) ($customer['governorate_id'] ?? 0) === (int) $governorate['id'] ? 'selected' : '' ?>>
                        <?= e($governorate['name_ar']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label" for="source">مصدر العميل</label>
            <select class="form-select" id="source" name="source">
                <?php foreach (['marketplace' => 'سوق المنصة', 'referral' => 'ترشيح',
                                'walk_in' => 'زيارة مباشرة', 'social' => 'وسائل التواصل',
                                'event' => 'معرض أو فعالية', 'other' => 'أخرى'] as $key => $label): ?>
                    <option value="<?= e($key) ?>"
                        <?= ($customer['source'] ?? 'other') === $key ? 'selected' : '' ?>>
                        <?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label" for="status">الحالة</label>
            <select class="form-select" id="status" name="status">
                <?php foreach (['active' => 'نشط', 'inactive' => 'غير نشط',
                                'blocked' => 'موقوف'] as $key => $label): ?>
                    <option value="<?= e($key) ?>"
                        <?= ($customer['status'] ?? 'active') === $key ? 'selected' : '' ?>>
                        <?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-12">
            <label class="form-label" for="address">العنوان</label>
            <input type="text" class="form-control" id="address" name="address"
                   maxlength="500" value="<?= $value('address') ?>">
        </div>

        <div class="col-md-4">
            <label class="form-label" for="credit_limit">حدّ ائتماني إرشادي (ج.م)</label>
            <input type="number" step="0.01" min="0" class="form-control numeric" dir="ltr"
                   id="credit_limit" name="credit_limit" value="<?= $value('credit_limit') ?>">
            <div class="form-text fs-xs">
                تذكير لك عند المتابعة، ولا يمنع المنصة من تسجيل أي بيع.
            </div>
        </div>

        <div class="col-12">
            <label class="form-label" for="notes_ar">ملاحظات</label>
            <textarea class="form-control" id="notes_ar" name="notes_ar" rows="3"
                      maxlength="2000"><?= $value('notes_ar') ?></textarea>
        </div>
    </div>

    <div class="np-card__footer d-flex gap-2">
        <button type="submit" class="btn btn-primary">حفظ</button>
        <a class="btn btn-outline-secondary"
           href="<?= e($isEdit ? url('/app/customers/' . $customer['id']) : url('/app/customers')) ?>">إلغاء</a>
    </div>
</form>
