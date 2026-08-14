<?php
/**
 * تفاصيل طلب التمويل | Financing application detail, applicant view (§4.5).
 *
 * ما لا يظهر هنا: `internal_note_ar` — الخدمة تحذفها من نسخة المشروع قبل وصولها
 * للقالب، فلا يعتمد الحجب على تذكّر المطوّر ألّا يطبعها.
 *
 * @var array<string,mixed> $application
 * @var array<int,array<string,mixed>> $history
 * @var array<int,array<string,mixed>> $documents
 * @var array<int,string> $actions
 * @var \App\Services\FinancingApplicationService $service
 */
$status = (string) $application['status'];
?>
<a class="fs-sm text-muted-np" href="<?= e(url('/app/finance/applications')) ?>">→ كل الطلبات</a>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-1 mb-3">
    <h1 class="h5 mb-0">
        الطلب <span class="numeric" dir="ltr"><?= e($application['application_number']) ?></span>
        <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
            <?= e($service->statusLabel($status)) ?></span>
    </h1>
    <span class="fs-sm text-muted-np"><?= e(format_date($application['created_at'], true)) ?></span>
</div>

<?php if ($status === 'approved'): ?>
    <div class="alert alert-success">
        <strong>سجّلت المؤسسة المالية موافقتها على هذا الطلب.</strong>
        استكمال الإجراءات والتعاقد وصرف التمويل تتم مع المؤسسة مباشرةً. المنصة ليست طرفاً
        في التعاقد ولا تضمن أي التزام.
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="np-card mb-3">
            <div class="np-card__header">بيانات الطلب</div>
            <div class="np-card__body">
                <dl class="row fs-sm mb-0">
                    <dt class="col-4 fw-normal text-muted-np">المنتج</dt>
                    <dd class="col-8"><?= e($application['product_name_ar']) ?></dd>

                    <dt class="col-4 fw-normal text-muted-np">المؤسسة المالية</dt>
                    <dd class="col-8">
                        <?= e($application['provider_trading_name'] ?: $application['provider_legal_name']) ?></dd>

                    <dt class="col-4 fw-normal text-muted-np">المبلغ المطلوب</dt>
                    <dd class="col-8 numeric">
                        <?= e(money((float) $application['requested_amount'],
                            (string) $application['currency_code'])) ?></dd>

                    <?php if ($application['requested_tenor_months'] !== null): ?>
                        <dt class="col-4 fw-normal text-muted-np">مدة السداد المطلوبة</dt>
                        <dd class="col-8"><?= e(number_ar((int) $application['requested_tenor_months'])) ?> شهراً</dd>
                    <?php endif; ?>

                    <?php if ($application['approved_amount'] !== null): ?>
                        <dt class="col-4 fw-normal text-muted-np">المبلغ المعتمد</dt>
                        <dd class="col-8 numeric fw-bold">
                            <?= e(money((float) $application['approved_amount'])) ?></dd>
                    <?php endif; ?>
                </dl>

                <h2 class="h6 mt-3">الغرض من التمويل</h2>
                <p class="fs-sm mb-0" style="white-space:pre-line"><?= e($application['purpose_ar']) ?></p>

                <?php if (!empty($application['decision_note_ar'])): ?>
                    <div class="alert <?= $status === 'approved' ? 'alert-success' : 'alert-warning' ?> mt-3 mb-0">
                        <strong>ردّ المؤسسة:</strong>
                        <p class="mb-0" style="white-space:pre-line">
                            <?= e($application['decision_note_ar']) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php // ─── المستندات المطلوبة ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">المستندات المطلوبة منك</div>
            <div class="np-card__body p-0">
                <?php if ($documents === []): ?>
                    <div class="np-empty py-4">
                        <p class="fs-sm mb-0">لم تطلب المؤسسة مستندات إضافية حتى الآن.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($documents as $document): ?>
                        <div class="p-3 border-bottom border-np">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <strong class="fs-sm"><?= e($document['label_ar']) ?></strong>
                                    <?php if ((int) $document['is_required'] === 1): ?>
                                        <span class="np-badge np-badge--pending">إلزامي</span>
                                    <?php endif; ?>
                                    <?php if (!empty($document['note_ar'])): ?>
                                        <div class="fs-xs text-muted-np"><?= e($document['note_ar']) ?></div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($document['media_id'] !== null): ?>
                                    <span class="np-badge np-badge--success">
                                        مرفوع · <?= e(format_date($document['uploaded_at'])) ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($document['media_id'] === null): ?>
                                <form method="post" enctype="multipart/form-data" class="d-flex gap-2 mt-2"
                                      action="<?= e(url('/app/finance/applications/' . $application['id'] . '/documents')) ?>"
                                      data-guard>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="document_id" value="<?= e((string) $document['id']) ?>">
                                    <label class="visually-hidden" for="doc_<?= e((string) $document['id']) ?>">
                                        ملف <?= e($document['label_ar']) ?></label>
                                    <input type="file" class="form-control form-control-sm"
                                           id="doc_<?= e((string) $document['id']) ?>" name="document" required>
                                    <button type="submit" class="btn btn-sm btn-primary">رفع</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

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
                                    'platform'  => 'فريق المنصة',
                                    'provider'  => 'المؤسسة المالية',
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
        <div class="np-card mb-3">
            <div class="np-card__header">الإجراءات المتاحة لك</div>
            <div class="np-card__body">
                <?php if ($actions === []): ?>
                    <p class="fs-sm text-muted-np mb-0">لا توجد إجراءات متاحة على هذه الحالة.</p>
                <?php else: ?>
                    <?php foreach ($actions as $action): ?>
                        <form method="post" class="mb-2" data-guard
                              action="<?= e(url('/app/finance/applications/' . $application['id'] . '/action')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= e($action) ?>">
                            <?php if ($service->requiresReason($action)): ?>
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
            </div>
        </div>

        <?= $view->partial('partials/finance-disclaimer') ?>
    </div>
</div>
