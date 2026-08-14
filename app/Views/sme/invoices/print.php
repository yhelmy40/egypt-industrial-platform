<?php
/**
 * نسخة الفاتورة للطباعة | The printable invoice (§4.10, §14).
 *
 * تُطبع الفاتورة الصادرة وحدها. المسودة لا تصل هنا: ورقة تحمل رقم فاتورة
 * وتخرج للعميل قبل الإصدار تصير مستنداً بلا سجلّ.
 *
 * @var array<string,mixed> $invoice
 * @var array<int,array<string,mixed>> $lines
 * @var array<int,array<string,mixed>> $payments
 * @var array<string,mixed>|null $organization
 * @var \App\Services\InvoiceService $service
 */
$balance = (float) $invoice['total'] - (float) $invoice['amount_paid'];
?>
<div class="print-doc__header mb-4">
    <div>
        <h1 class="print-doc__title">
            <?= e($organization['trading_name'] ?? $organization['legal_name'] ?? '') ?>
        </h1>
        <p class="print-doc__meta mb-0">
            <?php if (!empty($organization['governorate_name'])): ?>
                <?= e($organization['governorate_name']) ?><br>
            <?php endif; ?>
            <?php if (!empty($organization['public_phone'])): ?>
                <span class="numeric" dir="ltr"><?= e($organization['public_phone']) ?></span><br>
            <?php endif; ?>
            <?php if (!empty($organization['tax_number'])): ?>
                الرقم الضريبي: <span class="numeric" dir="ltr"><?= e($organization['tax_number']) ?></span>
            <?php endif; ?>
        </p>
    </div>

    <div class="text-start">
        <h2 class="h5 mb-1">فاتورة</h2>
        <p class="print-doc__meta mb-0">
            رقم: <span class="numeric fw-bold" dir="ltr"><?= e($invoice['invoice_number']) ?></span><br>
            تاريخ الإصدار: <?= e(format_date((string) $invoice['issue_date'])) ?><br>
            <?php if ($invoice['due_date'] !== null): ?>
                تاريخ الاستحقاق: <?= e(format_date((string) $invoice['due_date'])) ?><br>
            <?php endif; ?>
            الحالة: <?= e($service->statusLabel((string) $invoice['status'])) ?>
        </p>
    </div>
</div>

<div class="mb-4">
    <h3 class="h6 mb-1">المشتري</h3>
    <p class="mb-0 fs-sm">
        <?= e($invoice['customer_name_ar']) ?>
        <?php if (!empty($invoice['customer_phone'])): ?>
            <br><span class="numeric" dir="ltr"><?= e($invoice['customer_phone']) ?></span>
        <?php endif; ?>
        <?php if (!empty($invoice['customer_tax_number'])): ?>
            <br>الرقم الضريبي: <span class="numeric" dir="ltr"><?= e($invoice['customer_tax_number']) ?></span>
        <?php endif; ?>
    </p>
</div>

<table class="np-table mb-4">
    <thead><tr>
        <th>#</th><th>البند</th><th>الكمية</th><th>سعر الوحدة</th><th>الضريبة</th><th>الإجمالي</th>
    </tr></thead>
    <tbody>
        <?php foreach ($lines as $index => $line): ?>
            <tr>
                <td class="numeric"><?= e(number_ar($index + 1)) ?></td>
                <td class="fs-sm"><?= e($line['name_ar']) ?></td>
                <td class="numeric fs-sm">
                    <?= e(number_ar((float) $line['quantity'], 2)) ?>
                    <span class="fs-xs"><?= e($line['unit_of_measure'] ?? '') ?></span>
                </td>
                <td class="numeric fs-sm"><?= e(money((float) $line['unit_price'])) ?></td>
                <td class="numeric fs-xs"><?= e(number_ar((float) $line['vat_rate'], 2)) ?>٪</td>
                <td class="numeric fs-sm"><?= e(money((float) $line['line_total'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="print-doc__totals">
    <dl class="row mb-0 fs-sm">
        <dt class="col-7">مجموع البنود</dt>
        <dd class="col-5 numeric text-end"><?= e(money((float) $invoice['subtotal'])) ?></dd>
        <?php if ((float) $invoice['discount_amount'] > 0): ?>
            <dt class="col-7">الخصم</dt>
            <dd class="col-5 numeric text-end">− <?= e(money((float) $invoice['discount_amount'])) ?></dd>
        <?php endif; ?>
        <dt class="col-7">ضريبة القيمة المضافة</dt>
        <dd class="col-5 numeric text-end"><?= e(money((float) $invoice['vat_amount'])) ?></dd>
        <dt class="col-7 fw-bold border-top pt-2">الإجمالي</dt>
        <dd class="col-5 numeric text-end fw-bold border-top pt-2"><?= e(money((float) $invoice['total'])) ?></dd>
        <?php if ((float) $invoice['amount_paid'] > 0): ?>
            <dt class="col-7">المسدَّد</dt>
            <dd class="col-5 numeric text-end"><?= e(money((float) $invoice['amount_paid'])) ?></dd>
            <dt class="col-7 fw-bold">المتبقّي</dt>
            <dd class="col-5 numeric text-end fw-bold"><?= e(money($balance)) ?></dd>
        <?php endif; ?>
    </dl>
</div>

<?php if ($payments !== []): ?>
    <div class="mt-4">
        <h3 class="h6 mb-2">المقبوضات</h3>
        <table class="np-table">
            <thead><tr><th>الإيصال</th><th>التاريخ</th><th>المبلغ</th><th>الوسيلة</th></tr></thead>
            <tbody>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td class="numeric fs-sm" dir="ltr"><?= e($payment['receipt_number']) ?></td>
                        <td class="fs-sm"><?= e(format_date((string) $payment['paid_at'])) ?></td>
                        <td class="numeric fs-sm"><?= e(money((float) $payment['amount'])) ?></td>
                        <td class="fs-sm"><?= e(match ((string) $payment['method']) {
                            'cash'          => 'نقداً',
                            'bank_transfer' => 'تحويل بنكي',
                            'cheque'        => 'شيك',
                            'wallet'        => 'محفظة',
                            default         => 'أخرى',
                        }) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if (!empty($invoice['notes_ar'])): ?>
    <div class="mt-4">
        <h3 class="h6 mb-1">ملاحظات</h3>
        <p class="fs-sm mb-0"><?= nl2br(e($invoice['notes_ar'])) ?></p>
    </div>
<?php endif; ?>

<?php if ((string) $invoice['status'] === 'cancelled'): ?>
    <div class="print-doc__note">
        <strong>هذه الفاتورة ملغاة.</strong> <?= e($invoice['cancelled_reason_ar'] ?? '') ?>
    </div>
<?php endif; ?>

<div class="print-doc__note">
    مستند صادر عن نظام إدارة موارد مبسّط، وليس مخرجاً لنظام محاسبي بالقيد المزدوج.
    راجع التزاماتك الضريبية مع محاسب مؤهّل.
</div>
