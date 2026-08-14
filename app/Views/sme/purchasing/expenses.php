<?php
/**
 * المصروفات | Expenses (§4.10, §14).
 *
 * على الأساس النقدي: ما خرج فعلاً بتاريخ خروجه. لا استحقاق ولا إهلاك.
 * بند «الأجور» مصروف يُدوَّن، لا نظام أجور — لا ضرائب ولا تأمينات ولا كشوف.
 *
 * @var array<int,array<string,mixed>> $expenses
 * @var float $total
 * @var array<int,array<string,mixed>> $suppliers
 * @var array<string,string> $filters
 * @var \App\Services\ExpenseService $service
 */
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">المصروفات</h1>
        <p class="fs-sm text-muted-np mb-0">
            مجموع الفترة: <strong><?= e(money($total)) ?></strong>
            · <?= e(number_ar(count($expenses))) ?> قيداً
        </p>
    </div>
</div>

<form method="get" action="<?= e(url('/app/purchasing/expenses')) ?>" class="np-card mb-3">
    <div class="np-card__body row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label fs-sm" for="from">من</label>
            <input type="date" class="form-control form-control-sm" dir="ltr" id="from" name="from"
                   value="<?= e($filters['from']) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fs-sm" for="to">إلى</label>
            <input type="date" class="form-control form-control-sm" dir="ltr" id="to" name="to"
                   value="<?= e($filters['to']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fs-sm" for="category">البند</label>
            <select class="form-select form-select-sm" id="category" name="category">
                <option value="">كل البنود</option>
                <?php foreach ($service->categories() as $category): ?>
                    <option value="<?= e($category) ?>" <?= $filters['category'] === $category ? 'selected' : '' ?>>
                        <?= e($service->categoryLabel($category)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-outline-primary w-100">تصفية</button>
        </div>
    </div>
</form>

<div class="np-card mb-3">
    <div class="np-card__header"><h2 class="h6 mb-0">تسجيل مصروف</h2></div>
    <form method="post" action="<?= e(url('/app/purchasing/expenses')) ?>">
        <?= csrf_field() ?>
        <div class="np-card__body row g-2">
            <div class="col-md-3">
                <label class="form-label fs-sm" for="description_ar">الوصف <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm" id="description_ar"
                       name="description_ar" required maxlength="500">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-sm" for="expense_category">البند</label>
                <select class="form-select form-select-sm" id="expense_category" name="category">
                    <?php foreach ($service->categories() as $category): ?>
                        <option value="<?= e($category) ?>"><?= e($service->categoryLabel($category)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fs-sm" for="amount">المبلغ <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0.01" dir="ltr"
                       class="form-control form-control-sm numeric" id="amount" name="amount" required>
            </div>
            <div class="col-md-2">
                <label class="form-label fs-sm" for="spent_at">تاريخ الصرف</label>
                <input type="date" class="form-control form-control-sm" dir="ltr" id="spent_at"
                       name="spent_at" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-sm" for="supplier_id">المورّد</label>
                <select class="form-select form-select-sm" id="supplier_id" name="supplier_id">
                    <option value="">—</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= e((string) $supplier['id']) ?>"><?= e($supplier['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-sm btn-primary w-100">تسجيل</button>
            </div>
        </div>
    </form>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($expenses === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">💰</div>
                <p class="mb-1">لا مصروفات في هذه الفترة.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>الرقم</th><th>التاريخ</th><th>الوصف</th><th>البند</th>
                        <th>المورّد</th><th>الوسيلة</th><th>المبلغ</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td class="numeric fs-xs" dir="ltr"><?= e($expense['expense_number']) ?></td>
                                <td class="fs-xs text-muted-np">
                                    <?= e(format_date((string) $expense['spent_at'])) ?></td>
                                <td class="fs-sm"><?= e($expense['description_ar']) ?></td>
                                <td class="fs-xs">
                                    <?= e($service->categoryLabel((string) $expense['category'])) ?></td>
                                <td class="fs-sm"><?= e($expense['supplier_name'] ?? '—') ?></td>
                                <td class="fs-xs text-muted-np">
                                    <?= e($service->methodLabel((string) $expense['method'])) ?></td>
                                <td class="numeric fs-sm fw-bold"><?= e(money((float) $expense['amount'])) ?></td>
                                <td>
                                    <form method="post"
                                          action="<?= e(url('/app/purchasing/expenses/' . $expense['id'] . '/delete')) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                data-confirm="حذف هذا المصروف؟">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <div class="np-card__footer fs-xs text-muted-np">
        المصروفات مسجَّلة على الأساس النقدي: ما خرج فعلاً بتاريخ خروجه، بلا استحقاق ولا إهلاك.
        بند «الأجور» تدوين لما دُفع، وليس نظام أجور ولا احتساباً لضرائب أو تأمينات.
    </div>
</div>
