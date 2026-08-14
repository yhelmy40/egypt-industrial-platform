<?php
/**
 * دراسة طلب تمويل | Financing application review, provider view (§4.5).
 *
 * هنا وحدها يُسجَّل قرار القبول أو الرفض، ودائماً باسم المستخدم الذي سجّله.
 *
 * @var array<string,mixed> $application
 * @var array<int,array<string,mixed>> $history
 * @var array<int,array<string,mixed>> $documents
 * @var array<int,string> $actions
 * @var \App\Services\FinancingApplicationService $service
 */
$status = (string) $application['status'];
?>
<a class="fs-sm text-muted-np" href="<?= e(url('/app/finance/requests')) ?>">→ الطلبات الواردة</a>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-1 mb-3">
    <h1 class="h5 mb-0">
        الطلب <span class="numeric" dir="ltr"><?= e($application['application_number']) ?></span>
        <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
            <?= e($service->statusLabel($status)) ?></span>
    </h1>
    <span class="fs-sm text-muted-np"><?= e(format_date($application['created_at'], true)) ?></span>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="np-card mb-3">
            <div class="np-card__header">المشروع مقدّم الطلب</div>
            <div class="np-card__body">
                <dl class="row fs-sm mb-0">
                    <dt class="col-4 fw-normal text-muted-np">المنشأة</dt>
                    <dd class="col-8">
                        <a target="_blank" rel="noopener"
                           href="<?= e(url('/business/' . $application['applicant_slug'])) ?>">
                            <?= e($application['applicant_trading_name'] ?: $application['applicant_legal_name']) ?> ↗</a>
                        <span class="np-verified"><span aria-hidden="true">✓</span> موثّقة</span>
                    </dd>

                    <?php if (!empty($application['applicant_governorate'])): ?>
                        <dt class="col-4 fw-normal text-muted-np">المحافظة</dt>
                        <dd class="col-8"><?= e($application['applicant_governorate']) ?></dd>
                    <?php endif; ?>

                    <?php if (!empty($application['applicant_sector'])): ?>
                        <dt class="col-4 fw-normal text-muted-np">القطاع</dt>
                        <dd class="col-8"><?= e($application['applicant_sector']) ?></dd>
                    <?php endif; ?>

                    <dt class="col-4 fw-normal text-muted-np">اكتمال الملف</dt>
                    <dd class="col-8 numeric"><?= e(number_ar((int) $application['completion_score'])) ?>٪</dd>
                </dl>
            </div>
        </div>

        <div class="np-card mb-3">
            <div class="np-card__header">تفاصيل الطلب</div>
            <div class="np-card__body">
                <dl class="row fs-sm">
                    <dt class="col-4 fw-normal text-muted-np">المنتج</dt>
                    <dd class="col-8"><?= e($application['product_name_ar']) ?></dd>

                    <dt class="col-4 fw-normal text-muted-np">المبلغ المطلوب</dt>
                    <dd class="col-8 numeric fw-bold">
                        <?= e(money((float) $application['requested_amount'])) ?></dd>

                    <?php if ($application['requested_tenor_months'] !== null): ?>
                        <dt class="col-4 fw-normal text-muted-np">المدة المطلوبة</dt>
                        <dd class="col-8"><?= e(number_ar((int) $application['requested_tenor_months'])) ?> شهراً</dd>
                    <?php endif; ?>

                    <?php if ($application['declared_annual_revenue'] !== null): ?>
                        <dt class="col-4 fw-normal text-muted-np">إيراد سنوي معلن</dt>
                        <dd class="col-8 numeric">
                            <?= e(money((float) $application['declared_annual_revenue'])) ?></dd>
                    <?php endif; ?>

                    <?php if ($application['declared_employees'] !== null): ?>
                        <dt class="col-4 fw-normal text-muted-np">عدد العاملين</dt>
                        <dd class="col-8 numeric">
                            <?= e(number_ar((int) $application['declared_employees'])) ?></dd>
                    <?php endif; ?>

                    <?php if ($application['years_in_business'] !== null): ?>
                        <dt class="col-4 fw-normal text-muted-np">سنوات النشاط</dt>
                        <dd class="col-8 numeric">
                            <?= e(number_ar((int) $application['years_in_business'])) ?></dd>
                    <?php endif; ?>
                </dl>

                <h2 class="h6">الغرض من التمويل</h2>
                <p class="fs-sm mb-0" style="white-space:pre-line"><?= e($application['purpose_ar']) ?></p>

                <p class="fs-xs text-muted-np mb-0 mt-3">
                    البيانات أعلاه معلنة من مقدّم الطلب ولم تتحقّق منها المنصة مستندياً.
                </p>
            </div>
        </div>

        <?php // ─── المستندات ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">المستندات</div>
            <div class="np-card__body p-0">
                <?php if ($documents === []): ?>
                    <div class="np-empty py-4"><p class="fs-sm mb-0">لم تطلبوا مستندات بعد.</p></div>
                <?php else: ?>
                    <table class="np-table">
                        <tbody>
                            <?php foreach ($documents as $document): ?>
                                <tr>
                                    <td>
                                        <?= e($document['label_ar']) ?>
                                        <?php if ((int) $document['is_required'] === 1): ?>
                                            <span class="np-badge np-badge--pending">إلزامي</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($document['media_id'] !== null): ?>
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="<?= e(url('/files/' . $document['media_id'])) ?>">تنزيل</a>
                                        <?php else: ?>
                                            <span class="fs-xs text-muted-np">لم يُرفع بعد</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <div class="p-3 border-top border-np">
                    <form method="post" class="row g-2" data-guard
                          action="<?= e(url('/app/finance/requests/' . $application['id'] . '/documents')) ?>">
                        <?= csrf_field() ?>
                        <div class="col-md-5">
                            <label class="visually-hidden" for="label_ar">اسم المستند</label>
                            <input type="text" class="form-control form-control-sm" id="label_ar"
                                   name="label_ar" maxlength="200" required placeholder="اسم المستند المطلوب">
                        </div>
                        <div class="col-md-4">
                            <label class="visually-hidden" for="note_ar">ملاحظة</label>
                            <input type="text" class="form-control form-control-sm" id="note_ar"
                                   name="note_ar" maxlength="500" placeholder="ملاحظة للمشروع (اختياري)">
                        </div>
                        <div class="col-md-2 d-flex align-items-center">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1"
                                       id="is_required" name="is_required" checked>
                                <label class="form-check-label fs-sm" for="is_required">إلزامي</label>
                            </div>
                        </div>
                        <div class="col-md-1 d-grid">
                            <button type="submit" class="btn btn-sm btn-outline-primary">+</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php // ─── السجل ─── ?>
        <div class="np-card">
            <div class="np-card__header">
                سجل الطلب
                <span class="np-badge np-badge--warning">يشمل ملاحظات داخلية لا يراها المشروع</span>
            </div>
            <div class="np-card__body">
                <?php foreach ($history as $entry): ?>
                    <div class="step-item">
                        <span class="step-item__marker" aria-hidden="true">•</span>
                        <div>
                            <div class="step-item__title fs-sm">
                                <?= e($service->statusLabel((string) $entry['to_status'])) ?></div>
                            <p class="step-item__desc mb-0">
                                <?= e($entry['actor_name'] ?? 'النظام') ?>
                                · <?= e(format_date($entry['created_at'], true)) ?>
                                <?php if (!empty($entry['note_ar'])): ?>
                                    <br><?= e($entry['note_ar']) ?>
                                <?php endif; ?>
                                <?php if (!empty($entry['internal_note_ar'])): ?>
                                    <br><em>ملاحظة داخلية:</em> <?= e($entry['internal_note_ar']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="np-card mb-3">
            <div class="np-card__header">قرار المؤسسة</div>
            <div class="np-card__body">
                <?php if ($actions === []): ?>
                    <p class="fs-sm text-muted-np mb-0">لا توجد إجراءات متاحة على هذه الحالة.</p>
                <?php else: ?>
                    <?php foreach ($actions as $action): ?>
                        <form method="post" class="mb-2" data-guard
                              action="<?= e(url('/app/finance/requests/' . $application['id'] . '/action')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= e($action) ?>">

                            <?php if ($action === 'approve'): ?>
                                <details>
                                    <summary class="btn btn-sm btn-primary w-100" style="cursor:pointer">
                                        <?= e($service->actionLabel($action)) ?></summary>

                                    <label class="form-label fs-sm mt-2" for="approved_amount">المبلغ المعتمد</label>
                                    <input type="number" class="form-control form-control-sm" dir="ltr"
                                           id="approved_amount" name="approved_amount" min="0" step="100"
                                           value="<?= e((string) $application['requested_amount']) ?>">

                                    <label class="form-label fs-sm mt-2" for="approved_tenor_months">
                                        المدة المعتمدة (شهور)</label>
                                    <input type="number" class="form-control form-control-sm" dir="ltr"
                                           id="approved_tenor_months" name="approved_tenor_months" min="1" max="360"
                                           value="<?= e((string) ($application['requested_tenor_months'] ?? '')) ?>">

                                    <label class="form-label fs-sm mt-2" for="approve_note">رسالة للمشروع</label>
                                    <textarea class="form-control form-control-sm" id="approve_note"
                                              name="note" rows="3" maxlength="2000"></textarea>

                                    <button type="submit" class="btn btn-sm btn-primary w-100 mt-2">
                                        تسجيل الموافقة</button>
                                </details>
                            <?php elseif ($service->requiresReason($action)): ?>
                                <details>
                                    <summary class="btn btn-sm btn-outline-danger w-100" style="cursor:pointer">
                                        <?= e($service->actionLabel($action)) ?></summary>
                                    <label class="form-label fs-sm mt-2" for="note_<?= e($action) ?>">
                                        السبب (يصل للمشروع) <span class="text-danger">*</span></label>
                                    <textarea class="form-control form-control-sm" id="note_<?= e($action) ?>"
                                              name="note" rows="3" required maxlength="2000"></textarea>
                                    <label class="form-label fs-sm mt-2" for="internal_<?= e($action) ?>">
                                        ملاحظة داخلية (لا يراها المشروع)</label>
                                    <textarea class="form-control form-control-sm" id="internal_<?= e($action) ?>"
                                              name="internal_note" rows="2" maxlength="1000"></textarea>
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
                    القرار يُسجَّل باسمك وتاريخه في سجل لا يُعدَّل. المنصة لا تسجّل موافقة ولا
                    رفضاً نيابةً عن المؤسسة.
                </p>
            </div>
        </div>

        <?php if ($application['decided_at'] !== null): ?>
            <div class="np-card">
                <div class="np-card__header">القرار المسجَّل</div>
                <div class="np-card__body">
                    <dl class="row fs-sm mb-0">
                        <dt class="col-5 fw-normal text-muted-np">القرار</dt>
                        <dd class="col-7"><?= e($service->statusLabel($status)) ?></dd>
                        <dt class="col-5 fw-normal text-muted-np">التاريخ</dt>
                        <dd class="col-7"><?= e(format_date($application['decided_at'], true)) ?></dd>
                        <?php if ($application['approved_amount'] !== null): ?>
                            <dt class="col-5 fw-normal text-muted-np">المبلغ المعتمد</dt>
                            <dd class="col-7 numeric">
                                <?= e(money((float) $application['approved_amount'])) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
