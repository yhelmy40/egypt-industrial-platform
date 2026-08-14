<?php
/**
 * تفاصيل حالة الدعم | BDS case detail — shared by both sides (§4.8).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * قالب واحد يخدم المركز والمشروع، لأن ما يريانه من الحالة واحد في بنيته:
 * الطلب، الجلسات، الخطة، التوصيات، السجل. الاختلاف في **ما وصل إلى القالب**
 * لا في ما يطبعه: المستودع أسقط الملاحظات الداخلية والخطط غير المشتركة من
 * نسخة المشروع قبل أن تصل هنا.
 *
 * هذا مقصود: لو كان الحجب في القالب لصار كل تعديل مستقبلي فرصة تسريب.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @var array<string,mixed> $case
 * @var array<int,array<string,mixed>> $notes
 * @var array<int,array<string,mixed>> $consultations
 * @var array<int,array<string,mixed>> $plans
 * @var array<int,array<string,mixed>> $referrals
 * @var array<int,array<string,mixed>> $history
 * @var array<int,string> $actions
 * @var array<string,string> $sections
 * @var \App\Services\BdsCaseService $service
 * @var string $side          center|organization
 * @var string $basePath
 */
$isCenter   = $side === 'center';
$status     = (string) $case['status'];
$isClosed   = str_starts_with($status, 'closed_');
$focusAreas = array_values(array_filter(explode(',', (string) ($case['focus_areas'] ?? ''))));

$counterpart = $isCenter
    ? ($case['sme_trading_name'] ?: $case['sme_legal_name'])
    : ($case['center_trading_name'] ?: $case['center_legal_name']);

