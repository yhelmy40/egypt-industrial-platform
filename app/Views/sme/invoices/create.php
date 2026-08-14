<?php
/**
 * إنشاء مسودة فاتورة | Create an invoice draft (§4.10).
 *
 * تُنشأ المسودة برأسها أولاً ثم تُضاف البنود في صفحتها: بناء الفاتورة على
 * مرحلتين يجعل كل بند يُحسب فوراً بدل مجموع يُقدَّر في المتصفّح.
 *
 * @var array<int,array<string,mixed>> $customers
 * @var \App\Services\InvoiceService $service
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">فاتورة جديدة</h1>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/app/invoices')) ?>">رجوع</a>
</div>

<form method="post" action="<?= e(url('/app/invoices')) ?>" class="np-card">
    <?= csrf_field() ?>
    <div class="np-card__body row g-3">
        <div class="col-md-6">
            <label class="form-label" for="customer_id">عميل مسجَّل</label>
            <select class="form-select" id="customer_id" name="customer_id">
                <option value="">— مشترٍ غير مسجَّل —</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= e((string) $customer['id']) ?>">
                        <?= e($customer['name_ar']) ?>
                        (<?= e($customer['code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text fs-xs">
                اختر عميلاً من دفترك ليُربط به سجلّ الفواتير والمستحقّات.
            </div>
        </div>

        <div class="col-md-6">
            <label class="form-label" for="customer_name_ar">أو اكتب اسم المشتري</label>
            <input type="text" class="form-control" id="customer_name_ar" name="customer_name_ar"
                   maxlength="200" placeholder="لبيع نقدي لعميل غير مسجَّل">
        </div>

        <div class="col-md-3">
            <label class="form-label" for="customer_phone">هاتف المشتري</label>
            <input type="tel" class="form-control numeric" dir="ltr" id="customer_phone"
                   name="customer_phone" maxlength="30">
        </div>

        <div class="col-md-3">
            <label class="form-label" for="customer_tax_number">الرقم الضريبي</label>
            <input type="text" class="form-control numeric" dir="ltr" id="customer_tax_number"
                   name="customer_tax_number" maxlength="30">
        </div>

        <div class="col-md-3">
            <label class="form-label" for="issue_date">تاريخ الإصدار</label>
            <input type="date" class="form-control" dir="ltr" id="issue_date" name="issue_date"
                   value="<?= e(date('Y-m-d')) ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label" for="due_date">تاريخ الاستحقاق</label>
            <input type="date" class="form-control" dir="ltr" id="due_date" name="due_date">
            <div class="form-text fs-xs">يُحتسب في تقرير أعمار الذمم.</div>
        </div>

        <div class="col-12">
            <label class="form-label" for="notes_ar">ملاحظات على الفاتورة</label>
            <textarea class="form-control" id="notes_ar" name="notes_ar" rows="2"
                      maxlength="1000"></textarea>
        </div>
    </div>

    <div class="np-card__footer d-flex gap-2">
        <button type="submit" class="btn btn-primary">إنشاء المسودة</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/app/invoices')) ?>">إلغاء</a>
    </div>
</form>

<p class="fs-xs text-muted-np mt-2">
    تُنشأ الفاتورة كمسودة تُحرَّر بحرّية. بعد إصدارها لا تُعدَّل بنودها، لأن العميل يحمل
    نسخة منها — والتصحيح حينها يكون بإلغاء موثّق بسبب وإصدار بديل.
</p>
