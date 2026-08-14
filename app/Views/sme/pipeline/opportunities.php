<?php
/**
 * خطّ الفرص | The sales pipeline (§4.9).
 *
 * القيم المعروضة **تقديرات صاحب المشروع** لا تنبّؤ من المنصة، والتنويه معروض.
 *
 * @var array<int,array<string,mixed>> $opportunities
 * @var array<string,array{count:int,value:float}> $summary
 * @var array<int,array<string,mixed>> $customers
 * @var array<string,string> $filters
 * @var array<int,string> $stages
 * @var \App\Services\PipelineService $service
 */
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">خطّ الفرص</h1>
        <p class="fs-sm text-muted-np mb-0">الصفقات المحتملة ومراحلها.</p>
    </div>
</div>

<div class="row g-2 mb-3">
    <?php foreach ($stages as $stage): ?>
        <div class="col-md-2 col-4">
            <a class="stat-tile d-block text-decoration-none
                      <?= $filters['stage'] === $stage ? 'border-primary' : '' ?>"
               href="<?= e(url('/app/pipeline/opportunities?stage=' . $stage)) ?>">
                <div class="stat-tile__label"><?= e($service->stageLabel($stage)) ?></div>
                <div class="stat-value"><?= e(number_ar($summary[$stage]['count'])) ?></div>
                <div class="stat-tile__meta"><?= e(money($summary[$stage]['value'])) ?></div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<p class="fs-xs text-muted-np mb-3">
    القيم أعلاه مجموع تقديراتك أنت للفرص، وليست تنبّؤاً من المنصة ولا التزاماً من العملاء.
</p>

<?php if ($customers === []): ?>
    <div class="alert alert-info" role="alert">
        أضف عميلاً أولاً — الفرصة تُفتح على عميل في دفترك.
        <a href="<?= e(url('/app/customers/new')) ?>">إضافة عميل</a>
    </div>
<?php else: ?>
    <div class="np-card mb-3">
        <div class="np-card__header"><h2 class="h6 mb-0">فرصة جديدة</h2></div>
        <form method="post" action="<?= e(url('/app/pipeline/opportunities')) ?>">
            <?= csrf_field() ?>
            <div class="np-card__body row g-2">
                <div class="col-md-3">
                    <label class="form-label fs-sm" for="customer_id">العميل <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" id="customer_id" name="customer_id" required>
                        <option value="">— اختر —</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= e((string) $customer['id']) ?>"><?= e($customer['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-sm" for="title_ar">العنوان <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" id="title_ar" name="title_ar"
                           required maxlength="200">
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-sm" for="expected_value">القيمة المتوقّعة</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm numeric"
                           dir="ltr" id="expected_value" name="expected_value">
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-sm" for="expected_close_date">الإغلاق المتوقّع</label>
                    <input type="date" class="form-control form-control-sm" dir="ltr"
                           id="expected_close_date" name="expected_close_date">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-sm btn-primary w-100">إضافة</button>
                </div>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($opportunities === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">📈</div>
                <p class="mb-1">لا فرص في هذه المرحلة.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>الفرصة</th><th>العميل</th><th>المرحلة</th>
                        <th>القيمة المتوقّعة</th><th>الاحتمال</th><th>الإغلاق المتوقّع</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($opportunities as $opportunity): ?>
                            <tr>
                                <td>
                                    <a class="fw-bold fs-sm"
                                       href="<?= e(url('/app/pipeline/opportunities/' . $opportunity['id'])) ?>">
                                        <?= e($opportunity['title_ar']) ?></a>
                                </td>
                                <td class="fs-sm"><?= e($opportunity['customer_name']) ?></td>
                                <td>
                                    <span class="np-badge <?= e($service->stageBadgeClass((string) $opportunity['stage'])) ?>">
                                        <?= e($service->stageLabel((string) $opportunity['stage'])) ?></span>
                                </td>
                                <td class="numeric fs-sm">
                                    <?= $opportunity['expected_value'] !== null
                                        ? e(money((float) $opportunity['expected_value'])) : '—' ?>
                                </td>
                                <td class="numeric fs-sm">
                                    <?= $opportunity['probability'] !== null
                                        ? e(number_ar((int) $opportunity['probability'])) . '٪' : '—' ?>
                                </td>
                                <td class="fs-xs text-muted-np">
                                    <?= $opportunity['expected_close_date'] !== null
                                        ? e(format_date((string) $opportunity['expected_close_date'])) : '—' ?>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/app/pipeline/opportunities/' . $opportunity['id'])) ?>">فتح</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
