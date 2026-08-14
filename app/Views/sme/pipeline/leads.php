<?php
/**
 * المهتمّون | Leads (§4.9).
 *
 * المهتمّ ليس عميلاً حتى يُحوَّل: التحويل زرّ صريح يُنشئ ملفّ عميل ويحفظ أثر
 * مصدر العلاقة.
 *
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int} $results
 * @var array<string,string> $filters
 * @var \App\Services\PipelineService $service
 */

$statuses = ['' => 'الكل', 'new' => 'جديد', 'contacted' => 'جرى التواصل',
             'qualified' => 'مؤهَّل', 'converted' => 'حُوِّل', 'lost' => 'مفقود'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">المهتمّون</h1>
        <p class="fs-sm text-muted-np mb-0">
            من أبدى اهتماماً ولم يصر عميلاً بعد — <?= e(number_ar($results['total'])) ?> مهتمّاً.
        </p>
    </div>
    <div class="d-flex flex-wrap gap-1">
        <?php foreach ($statuses as $value => $label): ?>
            <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= e(url('/app/pipeline/leads') . ($value === '' ? '' : '?status=' . $value)) ?>">
                <?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="np-card mb-3">
    <div class="np-card__header"><h2 class="h6 mb-0">إضافة مهتمّ</h2></div>
    <form method="post" action="<?= e(url('/app/pipeline/leads')) ?>">
        <?= csrf_field() ?>
        <div class="np-card__body row g-2">
            <div class="col-md-3">
                <label class="form-label fs-sm" for="name_ar">الاسم <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm" id="name_ar" name="name_ar"
                       required maxlength="200">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-sm" for="phone">الهاتف</label>
                <input type="tel" class="form-control form-control-sm numeric" dir="ltr"
                       id="phone" name="phone" maxlength="30">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-sm" for="source">المصدر</label>
                <select class="form-select form-select-sm" id="source" name="source">
                    <?php foreach (['walk_in' => 'زيارة مباشرة', 'marketplace' => 'سوق المنصة',
                                    'referral' => 'ترشيح', 'social' => 'وسائل التواصل',
                                    'event' => 'معرض أو فعالية', 'other' => 'أخرى'] as $key => $label): ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fs-sm" for="interest_ar">ما يبحث عنه</label>
                <input type="text" class="form-control form-control-sm" id="interest_ar"
                       name="interest_ar" maxlength="1000">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-sm btn-primary w-100">إضافة</button>
            </div>
        </div>
    </form>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($results['data'] === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">🌱</div>
                <p class="mb-1">لا مهتمّين مطابقين.</p>
                <p class="fs-sm mb-0">سجّل هنا كل من سأل عن منتجاتك قبل أن يصير عميلاً.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>الاسم</th><th>الهاتف</th><th>ما يبحث عنه</th>
                        <th>المصدر</th><th>الحالة</th><th>التاريخ</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($results['data'] as $lead): ?>
                            <?php $status = (string) $lead['status']; ?>
                            <tr>
                                <td class="fw-bold fs-sm"><?= e($lead['name_ar']) ?></td>
                                <td class="numeric fs-sm" dir="ltr"><?= e($lead['phone'] ?? '—') ?></td>
                                <td class="fs-sm"><?= e($lead['interest_ar'] ?? '—') ?></td>
                                <td class="fs-xs text-muted-np"><?= e((string) $lead['source']) ?></td>
                                <td>
                                    <span class="np-badge <?= e(match ($status) {
                                        'converted' => 'np-badge--success',
                                        'qualified' => 'np-badge--info',
                                        'contacted' => 'np-badge--review',
                                        'lost'      => 'np-badge--muted',
                                        default     => 'np-badge--pending',
                                    }) ?>"><?= e($service->leadStatusLabel($status)) ?></span>
                                    <?php if ($status === 'converted' && !empty($lead['converted_customer_name'])): ?>
                                        <div class="fs-xs text-muted-np">
                                            → <?= e($lead['converted_customer_name']) ?>
                                        </div>
                                    <?php elseif ($status === 'lost' && !empty($lead['lost_reason_ar'])): ?>
                                        <div class="fs-xs text-muted-np"><?= e($lead['lost_reason_ar']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="fs-xs text-muted-np"><?= e(format_date((string) $lead['created_at'])) ?></td>
                                <td>
                                    <?php if ($status !== 'converted'): ?>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php if ($status !== 'lost'): ?>
                                                <form method="post"
                                                      action="<?= e(url('/app/pipeline/leads/' . $lead['id'] . '/convert')) ?>">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn-sm btn-primary"
                                                            data-confirm="تحويل هذا المهتمّ إلى عميل؟">
                                                        تحويل لعميل</button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="post" class="d-flex gap-1"
                                                  action="<?= e(url('/app/pipeline/leads/' . $lead['id'] . '/status')) ?>">
                                                <?= csrf_field() ?>
                                                <label class="visually-hidden"
                                                       for="status_<?= e((string) $lead['id']) ?>">الحالة</label>
                                                <select class="form-select form-select-sm"
                                                        id="status_<?= e((string) $lead['id']) ?>" name="status">
                                                    <?php foreach (['new' => 'جديد', 'contacted' => 'جرى التواصل',
                                                                    'qualified' => 'مؤهَّل',
                                                                    'lost' => 'مفقود'] as $key => $label): ?>
                                                        <option value="<?= e($key) ?>"
                                                            <?= $status === $key ? 'selected' : '' ?>>
                                                            <?= e($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <label class="visually-hidden"
                                                       for="reason_<?= e((string) $lead['id']) ?>">سبب الفقد</label>
                                                <input type="text" class="form-control form-control-sm"
                                                       id="reason_<?= e((string) $lead['id']) ?>"
                                                       name="lost_reason_ar" maxlength="500"
                                                       placeholder="سبب الفقد" style="min-width:9rem">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">حفظ</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="fs-xs text-muted-np">—</span>
                                    <?php endif; ?>
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
                    <a class="page-link"
                       href="<?= e(url('/app/pipeline/leads?page=' . $page
                            . ($filters['status'] !== '' ? '&status=' . $filters['status'] : ''))) ?>">
                        <?= e(number_ar($page)) ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
