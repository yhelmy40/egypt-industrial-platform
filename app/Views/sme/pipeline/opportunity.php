<?php
/**
 * ملفّ الفرصة | The opportunity file (§4.9).
 *
 * المراحل المعروضة هي **المتاحة من المرحلة الحالية وحدها**: القفز محكوم، فلا
 * يُعرض زرّ لإجراء سترفضه الخدمة.
 *
 * @var array<string,mixed> $opportunity
 * @var array<int,array<string,mixed>> $activities
 * @var array<int,string> $nextStages
 * @var \App\Services\PipelineService $service
 */
$opportunityId = (int) $opportunity['id'];
$stage         = (string) $opportunity['stage'];
$isClosed      = in_array($stage, ['won', 'lost'], true);
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><?= e($opportunity['title_ar']) ?></h1>
        <p class="fs-sm text-muted-np mb-0">
            <a href="<?= e(url('/app/customers/' . $opportunity['customer_id'])) ?>">
                <?= e($opportunity['customer_name']) ?></a>
            · <span class="np-badge <?= e($service->stageBadgeClass($stage)) ?>">
                <?= e($service->stageLabel($stage)) ?></span>
        </p>
    </div>
    <a class="btn btn-sm btn-outline-secondary"
       href="<?= e(url('/app/pipeline/opportunities')) ?>">رجوع لخطّ الفرص</a>
</div>

