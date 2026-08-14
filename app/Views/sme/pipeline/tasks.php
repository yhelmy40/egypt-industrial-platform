<?php
/**
 * المهام والمتابعات | Tasks and follow-ups (§4.9).
 *
 * القائمة تبدأ بما فات موعده: ترتيب يدفع للتصرّف بدل أن يُخفي المتأخّر.
 *
 * @var array<int,array<string,mixed>> $tasks
 * @var array<int,array<string,mixed>> $customers
 * @var bool $mine
 * @var \App\Services\PipelineService $service
 */

$overdue = array_filter($tasks, static fn (array $t): bool => (int) $t['is_overdue'] === 1);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">المهام والمتابعات</h1>
        <p class="fs-sm text-muted-np mb-0">
            <?= e(number_ar(count($tasks))) ?> مهمة قيد التنفيذ،
            منها <?= e(number_ar(count($overdue))) ?> فات موعدها.
        </p>
    </div>
    <div class="d-flex gap-1">
        <a class="btn btn-sm <?= $mine ? 'btn-outline-primary' : 'btn-primary' ?>"
           href="<?= e(url('/app/pipeline/tasks')) ?>">الكل</a>
        <a class="btn btn-sm <?= $mine ? 'btn-primary' : 'btn-outline-primary' ?>"
           href="<?= e(url('/app/pipeline/tasks?mine=1')) ?>">مهامي</a>
    </div>
</div>

<?php if ($customers !== []): ?>
    <div class="np-card mb-3">
        <div class="np-card__header"><h2 class="h6 mb-0">مهمة جديدة</h2></div>
        <form method="post" action="<?= e(url('/app/pipeline/activities')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="status" value="planned">
            <input type="hidden" name="activity_type" value="task">
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
                <div class="col-md-4">
                    <label class="form-label fs-sm" for="subject_ar">المهمة <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" id="subject_ar"
                           name="subject_ar" required maxlength="200" placeholder="متابعة عرض السعر…">
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-sm" for="due_at">الموعد <span class="text-danger">*</span></label>
                    <input type="datetime-local" class="form-control form-control-sm" dir="ltr"
                           id="due_at" name="due_at" required>
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
        <?php if ($tasks === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">✅</div>
                <p class="mb-1">لا مهام مستحقّة.</p>
                <p class="fs-sm mb-0">أضف مهمة متابعة حتى لا يضيع عميل بسبب النسيان.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>المهمة</th><th>مرتبطة بـ</th><th>الموعد</th>
                        <th>المسؤول</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <tr>
                                <td>
                                    <strong class="fs-sm"><?= e($task['subject_ar']) ?></strong>
                                    <?php if ((int) $task['is_overdue'] === 1): ?>
                                        <span class="np-badge np-badge--danger">فات موعدها</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fs-sm">
                                    <?php if (!empty($task['customer_name'])): ?>
                                        <a href="<?= e(url('/app/customers/' . $task['customer_id'])) ?>">
                                            <?= e($task['customer_name']) ?></a>
                                    <?php elseif (!empty($task['opportunity_title'])): ?>
                                        <?= e($task['opportunity_title']) ?>
                                    <?php elseif (!empty($task['lead_name'])): ?>
                                        <?= e($task['lead_name']) ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td class="fs-xs text-muted-np">
                                    <?= $task['due_at'] !== null
                                        ? e(format_date((string) $task['due_at'], true)) : '—' ?>
                                </td>
                                <td class="fs-sm"><?= e($task['assignee_name'] ?? '—') ?></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <form method="post"
                                              action="<?= e(url('/app/pipeline/activities/' . $task['id'] . '/complete')) ?>"
                                              class="d-flex gap-1">
                                            <?= csrf_field() ?>
                                            <label class="visually-hidden"
                                                   for="outcome_<?= e((string) $task['id']) ?>">النتيجة</label>
                                            <input type="text" class="form-control form-control-sm"
                                                   id="outcome_<?= e((string) $task['id']) ?>"
                                                   name="outcome_ar" maxlength="2000"
                                                   placeholder="النتيجة" style="min-width:10rem">
                                            <button type="submit" class="btn btn-sm btn-primary">إنجاز</button>
                                        </form>
                                        <form method="post"
                                              action="<?= e(url('/app/pipeline/activities/' . $task['id'] . '/cancel')) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                    data-confirm="إلغاء هذه المهمة؟">إلغاء</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
