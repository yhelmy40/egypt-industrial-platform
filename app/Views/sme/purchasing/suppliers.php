<?php
/**
 * الموردون | Suppliers (§4.10).
 *
 * @var array<int,array<string,mixed>> $suppliers
 * @var array<int,array<string,mixed>> $governorates
 * @var array<string,string> $filters
 */
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">الموردون</h1>
        <p class="fs-sm text-muted-np mb-0"><?= e(number_ar(count($suppliers))) ?> مورّداً.</p>
    </div>
    <form method="get" action="<?= e(url('/app/purchasing/suppliers')) ?>" class="d-flex gap-2">
        <label class="visually-hidden" for="q">بحث</label>
        <input type="search" class="form-control form-control-sm" id="q" name="q"
               value="<?= e($filters['q']) ?>" placeholder="اسم المورّد أو كوده…" style="min-width:14rem">
        <button type="submit" class="btn btn-sm btn-outline-primary">بحث</button>
    </form>
</div>

<div class="np-card mb-3">
    <div class="np-card__header"><h2 class="h6 mb-0">إضافة مورّد</h2></div>
    <form method="post" action="<?= e(url('/app/purchasing/suppliers')) ?>">
        <?= csrf_field() ?>
        <div class="np-card__body row g-2">
            <div class="col-md-3">
                <label class="form-label fs-sm" for="name_ar">اسم المورّد <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm" id="name_ar" name="name_ar"
                       required maxlength="200">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-sm" for="contact_person_ar">مسؤول التواصل</label>
                <input type="text" class="form-control form-control-sm" id="contact_person_ar"
                       name="contact_person_ar" maxlength="150">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-sm" for="phone">الهاتف</label>
                <input type="tel" class="form-control form-control-sm numeric" dir="ltr"
                       id="phone" name="phone" maxlength="30">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-sm" for="governorate_id">المحافظة</label>
                <select class="form-select form-select-sm" id="governorate_id" name="governorate_id">
                    <option value="">—</option>
                    <?php foreach ($governorates as $governorate): ?>
                        <option value="<?= e((string) $governorate['id']) ?>">
                            <?= e($governorate['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fs-sm" for="payment_terms_ar">شروط السداد</label>
                <input type="text" class="form-control form-control-sm" id="payment_terms_ar"
                       name="payment_terms_ar" maxlength="200" placeholder="٣٠ يوماً…">
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-sm btn-primary w-100">إضافة</button>
            </div>
        </div>
    </form>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($suppliers === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">🚚</div>
                <p class="mb-1">لا موردين مسجَّلين.</p>
                <p class="fs-sm mb-0">سجّل مورّديك لتربط بهم أوامر الشراء والمصروفات.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>الكود</th><th>المورّد</th><th>مسؤول التواصل</th><th>الهاتف</th>
                        <th>شروط السداد</th><th>الحالة</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($suppliers as $supplier): ?>
                            <tr>
                                <td class="numeric fs-sm" dir="ltr"><?= e($supplier['code']) ?></td>
                                <td class="fw-bold fs-sm"><?= e($supplier['name_ar']) ?></td>
                                <td class="fs-sm"><?= e($supplier['contact_person_ar'] ?? '—') ?></td>
                                <td class="numeric fs-sm" dir="ltr"><?= e($supplier['phone'] ?? '—') ?></td>
                                <td class="fs-sm"><?= e($supplier['payment_terms_ar'] ?? '—') ?></td>
                                <td>
                                    <span class="np-badge <?= (string) $supplier['status'] === 'active'
                                        ? 'np-badge--success' : 'np-badge--muted' ?>">
                                        <?= (string) $supplier['status'] === 'active' ? 'نشط' : 'غير نشط' ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post"
                                          action="<?= e(url('/app/purchasing/suppliers/' . $supplier['id'] . '/archive')) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                data-confirm="أرشفة هذا المورّد؟">أرشفة</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