<?php if ($isClosed && !empty($opportunity['close_reason_ar'])): ?>
    <div class="alert alert-<?= $stage === 'won' ? 'success' : 'warning' ?>" role="alert">
        <strong><?= $stage === 'won' ? 'أُغلقت مكسوبة' : 'أُغلقت خاسرة' ?>:</strong>
        <?= e($opportunity['close_reason_ar']) ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="np-card mb-3">
            <div class="np-card__header"><h2 class="h6 mb-0">نقل المرحلة</h2></div>
            <div class="np-card__body">
                <?php if ($nextStages === []): ?>
                    <p class="fs-sm text-muted-np mb-0">لا مراحل تالية من هذا الوضع.</p>
                <?php else: ?>
                    <form method="post"
                          action="<?= e(url('/app/pipeline/opportunities/' . $opportunityId . '/stage')) ?>"
                          class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <div class="col-md-4">
                            <label class="form-label fs-sm" for="stage">المرحلة التالية</label>
                            <select class="form-select form-select-sm" id="stage" name="stage" required>
                                <?php foreach ($nextStages as $next): ?>
                                    <option value="<?= e($next) ?>"><?= e($service->stageLabel($next)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fs-sm" for="close_reason_ar">
                                السبب <span class="text-muted-np">(إلزامي عند الخسارة)</span>
                            </label>
                            <input type="text" class="form-control form-control-sm" id="close_reason_ar"
                                   name="close_reason_ar" maxlength="500">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-sm btn-primary w-100">نقل</button>
                        </div>
                    </form>
                    <p class="fs-xs text-muted-np mb-0 mt-2">
                        فرصة تُغلق خاسرة بلا سبب لا تُعلّمك شيئاً للمرة القادمة، ولذلك السبب إلزامي.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="np-card">
            <div class="np-card__header"><h2 class="h6 mb-0">سجلّ المتابعة</h2></div>
            <div class="np-card__body">
                <form method="post" action="<?= e(url('/app/pipeline/activities')) ?>" class="row g-2 mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="opportunity_id" value="<?= e((string) $opportunityId) ?>">
                    <div class="col-md-3">
                        <label class="visually-hidden" for="activity_type">النوع</label>
                        <select class="form-select form-select-sm" id="activity_type" name="activity_type">
                            <?php foreach (['call' => 'مكالمة', 'visit' => 'زيارة', 'meeting' => 'اجتماع',
                                            'note' => 'ملاحظة'] as $key => $label): ?>
                                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="visually-hidden" for="subject_ar">الموضوع</label>
                        <input type="text" class="form-control form-control-sm" id="subject_ar"
                               name="subject_ar" required maxlength="200" placeholder="ما جرى…">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">تسجيل</button>
                    </div>
                </form>

                <?php if ($activities === []): ?>
                    <p class="fs-sm text-muted-np mb-0">لا متابعات مسجَّلة على هذه الفرصة.</p>
                <?php else: ?>
                    <ol class="list-unstyled mb-0">
                        <?php foreach ($activities as $activity): ?>
                            <li class="step-item">
                                <span class="step-item__marker" aria-hidden="true">
                                    <?= (string) $activity['status'] === 'planned' ? '◌' : '✓' ?>
                                </span>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between gap-2">
                                        <strong class="fs-sm">
                                            <?= e($service->activityTypeLabel((string) $activity['activity_type'])) ?>:
                                            <?= e($activity['subject_ar']) ?>
                                        </strong>
                                        <span class="fs-xs text-muted-np">
                                            <?= e(format_date((string) ($activity['occurred_at']
                                                ?? $activity['due_at'] ?? $activity['created_at']), true)) ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($activity['body_ar'])): ?>
                                        <p class="fs-sm mb-0 mt-1"><?= nl2br(e($activity['body_ar'])) ?></p>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="np-card">
            <div class="np-card__header"><h2 class="h6 mb-0">بيانات الفرصة</h2></div>
            <?php if ($isClosed): ?>
                <div class="np-card__body">
                    <p class="fs-sm text-muted-np mb-2">
                        الفرصة مغلقة. أعد فتحها من «نقل المرحلة» قبل تعديل بياناتها.
                    </p>
                    <dl class="row mb-0 fs-sm">
                        <dt class="col-6">القيمة المتوقّعة</dt>
                        <dd class="col-6 numeric">
                            <?= $opportunity['expected_value'] !== null
                                ? e(money((float) $opportunity['expected_value'])) : '—' ?>
                        </dd>
                        <dt class="col-6">الاحتمال</dt>
                        <dd class="col-6 numeric">
                            <?= $opportunity['probability'] !== null
                                ? e(number_ar((int) $opportunity['probability'])) . '٪' : '—' ?>
                        </dd>
                    </dl>
                </div>
            <?php else: ?>
                <form method="post" action="<?= e(url('/app/pipeline/opportunities/' . $opportunityId)) ?>">
                    <?= csrf_field() ?>
                    <div class="np-card__body row g-3">
                        <div class="col-12">
                            <label class="form-label fs-sm" for="title_ar">العنوان</label>
                            <input type="text" class="form-control form-control-sm" id="title_ar"
                                   name="title_ar" required maxlength="200"
                                   value="<?= e($opportunity['title_ar']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-sm" for="expected_value">القيمة المتوقّعة (ج.م)</label>
                            <input type="number" step="0.01" min="0" dir="ltr"
                                   class="form-control form-control-sm numeric" id="expected_value"
                                   name="expected_value"
                                   value="<?= e((string) ($opportunity['expected_value'] ?? '')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-sm" for="probability">الاحتمال (٪)</label>
                            <input type="number" min="0" max="100" dir="ltr"
                                   class="form-control form-control-sm numeric" id="probability"
                                   name="probability"
                                   value="<?= e((string) ($opportunity['probability'] ?? '')) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fs-sm" for="expected_close_date">الإغلاق المتوقّع</label>
                            <input type="date" class="form-control form-control-sm" dir="ltr"
                                   id="expected_close_date" name="expected_close_date"
                                   value="<?= e((string) ($opportunity['expected_close_date'] ?? '')) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fs-sm" for="description_ar">التفاصيل</label>
                            <textarea class="form-control form-control-sm" id="description_ar"
                                      name="description_ar" rows="4"
                                      maxlength="2000"><?= e((string) ($opportunity['description_ar'] ?? '')) ?></textarea>
                        </div>
                    </div>
                    <div class="np-card__footer">
                        <button type="submit" class="btn btn-sm btn-primary">حفظ</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