$taskStatusLabel = static fn (string $s): string => match ($s) {
    'in_progress' => 'قيد التنفيذ',
    'done'        => 'منجزة',
    'skipped'     => 'مُتجاوَزة',
    default       => 'لم تبدأ',
};
$sessionStatusLabel = static fn (string $s): string => match ($s) {
    'completed' => 'تمّت',
    'no_show'   => 'لم يحضر',
    'cancelled' => 'أُلغيت',
    default     => 'مجدولة',
};
?>
<a class="fs-sm text-muted-np" href="<?= e(url($basePath)) ?>">→ كل الحالات</a>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-1 mb-3">
    <div>
        <h1 class="h5 mb-1">
            <span class="numeric" dir="ltr"><?= e($case['case_number']) ?></span>
            <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
                <?= e($service->statusLabel($status)) ?></span>
        </h1>
        <span class="fs-sm text-muted-np">
            <?= e($case['title_ar']) ?> ·
            <?= $isCenter ? 'المشروع' : 'المركز' ?>: <?= e($counterpart) ?>
        </span>
    </div>
    <span class="fs-sm text-muted-np"><?= e(format_date($case['created_at'], true)) ?></span>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <?php // ─── الطلب ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">طلب الدعم</div>
            <div class="np-card__body">
                <p style="white-space:pre-line"><?= e($case['request_details_ar']) ?></p>

                <?php if ($focusAreas !== []): ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($focusAreas as $area): ?>
                            <span class="np-badge np-badge--info">
                                <?= e($sections[$area] ?? $area) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($isCenter): ?>
                    <dl class="row fs-sm mt-3 mb-0">
                        <dt class="col-4 fw-normal text-muted-np">المنشأة</dt>
                        <dd class="col-8">
                            <a target="_blank" rel="noopener"
                               href="<?= e(url('/business/' . $case['sme_slug'])) ?>">
                                <?= e($counterpart) ?> ↗</a>
                        </dd>
                        <?php if (!empty($case['governorate_name'])): ?>
                            <dt class="col-4 fw-normal text-muted-np">المحافظة</dt>
                            <dd class="col-8"><?= e($case['governorate_name']) ?></dd>
                        <?php endif; ?>
                        <?php if (!empty($case['sector_name'])): ?>
                            <dt class="col-4 fw-normal text-muted-np">القطاع</dt>
                            <dd class="col-8"><?= e($case['sector_name']) ?></dd>
                        <?php endif; ?>
                        <dt class="col-4 fw-normal text-muted-np">اكتمال الملف</dt>
                        <dd class="col-8 numeric"><?= e(number_ar((int) $case['completion_score'])) ?>٪</dd>
                    </dl>
                <?php endif; ?>

                <?php if ($isClosed && !empty($case['outcome_summary_ar'])): ?>
                    <div class="alert <?= $status === 'closed_completed' ? 'alert-success' : 'alert-warning' ?> mt-3 mb-0">
                        <strong>نتيجة الحالة:</strong>
                        <p class="mb-0" style="white-space:pre-line">
                            <?= e($case['outcome_summary_ar']) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php // ─── الجلسات ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">الجلسات الاستشارية</div>
            <div class="np-card__body">
                <?php if ($consultations === []): ?>
                    <p class="fs-sm text-muted-np <?= $isCenter ? '' : 'mb-0' ?>">لا توجد جلسات مجدولة.</p>
                <?php else: ?>
                    <?php foreach ($consultations as $session): ?>
                        <div class="step-item">
                            <span class="step-item__marker" aria-hidden="true">
                                <?= $session['status'] === 'completed' ? '✓' : '•' ?></span>
                            <div class="flex-grow-1">
                                <div class="step-item__title fs-sm d-flex flex-wrap gap-2">
                                    <?= e($session['title_ar']) ?>
                                    <span class="np-badge <?= $session['status'] === 'completed'
                                        ? 'np-badge--success' : 'np-badge--draft' ?>">
                                        <?= e($sessionStatusLabel((string) $session['status'])) ?></span>
                                </div>
                                <p class="step-item__desc mb-1">
                                    <?= e(format_date($session['scheduled_at'], true)) ?>
                                    · <?= e(match ((string) $session['mode']) {
                                        'phone'  => 'هاتفياً',
                                        'online' => 'عن بُعد',
                                        default  => 'حضورياً',
                                    }) ?>
                                    <?php if (!empty($session['location_ar'])): ?>
                                        · <?= e($session['location_ar']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($session['specialist_name'])): ?>
                                        · <?= e($session['specialist_name']) ?>
                                    <?php endif; ?>
                                </p>

                                <?php if (!empty($session['summary_ar'])): ?>
                                    <p class="fs-sm mb-1" style="white-space:pre-line">
                                        <?= e($session['summary_ar']) ?></p>
                                <?php endif; ?>

                                <?php // الملاحظة الخاصة لا تصل نسخة المشروع أصلاً ?>
                                <?php if ($isCenter && !empty($session['internal_note_ar'])): ?>
                                    <p class="fs-xs text-muted-np mb-1">
                                        <em>ملاحظة داخلية:</em> <?= e($session['internal_note_ar']) ?></p>
                                <?php endif; ?>

                                <?php if ($isCenter && $session['status'] === 'scheduled'): ?>
                                    <details class="mt-1">
                                        <summary class="fs-xs" style="cursor:pointer">تسجيل نتيجة الجلسة</summary>
                                        <form method="post" class="mt-2" data-guard
                                              action="<?= e(url($basePath . '/' . $case['id'] . '/consultations/record')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="consultation_id"
                                                   value="<?= e((string) $session['id']) ?>">

                                            <label class="form-label fs-sm"
                                                   for="st_<?= e((string) $session['id']) ?>">الحالة</label>
                                            <select class="form-select form-select-sm"
                                                    id="st_<?= e((string) $session['id']) ?>" name="status">
                                                <option value="completed">تمّت</option>
                                                <option value="no_show">لم يحضر</option>
                                                <option value="cancelled">أُلغيت</option>
                                            </select>

                                            <label class="form-label fs-sm mt-2"
                                                   for="sm_<?= e((string) $session['id']) ?>">
                                                ملخّص الجلسة (يراه المشروع)</label>
                                            <textarea class="form-control form-control-sm"
                                                      id="sm_<?= e((string) $session['id']) ?>"
                                                      name="summary_ar" rows="3" maxlength="2000"></textarea>

                                            <label class="form-label fs-sm mt-2"
                                                   for="in_<?= e((string) $session['id']) ?>">
                                                ملاحظة داخلية (لا يراها المشروع)</label>
                                            <textarea class="form-control form-control-sm"
                                                      id="in_<?= e((string) $session['id']) ?>"
                                                      name="internal_note_ar" rows="2" maxlength="2000"></textarea>

                                            <button type="submit" class="btn btn-sm btn-primary mt-2">حفظ</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($isCenter && !$isClosed): ?>
                    <details class="mt-3">
                        <summary class="btn btn-sm btn-outline-primary" style="cursor:pointer">
                            + جدولة جلسة</summary>
                        <form method="post" class="row g-2 mt-2" data-guard
                              action="<?= e(url($basePath . '/' . $case['id'] . '/consultations')) ?>">
                            <?= csrf_field() ?>
                            <div class="col-md-4">
                                <label class="form-label fs-sm" for="session_title">العنوان</label>
                                <input type="text" class="form-control form-control-sm" id="session_title"
                                       name="title_ar" maxlength="200" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fs-sm" for="session_at">الموعد</label>
                                <input type="datetime-local" class="form-control form-control-sm" dir="ltr"
                                       id="session_at" name="scheduled_at" required>
                            </div>
                            <div class="col-md-2 col-6">
                                <label class="form-label fs-sm" for="session_mode">الطريقة</label>
                                <select class="form-select form-select-sm" id="session_mode" name="mode">
                                    <option value="onsite">حضورياً</option>
                                    <option value="phone">هاتفياً</option>
                                    <option value="online">عن بُعد</option>
                                </select>
                            </div>
                            <div class="col-md-2 col-6">
                                <label class="form-label fs-sm" for="session_duration">المدة (دقائق)</label>
                                <input type="number" class="form-control form-control-sm" dir="ltr"
                                       id="session_duration" name="duration_minutes" min="15" max="480">
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-primary w-100">+</button>
                            </div>
                            <div class="col-12">
                                <label class="visually-hidden" for="session_location">المكان</label>
                                <input type="text" class="form-control form-control-sm" id="session_location"
                                       name="location_ar" maxlength="300" placeholder="المكان أو تفاصيل اللقاء">
                                <p class="form-text">
                                    المنصة تسجّل الموعد ولا توفّر اجتماعات مرئية؛ رابط اللقاء يُتفق عليه خارجها.
                                </p>
                            </div>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        </div>

        <?php // ─── خطط العمل ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">خطط العمل</div>
            <div class="np-card__body">
                <?php if ($plans === []): ?>
                    <p class="fs-sm text-muted-np <?= $isCenter ? '' : 'mb-0' ?>">
                        <?= $isCenter ? 'لم تُنشأ خطة بعد.' : 'لم يشارك المركز خطة عمل بعد.' ?>
                    </p>
                <?php else: ?>
                    <?php foreach ($plans as $plan): ?>
                        <div class="border-bottom border-np pb-3 mb-3">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <strong><?= e($plan['title_ar']) ?></strong>
                                    <span class="np-badge <?= $plan['shared_at'] !== null
                                        ? 'np-badge--success' : 'np-badge--draft' ?>">
                                        <?= $plan['shared_at'] !== null ? 'مشتركة' : 'مسودة داخلية' ?></span>
                                    <?php if (!empty($plan['objective_ar'])): ?>
                                        <p class="fs-sm text-muted-np mb-0 mt-1">
                                            <?= e($plan['objective_ar']) ?></p>
                                    <?php endif; ?>
                                </div>

                                <?php if ($isCenter && $plan['shared_at'] === null): ?>
                                    <form method="post" data-guard
                                          action="<?= e(url($basePath . '/' . $case['id'] . '/plans/share')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="plan_id" value="<?= e((string) $plan['id']) ?>">
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            مشاركة مع المشروع</button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <?php if ($plan['tasks'] === []): ?>
                                <p class="fs-sm text-muted-np mt-2 mb-0">لا مهام بعد.</p>
                            <?php else: ?>
                                <div class="table-scroll mt-2" style="border:0">
                                    <table class="np-table">
                                        <tbody>
                                            <?php foreach ($plan['tasks'] as $task): ?>
                                                <?php
                                                $ownerIsMe = $isCenter
                                                    ? $task['owner_side'] === 'center'
                                                    : $task['owner_side'] === 'organization';
                                                ?>
                                                <tr>
                                                    <td>
                                                        <?= e($task['title_ar']) ?>
                                                        <?php if (!empty($task['description_ar'])): ?>
                                                            <div class="fs-xs text-muted-np">
                                                                <?= e($task['description_ar']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="fs-xs text-muted-np">
                                                        على <?= $task['owner_side'] === 'center'
                                                            ? 'المركز' : 'المشروع' ?>
                                                        <?php if (!empty($task['due_date'])): ?>
                                                            <div><?= e(format_date($task['due_date'])) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="width:12rem">
                                                        <?php if ($ownerIsMe && $plan['shared_at'] !== null): ?>
                                                            <form method="post" class="d-flex gap-1"
                                                                  action="<?= e(url($basePath . '/' . $case['id']
                                                                      . ($isCenter ? '/plans/tasks/update' : '/tasks'))) ?>">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="task_id"
                                                                       value="<?= e((string) $task['id']) ?>">
                                                                <label class="visually-hidden"
                                                                       for="tk_<?= e((string) $task['id']) ?>">الحالة</label>
                                                                <select class="form-select form-select-sm"
                                                                        id="tk_<?= e((string) $task['id']) ?>"
                                                                        name="status">
                                                                    <?php foreach (['pending','in_progress','done','skipped'] as $option): ?>
                                                                        <option value="<?= e($option) ?>"
                                                                            <?= $task['status'] === $option ? ' selected' : '' ?>>
                                                                            <?= e($taskStatusLabel($option)) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <button type="submit"
                                                                        class="btn btn-sm btn-outline-primary">حفظ</button>
                                                            </form>
                                                        <?php else: ?>
                                                            <span class="np-badge <?= $task['status'] === 'done'
                                                                ? 'np-badge--success' : 'np-badge--draft' ?>">
                                                                <?= e($taskStatusLabel((string) $task['status'])) ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <?php if ($isCenter): ?>
                                <details class="mt-2">
                                    <summary class="fs-xs" style="cursor:pointer">+ إضافة مهمة</summary>
                                    <form method="post" class="row g-2 mt-1" data-guard
                                          action="<?= e(url($basePath . '/' . $case['id'] . '/plans/tasks')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="plan_id" value="<?= e((string) $plan['id']) ?>">
                                        <div class="col-md-4">
                                            <label class="visually-hidden"
                                                   for="tt_<?= e((string) $plan['id']) ?>">عنوان المهمة</label>
                                            <input type="text" class="form-control form-control-sm"
                                                   id="tt_<?= e((string) $plan['id']) ?>" name="title_ar"
                                                   maxlength="200" required placeholder="عنوان المهمة">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="visually-hidden"
                                                   for="td_<?= e((string) $plan['id']) ?>">الوصف</label>
                                            <input type="text" class="form-control form-control-sm"
                                                   id="td_<?= e((string) $plan['id']) ?>" name="description_ar"
                                                   maxlength="1000" placeholder="وصف (اختياري)">
                                        </div>
                                        <div class="col-md-2 col-6">
                                            <label class="visually-hidden"
                                                   for="to_<?= e((string) $plan['id']) ?>">على من</label>
                                            <select class="form-select form-select-sm"
                                                    id="to_<?= e((string) $plan['id']) ?>" name="owner_side">
                                                <option value="organization">على المشروع</option>
                                                <option value="center">على المركز</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2 col-4">
                                            <label class="visually-hidden"
                                                   for="tdue_<?= e((string) $plan['id']) ?>">الموعد</label>
                                            <input type="date" class="form-control form-control-sm" dir="ltr"
                                                   id="tdue_<?= e((string) $plan['id']) ?>" name="due_date">
                                        </div>
                                        <div class="col-md-1 col-2 d-grid">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">+</button>
                                        </div>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($isCenter && !$isClosed): ?>
                    <details>
                        <summary class="btn btn-sm btn-outline-primary" style="cursor:pointer">
                            + خطة عمل جديدة</summary>
                        <form method="post" class="row g-2 mt-2" data-guard
                              action="<?= e(url($basePath . '/' . $case['id'] . '/plans')) ?>">
                            <?= csrf_field() ?>
                            <div class="col-md-5">
                                <label class="form-label fs-sm" for="plan_title">عنوان الخطة</label>
                                <input type="text" class="form-control form-control-sm" id="plan_title"
                                       name="title_ar" maxlength="200" required>
                            </div>
                            <div class="col-md-3 col-6">
                                <label class="form-label fs-sm" for="plan_starts">تبدأ</label>
                                <input type="date" class="form-control form-control-sm" dir="ltr"
                                       id="plan_starts" name="starts_on">
                            </div>
                            <div class="col-md-3 col-6">
                                <label class="form-label fs-sm" for="plan_ends">تنتهي</label>
                                <input type="date" class="form-control form-control-sm" dir="ltr"
                                       id="plan_ends" name="ends_on">
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-primary w-100">+</button>
                            </div>
                            <div class="col-12">
                                <label class="visually-hidden" for="plan_objective">الهدف</label>
                                <textarea class="form-control form-control-sm" id="plan_objective"
                                          name="objective_ar" rows="2" maxlength="2000"
                                          placeholder="هدف الخطة"></textarea>
                                <p class="form-text">
                                    تبقى الخطة مسودة داخلية حتى تشاركها. لا يراها المشروع قبل ذلك.
                                </p>
                            </div>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        </div>

        <?php // ─── الملاحظات ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">
                الملاحظات
                <?php if ($isCenter): ?>
                    <span class="np-badge np-badge--warning">تشمل ملاحظات داخلية لا يراها المشروع</span>
                <?php endif; ?>
            </div>
            <div class="np-card__body">
                <form method="post" class="mb-3" data-guard
                      action="<?= e(url($basePath . '/' . $case['id'] . '/notes')) ?>">
                    <?= csrf_field() ?>
                    <label class="form-label fs-sm" for="note_body">ملاحظة جديدة</label>
                    <textarea class="form-control" id="note_body" name="body_ar" rows="3"
                              maxlength="3000" required></textarea>

                    <?php if ($isCenter): ?>
                        <div class="d-flex flex-wrap gap-3 mt-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="visibility"
                                       value="internal" id="vis_internal" checked>
                                <label class="form-check-label fs-sm" for="vis_internal">
                                    داخلية — لا يراها المشروع</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="visibility"
                                       value="shared" id="vis_shared">
                                <label class="form-check-label fs-sm" for="vis_shared">
                                    مشتركة — يراها المشروع</label>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="form-text">ملاحظتك يراها المركز.</p>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-sm btn-primary mt-2">إضافة</button>
                </form>

                <?php if ($notes === []): ?>
                    <p class="fs-sm text-muted-np mb-0">لا ملاحظات بعد.</p>
                <?php else: ?>
                    <?php foreach ($notes as $note): ?>
                        <div class="p-2 border-bottom border-np">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <strong class="fs-sm">
                                    <?= e($note['author_name'] ?? 'مستخدم') ?>
                                    <span class="fs-xs text-muted-np fw-normal">
                                        (<?= $note['author_side'] === 'center' ? 'المركز' : 'المشروع' ?>)</span>
                                    <?php if ($note['visibility'] === 'internal'): ?>
                                        <span class="np-badge np-badge--warning">داخلية</span>
                                    <?php endif; ?>
                                </strong>
                                <span class="fs-xs text-muted-np">
                                    <?= e(time_ago($note['created_at'])) ?></span>
                            </div>
                            <p class="fs-sm mb-0 mt-1" style="white-space:pre-line">
                                <?= e($note['body_ar']) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php // ─── السجل ─── ?>
        <div class="np-card">
            <div class="np-card__header">سجل الحالة</div>
            <div class="np-card__body">
                <?php foreach ($history as $entry): ?>
                    <div class="step-item">
                        <span class="step-item__marker" aria-hidden="true">•</span>
                        <div>
                            <div class="step-item__title fs-sm">
                                <?= e($service->statusLabel((string) $entry['to_status'])) ?></div>
                            <p class="step-item__desc mb-0">
                                <?= e(match ((string) $entry['actor_side']) {
                                    'organization' => 'المشروع',
                                    'center'       => 'المركز',
                                    'platform'     => 'المنصة',
                                    default        => 'النظام',
                                }) ?>
                                <?php if (!empty($entry['actor_name'])): ?>
                                    · <?= e($entry['actor_name']) ?>
                                <?php endif; ?>
                                · <?= e(format_date($entry['created_at'], true)) ?>
                                <?php if (!empty($entry['note_ar'])): ?>
                                    <br><?= e($entry['note_ar']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php // ─── الإجراءات ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">الإجراءات المتاحة لك</div>
            <div class="np-card__body">
                <?php if ($actions === []): ?>
                    <p class="fs-sm text-muted-np mb-0">لا توجد إجراءات متاحة على هذا الوضع.</p>
                <?php else: ?>
                    <?php foreach ($actions as $action): ?>
                        <form method="post" class="mb-2" data-guard
                              action="<?= e(url($basePath . '/' . $case['id'] . '/action')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= e($action) ?>">

                            <?php if (in_array($action, ['assign', 'reassign'], true)): ?>
                                <details>
                                    <summary class="btn btn-sm btn-primary w-100" style="cursor:pointer">
                                        <?= e($service->actionLabel($action)) ?></summary>
                                    <label class="form-label fs-sm mt-2" for="assignee_<?= e($action) ?>">
                                        الأخصائي</label>
                                    <select class="form-select form-select-sm"
                                            id="assignee_<?= e($action) ?>" name="assigned_to" required>
                                        <option value="">اختر الأخصائي</option>
                                        <?php foreach (($specialists ?? []) as $specialist): ?>
                                            <option value="<?= e((string) $specialist['id']) ?>">
                                                <?= e($specialist['name']) ?>
                                                (<?= e(number_ar((int) $specialist['active_cases'])) ?> حالة نشطة)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary w-100 mt-2">تأكيد</button>
                                </details>
                            <?php elseif ($service->requiresText($action)): ?>
                                <details>
                                    <summary class="btn btn-sm <?= str_starts_with($action, 'close_')
                                        ? 'btn-primary' : 'btn-outline-danger' ?> w-100" style="cursor:pointer">
                                        <?= e($service->actionLabel($action)) ?></summary>
                                    <label class="form-label fs-sm mt-2" for="note_<?= e($action) ?>">
                                        <?= str_starts_with($action, 'close_') ? 'ملخّص النتيجة' : 'السبب' ?>
                                        <span class="text-danger">*</span></label>
                                    <textarea class="form-control form-control-sm" id="note_<?= e($action) ?>"
                                              name="note" rows="3" required maxlength="2000"></textarea>
                                    <button type="submit" class="btn btn-sm btn-primary w-100 mt-2">تأكيد</button>
                                </details>
                            <?php else: ?>
                                <button type="submit" class="btn btn-sm btn-primary w-100">
                                    <?= e($service->actionLabel($action)) ?></button>
                            <?php endif; ?>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php // ─── التوصيات ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">التوصيات والإحالات</div>
            <div class="np-card__body">
                <?php if ($referrals === []): ?>
                    <p class="fs-sm text-muted-np <?= $isCenter ? '' : 'mb-0' ?>">لا توصيات بعد.</p>
                <?php else: ?>
                    <?php foreach ($referrals as $referral): ?>
                        <div class="border-bottom border-np pb-2 mb-2">
                            <strong class="fs-sm"><?= e($referral['target_name_ar']) ?></strong>
                            <span class="np-badge <?= match ((string) $referral['status']) {
                                'accepted' => 'np-badge--success',
                                'declined' => 'np-badge--muted',
                                default    => 'np-badge--pending',
                            } ?>">
                                <?= e(match ((string) $referral['status']) {
                                    'accepted' => 'قبلها المشروع',
                                    'declined' => 'اعتذر عنها',
                                    'acted'    => 'تصرّف بناءً عليها',
                                    default    => 'بانتظار الرد',
                                }) ?>
                            </span>
                            <p class="fs-sm text-muted-np mb-1 mt-1"><?= e($referral['reason_ar']) ?></p>

                            <a class="fs-xs" target="_blank" rel="noopener"
                               href="<?= e(url(($referral['target_type'] === 'financing_product'
                                   ? '/financing' : '/services'))) ?>">استعرض العروض ↗</a>

                            <?php if (!$isCenter && $referral['status'] === 'suggested'): ?>
                                <form method="post" class="d-flex gap-1 mt-2"
                                      action="<?= e(url($basePath . '/' . $case['id'] . '/referrals')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="referral_id"
                                           value="<?= e((string) $referral['id']) ?>">
                                    <button type="submit" name="decision" value="accepted"
                                            class="btn btn-sm btn-outline-primary flex-grow-1">تهمّني</button>
                                    <button type="submit" name="decision" value="declined"
                                            class="btn btn-sm btn-link text-muted-np">لا تناسبني</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($isCenter && !$isClosed): ?>
                    <details class="mt-2">
                        <summary class="btn btn-sm btn-outline-primary w-100" style="cursor:pointer">
                            + توصية جديدة</summary>
                        <form method="post" class="mt-2" data-guard
                              action="<?= e(url($basePath . '/' . $case['id'] . '/referrals')) ?>">
                            <?= csrf_field() ?>

                            <label class="form-label fs-sm" for="target_type">النوع</label>
                            <select class="form-select form-select-sm" id="target_type" name="target_type">
                                <option value="financing_product">منتج تمويلي</option>
                                <option value="service_offering">باقة خدمة</option>
                            </select>

                            <label class="form-label fs-sm mt-2" for="target_id">العرض</label>
                            <select class="form-select form-select-sm" id="target_id" name="target_id" required>
                                <option value="">اختر العرض</option>
                                <optgroup label="منتجات تمويلية">
                                    <?php foreach (($referable['financing_product'] ?? []) as $offer): ?>
                                        <option value="<?= e((string) $offer['id']) ?>">
                                            <?= e($offer['name_ar']) ?>
                                            — <?= e($offer['trading_name'] ?: $offer['legal_name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="باقات خدمات">
                                    <?php foreach (($referable['service_offering'] ?? []) as $offer): ?>
                                        <option value="<?= e((string) $offer['id']) ?>">
                                            <?= e($offer['name_ar']) ?>
                                            — <?= e($offer['trading_name'] ?: $offer['legal_name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                            <p class="form-text">اختر النوع المطابق للعرض المحدَّد.</p>

                            <label class="form-label fs-sm" for="reason_ar">
                                لماذا يناسب المشروع؟ <span class="text-danger">*</span></label>
                            <textarea class="form-control form-control-sm" id="reason_ar" name="reason_ar"
                                      rows="3" required maxlength="1000"></textarea>

                            <button type="submit" class="btn btn-sm btn-primary w-100 mt-2">تسجيل التوصية</button>

                            <p class="fs-xs text-muted-np mb-0 mt-2">
                                التوصية لا تُنشئ طلباً ولا تُلزم الجهة المُحال إليها. التقديم قرار المشروع،
                                والقبول قرار الجهة.
                            </p>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        </div>

        <?php // ─── التقييم بعد الإغلاق ─── ?>
        <?php if (!$isCenter && $isClosed): ?>
            <div class="np-card">
                <div class="np-card__header">تقييم الدعم</div>
                <div class="np-card__body">
                    <?php if ($case['satisfaction_rating'] !== null): ?>
                        <p class="fs-sm mb-0">
                            تقييمك:
                            <?= e(str_repeat('★', (int) $case['satisfaction_rating'])) ?><span
                                class="text-muted-np"><?= e(str_repeat('☆', 5 - (int) $case['satisfaction_rating'])) ?></span>
                        </p>
                        <?php if (!empty($case['satisfaction_comment_ar'])): ?>
                            <p class="fs-sm text-muted-np mb-0 mt-1">
                                <?= e($case['satisfaction_comment_ar']) ?></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="post" data-guard
                              action="<?= e(url($basePath . '/' . $case['id'] . '/rate')) ?>">
                            <?= csrf_field() ?>
                            <label class="form-label fs-sm" for="rating">كيف تقيّم الدعم الذي تلقّيته؟</label>
                            <select class="form-select form-select-sm" id="rating" name="rating" required>
                                <option value="">اختر</option>
                                <?php foreach ([5, 4, 3, 2, 1] as $point): ?>
                                    <option value="<?= e((string) $point) ?>">
                                        <?= e(str_repeat('★', $point)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="form-label fs-sm mt-2" for="comment">ملاحظة (اختياري)</label>
                            <textarea class="form-control form-control-sm" id="comment" name="comment"
                                      rows="2" maxlength="1000"></textarea>
                            <button type="submit" class="btn btn-sm btn-primary w-100 mt-2">إرسال التقييم</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isCenter && !empty($case['specialist_name'])): ?>
            <div class="np-card">
                <div class="np-card__header">الأخصائي المسؤول</div>
                <div class="np-card__body">
                    <p class="fs-sm mb-0">
                        <?= e($case['specialist_name']) ?>
                        <?php if (!empty($case['assigned_at'])): ?>
                            <span class="fs-xs text-muted-np d-block">
                                منذ <?= e(format_date($case['assigned_at'])) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
