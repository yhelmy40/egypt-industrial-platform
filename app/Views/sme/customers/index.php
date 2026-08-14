<?php
/**
 * دفتر العملاء | The customer book (§4.9).
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,string> $filters
 * @var array<int,array<string,mixed>> $governorates
 * @var \App\Services\CustomerService $service
 */

$types    = ['' => 'كل الأنواع', 'individual' => 'فرد', 'company' => 'شركة',
             'government' => 'جهة حكومية', 'ngo' => 'منظمة أهلية'];
$statuses = ['' => 'كل الحالات', 'active' => 'نشط', 'inactive' => 'غير نشط', 'blocked' => 'موقوف'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">العملاء</h1>
        <p class="fs-sm text-muted-np mb-0">
            دفتر عملاء مشروعك — <?= e(number_ar($results['total'])) ?> عميلاً.
        </p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('/app/customers/new')) ?>">إضافة عميل</a>
</div>

<form method="get" action="<?= e(url('/app/customers')) ?>" class="np-card mb-3">
    <div class="np-card__body row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label fs-sm" for="q">بحث</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q"
                   value="<?= e($filters['q']) ?>" placeholder="اسم العميل أو كوده أو هاتفه…">
        </div>
        <div class="col-md-3">
            <label class="form-label fs-sm" for="customer_type">النوع</label>
            <select class="form-select form-select-sm" id="customer_type" name="customer_type">
                <?php foreach ($types as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['customer_type'] === $value ? 'selected' : '' ?>>
                        <?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fs-sm" for="status">الحالة</label>
            <select class="form-select form-select-sm" id="status" name="status">
                <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>>
                        <?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-outline-primary w-100">تصفية</button>
        </div>
    </div>
</form>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($results['data'] === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">👤</div>
                <p class="mb-1">لا يوجد عملاء مطابقون.</p>
                <p class="fs-sm mb-0">ابدأ بإضافة عميل، أو حوّل مهتمّاً من قائمة المهتمّين.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>الكود</th><th>العميل</th><th>النوع</th><th>الهاتف</th>
                        <th>المحافظة</th><th>مستحقّ عليه</th><th>الحالة</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($results['data'] as $customer): ?>
                            <tr>
                                <td class="numeric fs-sm" dir="ltr"><?= e($customer['code']) ?></td>
                                <td>
                                    <a class="fw-bold" href="<?= e(url('/app/customers/' . $customer['id'])) ?>">
                                        <?= e($customer['name_ar']) ?></a>
                                </td>
                                <td class="fs-sm"><?= e($service->typeLabel((string) $customer['customer_type'])) ?></td>
                                <td class="fs-sm numeric" dir="ltr"><?= e($customer['phone'] ?? '—') ?></td>
                                <td class="fs-sm"><?= e($customer['governorate_name'] ?? '—') ?></td>
                                <td class="numeric fs-sm <?= (float) $customer['outstanding_balance'] > 0 ? 'fw-bold' : 'text-muted-np' ?>">
                                    <?= e(money((float) $customer['outstanding_balance'])) ?>
                                </td>
                                <td>
                                    <span class="np-badge <?= e(match ((string) $customer['status']) {
                                        'active'  => 'np-badge--success',
                                        'blocked' => 'np-badge--danger',
                                        default   => 'np-badge--muted',
                                    }) ?>">
                                        <?= e(match ((string) $customer['status']) {
                                            'active'   => 'نشط',
                                            'blocked'  => 'موقوف',
                                            default    => 'غير نشط',
                                        }) ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/app/customers/' . $customer['id'])) ?>">فتح</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($results['last_page'] > 1): ?>
    <?php
    $pageUrl = static function (int $page) use ($filters): string {
        $params = array_filter($filters + ['page' => $page], static fn ($v) => $v !== '' && $v !== null);

        return url('/app/customers') . '?' . http_build_query($params);
    };
    ?>
    <nav class="mt-3" aria-label="تصفّح الصفحات">
        <ul class="pagination pagination-sm justify-content-center mb-0">
            <?php for ($page = 1; $page <= $results['last_page']; $page++): ?>
                <li class="page-item <?= $page === $results['page'] ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e($pageUrl($page)) ?>"><?= e(number_ar($page)) ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
