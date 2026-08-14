<?php
/**
 * الأصناف والمخزون | Items and stock (§4.10).
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,mixed> $filters
 * @var array<int,array<string,mixed>> $lowStock
 * @var array<string,mixed> $valuation
 * @var \App\Services\InventoryService $service
 */

$types = ['' => 'كل الأنواع', 'product' => 'منتج', 'raw_material' => 'خامة',
          'supply' => 'مستلزم', 'service' => 'خدمة'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">الأصناف والمخزون</h1>
        <p class="fs-sm text-muted-np mb-0">
            الرصيد مشتقّ من دفتر الحركات، ولا يُحرَّر مباشرةً.
        </p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('/app/inventory/new')) ?>">إضافة صنف</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">أصناف متتبَّعة</div>
            <div class="stat-value"><?= e(number_ar((int) $valuation['item_count'])) ?></div>
            <div class="stat-tile__meta">نشطة ومتتبَّعة المخزون</div>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">قيمة المخزون التقديرية</div>
            <div class="stat-value"><?= e(money((float) $valuation['total_cost'])) ?></div>
            <div class="stat-tile__meta">
                بآخر تكلفة شراء معروفة — تقدير إداري
            </div>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">تحت حدّ إعادة الطلب</div>
            <div class="stat-value"><?= e(number_ar(count($lowStock))) ?></div>
            <div class="stat-tile__meta">تحتاج شراءً</div>
        </div>
    </div>
</div>

<?php if ((int) $valuation['items_without_cost'] > 0): ?>
    <div class="alert alert-info fs-sm" role="alert">
        <?= e(number_ar((int) $valuation['items_without_cost'])) ?> صنفاً بلا تكلفة شراء مسجَّلة،
        فقيمتها غير محتسبة في التقدير أعلاه. أضف تكلفة لكل صنف ليكون التقدير مكتملاً.
    </div>
<?php endif; ?>

<?php if ($lowStock !== []): ?>
    <div class="np-card mb-3">
        <div class="np-card__header"><h2 class="h6 mb-0">أصناف تحتاج إعادة طلب</h2></div>
        <div class="np-card__body">
            <ul class="list-unstyled mb-0">
                <?php foreach ($lowStock as $item): ?>
                    <li class="d-flex justify-content-between gap-2 fs-sm mb-1">
                        <a href="<?= e(url('/app/inventory/' . $item['id'])) ?>"><?= e($item['name_ar']) ?></a>
                        <span class="numeric text-muted-np">
                            <?= e(number_ar((float) $item['quantity_on_hand'], 2)) ?>
                            من <?= e(number_ar((float) $item['reorder_level'], 2)) ?>
                            <?= e($item['unit_of_measure']) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<form method="get" action="<?= e(url('/app/inventory')) ?>" class="np-card mb-3">
    <div class="np-card__body row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label fs-sm" for="q">بحث</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q"
                   value="<?= e((string) $filters['q']) ?>" placeholder="اسم الصنف أو كوده…">
        </div>
        <div class="col-md-3">
            <label class="form-label fs-sm" for="item_type">النوع</label>
            <select class="form-select form-select-sm" id="item_type" name="item_type">
                <?php foreach ($types as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['item_type'] === $value ? 'selected' : '' ?>>
                        <?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex align-items-center">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="low_stock" name="low_stock" value="1"
                    <?= !empty($filters['low_stock']) ? 'checked' : '' ?>>
                <label class="form-check-label fs-sm" for="low_stock">تحت حدّ إعادة الطلب فقط</label>
            </div>
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
                <div class="np-empty__icon" aria-hidden="true">📦</div>
                <p class="mb-1">لا أصناف مطابقة.</p>
                <p class="fs-sm mb-0">ابدأ بإضافة أصنافك ورصيدها الافتتاحي.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>الكود</th><th>الصنف</th><th>النوع</th><th>الرصيد</th>
                        <th>سعر البيع</th><th>التكلفة</th><th>مرتبط بإعلان</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($results['data'] as $item): ?>
                            <?php
                            $low = (int) $item['track_stock'] === 1
                                && $item['reorder_level'] !== null
                                && (float) $item['quantity_on_hand'] <= (float) $item['reorder_level'];
                            ?>
                            <tr>
                                <td class="numeric fs-sm" dir="ltr"><?= e($item['sku']) ?></td>
                                <td>
                                    <a class="fw-bold fs-sm" href="<?= e(url('/app/inventory/' . $item['id'])) ?>">
                                        <?= e($item['name_ar']) ?></a>
                                    <?php if ((int) $item['is_active'] === 0): ?>
                                        <span class="np-badge np-badge--muted">غير نشط</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fs-xs text-muted-np"><?= e($types[$item['item_type']] ?? '') ?></td>
                                <td class="numeric fs-sm <?= $low ? 'fw-bold text-danger' : '' ?>">
                                    <?php if ((int) $item['track_stock'] === 1): ?>
                                        <?= e(number_ar((float) $item['quantity_on_hand'], 2)) ?>
                                        <span class="fs-xs text-muted-np"><?= e($item['unit_of_measure']) ?></span>
                                    <?php else: ?>
                                        <span class="fs-xs text-muted-np">غير متتبَّع</span>
                                    <?php endif; ?>
                                </td>
                                <td class="numeric fs-sm">
                                    <?= $item['sale_price'] !== null ? e(money((float) $item['sale_price'])) : '—' ?>
                                </td>
                                <td class="numeric fs-sm text-muted-np">
                                    <?= $item['cost_price'] !== null ? e(money((float) $item['cost_price'])) : '—' ?>
                                </td>
                                <td class="fs-xs">
                                    <?= $item['listing_id'] !== null
                                        ? '<span class="np-badge np-badge--info">نعم</span>' : '—' ?>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/app/inventory/' . $item['id'])) ?>">فتح</a>
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
    <nav class="mt-3" aria-label="تصفّح الصفحات">
        <ul class="pagination pagination-sm justify-content-center mb-0">
            <?php for ($page = 1; $page <= $results['last_page']; $page++): ?>
                <li class="page-item <?= $page === $results['page'] ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e(url('/app/inventory?page=' . $page)) ?>">
                        <?= e(number_ar($page)) ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
