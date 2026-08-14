<?php
/**
 * تفاصيل طلب الخدمة | Service request detail — shared by both sides (§4.6).
 *
 * الطرفان يريان الحقائق نفسها: التفاصيل، العرض، المراحل، والسجل. الاختلاف في
 * الإجراءات المتاحة فقط، وهي تأتي محسوبة من الخدمة حسب الجهة. قالب واحد يمنع
 * انحراف العرض بين الطرفين بمرور الوقت.
 *
 * @var array<string,mixed> $serviceRequest
 * @var array<int,array<string,mixed>> $history
 * @var array<int,array<string,mixed>> $milestones
 * @var array<int,string> $actions
 * @var \App\Services\ServiceRequestService $service
 * @var string $side          applicant|provider
 * @var string $actionPath
 */
$status        = (string) $serviceRequest['status'];
$isProvider    = $side === 'provider';
$counterpart   = $isProvider
    ? ($serviceRequest['applicant_trading_name'] ?: $serviceRequest['applicant_legal_name'])
    : ($serviceRequest['provider_trading_name'] ?: $serviceRequest['provider_legal_name']);
$milestoneLabel = static fn (string $s): string => match ($s) {
    'in_progress' => 'قيد التنفيذ',
    'done'        => 'منجزة',
    'skipped'     => 'مُتجاوَزة',
    default       => 'لم تبدأ',
};
?>
<a class="fs-sm text-muted-np"
   href="<?= e(url($isProvider ? '/app/services/requests' : '/app/services/my-requests')) ?>">
    → كل الطلبات</a>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-1 mb-3">
    <h1 class="h5 mb-0">
        الطلب <span class="numeric" dir="ltr"><?= e($serviceRequest['request_number']) ?></span>
        <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
            <?= e($service->statusLabel($status)) ?></span>
    </h1>
    <span class="fs-sm text-muted-np"><?= e(format_date($serviceRequest['created_at'], true)) ?></span>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="np-card mb-3">
            <div class="np-card__header">تفاصيل الطلب</div>
            <div class="np-card__body">
                <dl class="row fs-sm">
                    <dt class="col-4 fw-normal text-muted-np">الخدمة</dt>
                    <dd class="col-8"><?= e($serviceRequest['offering_name_ar']) ?></dd>

                    <dt class="col-4 fw-normal text-muted-np">
                        <?= $isProvider ? 'المشروع' : 'مقدّم الخدمة' ?></dt>
                    <dd class="col-8"><?= e($counterpart) ?></dd>

                    <?php if (!empty($serviceRequest['preferred_start_date'])): ?>
                        <dt class="col-4 fw-normal text-muted-np">البدء المفضّل</dt>
                        <dd class="col-8"><?= e(format_date($serviceRequest['preferred_start_date'])) ?></dd>
                    <?php endif; ?>

                    <?php if ($isProvider && !empty($serviceRequest['contact_person'])): ?>
                        <dt class="col-4 fw-normal text-muted-np">مسؤول التواصل</dt>
                        <dd class="col-8">
                            <?= e($serviceRequest['contact_person']) ?>
                            <?php if (!empty($serviceRequest['contact_phone'])): ?>
                                <span class="numeric" dir="ltr">
                                    · <?= e($serviceRequest['contact_phone']) ?></span>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                </dl>

                <h2 class="h6">الاحتياج كما وصفه المشروع</h2>
                <p class="fs-sm mb-0" style="white-space:pre-line">
                    <?= e($serviceRequest['details_ar']) ?></p>
            </div>
        </div>

        <?php // ─── العرض المقدَّم ─── ?>
        <?php if ($serviceRequest['proposed_at'] !== null): ?>
            <div class="np-card mb-3">
                <div class="np-card__header">
                    العرض المقدَّم
                    <span class="fs-xs text-muted-np fw-normal">
                        <?= e(format_date($serviceRequest['proposed_at'], true)) ?></span>
                </div>
                <div class="np-card__body">
                    <div class="d-flex flex-wrap gap-4 mb-3">
                        <div>
                            <div class="stat-tile__label">قيمة العرض</div>
                            <div class="stat-value fs-4">
                                <?= (float) $serviceRequest['proposed_price'] === 0.0
                                    ? 'مجاناً'
                                    : e(money((float) $serviceRequest['proposed_price'],
                                        (string) $serviceRequest['proposed_currency'])) ?>
                            </div>
                        </div>
                        <?php if ($serviceRequest['proposed_duration_days'] !== null): ?>
                            <div>
                                <div class="stat-tile__label">مدة التنفيذ</div>
                                <div class="stat-value fs-4">
                                    <?= e(number_ar((int) $serviceRequest['proposed_duration_days'])) ?>
                                    <span class="fs-6 text-muted-np">يوماً</span></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <h3 class="h6">نطاق العمل</h3>
                    <p class="fs-sm mb-0" style="white-space:pre-line">
                        <?= e((string) $serviceRequest['proposal_note_ar']) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php // ─── مراحل التنفيذ ─── ?>
        <?php if ($milestones !== [] || ($isProvider && in_array($status, ['accepted', 'in_progress'], true))): ?>
            <div class="np-card mb-3">
                <div class="np-card__header">مراحل التنفيذ</div>
                <div class="np-card__body">
                    <?php if ($milestones === []): ?>
                        <p class="fs-sm text-muted-np">لم تُضف مراحل بعد.</p>
                    <?php else: ?>
                        <?php foreach ($milestones as $milestone): ?>
                            <div class="step-item">
                                <span class="step-item__marker" aria-hidden="true">
                                    <?= $milestone['status'] === 'done' ? '✓' : '•' ?></span>
                                <div class="flex-grow-1">
                                    <div class="step-item__title fs-sm d-flex flex-wrap gap-2">
                                        <?= e($milestone['title_ar']) ?>
                                        <span class="np-badge <?= $milestone['status'] === 'done'
                                            ? 'np-badge--success' : 'np-badge--draft' ?>">
                                            <?= e($milestoneLabel((string) $milestone['status'])) ?></span>
                                    </div>
                                    <p class="step-item__desc mb-0">
                                        <?php if (!empty($milestone['description_ar'])): ?>
                                            <?= e($milestone['description_ar']) ?><br>
                                        <?php endif; ?>
                                        <?php if (!empty($milestone['due_date'])): ?>
                                            الموعد: <?= e(format_date($milestone['due_date'])) ?>
                                        <?php endif; ?>
                                    </p>

                                    <?php if ($isProvider): ?>
                                        <form method="post" class="d-flex gap-2 mt-2"
                                              action="<?= e(url('/app/services/requests/'
                                                  . $serviceRequest['id'] . '/milestones/update')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="milestone_id"
                                                   value="<?= e((string) $milestone['id']) ?>">
                                            <label class="visually-hidden"
                                                   for="ms_<?= e((string) $milestone['id']) ?>">حالة المرحلة</label>
                                            <select class="form-select form-select-sm" style="width:auto"
                                                    id="ms_<?= e((string) $milestone['id']) ?>" name="status">
                                                <?php foreach (['pending', 'in_progress', 'done', 'skipped'] as $option): ?>
                                                    <option value="<?= e($option) ?>"
                                                        <?= $milestone['status'] === $option ? ' selected' : '' ?>>
                                                        <?= e($milestoneLabel($option)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-primary">حفظ</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($isProvider && in_array($status, ['accepted', 'in_progress'], true)): ?>
                        <form method="post" class="row g-2 mt-3" data-guard
                              action="<?= e(url('/app/services/requests/' . $serviceRequest['id'] . '/milestones')) ?>">
                            <?= csrf_field() ?>
                            <div class="col-md-5">
                                <label class="visually-hidden" for="title_ar">عنوان المرحلة</label>
                                <input type="text" class="form-control form-control-sm" id="title_ar"
                                       name="title_ar" maxlength="200" required placeholder="عنوان المرحلة">
                            </div>
                            <div class="col-md-4">
                                <label class="visually-hidden" for="description_ar">وصف</label>
                                <input type="text" class="form-control form-control-sm" id="description_ar"
                                       name="description_ar" maxlength="1000" placeholder="وصف مختصر (اختياري)">
                            </div>
                            <div class="col-md-2 col-8">
                                <label class="visually-hidden" for="due_date">الموعد</label>
                                <input type="date" class="form-control form-control-sm" dir="ltr"
                                       id="due_date" name="due_date">
                            </div>
                            <div class="col-md-1 col-4 d-grid">
                                <button type="submit" class="btn btn-sm btn-outline-primary">+</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php // ─── السجل ─── ?>
        <div class="np-card">
            <div class="np-card__header">سجل الطلب</div>
            <div class="np-card__body">
                <?php foreach ($history as $entry): ?>
                    <div class="step-item">
                        <span class="step-item__marker" aria-hidden="true">•</span>
                        <div>
                            <div class="step-item__title fs-sm">
                                <?= e($service->statusLabel((string) $entry['to_status'])) ?></div>
                            <p class="step-item__desc mb-0">
                                <?= e(match ((string) $entry['actor_type']) {
                                    'applicant' => 'المشروع',
                                    'provider'  => 'مقدّم الخدمة',
                                    'platform'  => 'فريق المنصة',
                                    default     => 'النظام',
                                }) ?>
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
        <div class="np-card">
            <div class="np-card__header">الإجراءات المتاحة لك</div>
            <div class="np-card__body">
                <?php if ($actions === []): ?>
                    <p class="fs-sm text-muted-np mb-0">لا توجد إجراءات متاحة على هذه الحالة.</p>
                <?php else: ?>
                    <?php foreach ($actions as $action): ?>
                        <form method="post" class="mb-2" data-guard action="<?= e(url($actionPath)) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= e($action) ?>">

                            <?php if ($action === 'propose'): ?>
                                <details>
                                    <summary class="btn btn-sm btn-primary w-100" style="cursor:pointer">
                                        <?= e($service->actionLabel($action)) ?></summary>

                                    <label class="form-label fs-sm mt-2" for="proposed_price">
                                        قيمة العرض <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control form-control-sm" dir="ltr"
                                           id="proposed_price" name="proposed_price" min="0" step="0.01" required>
                                    <p class="form-text">اكتب صفراً إن كانت الخدمة مجانية.</p>

                                    <label class="form-label fs-sm" for="proposed_duration_days">مدة التنفيذ (أيام)</label>
                                    <input type="number" class="form-control form-control-sm" dir="ltr"
                                           id="proposed_duration_days" name="proposed_duration_days" min="1" max="3650">

                                    <label class="form-label fs-sm mt-2" for="proposal_note_ar">
                                        نطاق العمل <span class="text-danger">*</span></label>
                                    <textarea class="form-control form-control-sm" id="proposal_note_ar"
                                              name="proposal_note_ar" rows="4" required maxlength="2000"></textarea>

                                    <button type="submit" class="btn btn-sm btn-primary w-100 mt-2">
                                        إرسال العرض</button>
                                </details>
                            <?php elseif ($service->requiresReason($action)): ?>
                                <details>
                                    <summary class="btn btn-sm btn-outline-danger w-100" style="cursor:pointer">
                                        <?= e($service->actionLabel($action)) ?></summary>
                                    <label class="form-label fs-sm mt-2" for="note_<?= e($action) ?>">
                                        السبب <span class="text-danger">*</span></label>
                                    <textarea class="form-control form-control-sm" id="note_<?= e($action) ?>"
                                              name="note" rows="2" required maxlength="1000"></textarea>
                                    <button type="submit" class="btn btn-sm btn-danger w-100 mt-2">تأكيد</button>
                                </details>
                            <?php else: ?>
                                <button type="submit" class="btn btn-sm btn-primary w-100">
                                    <?= e($service->actionLabel($action)) ?></button>
                            <?php endif; ?>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>

                <p class="fs-xs text-muted-np mb-0 mt-3">
                    <?= $isProvider
                        ? 'قبول العرض قرار المشروع وحده، وتأكيد الاستلام كذلك.'
                        : 'تقديم العرض وتسجيل التسليم من اختصاص مقدّم الخدمة.' ?>
                </p>
            </div>
        </div>
    </div>
</div>
