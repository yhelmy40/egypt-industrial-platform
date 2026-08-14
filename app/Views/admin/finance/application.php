<?php
/**
 * فرز طلب تمويل | Screening one financing application (§4.5).
 *
 * @var array<string,mixed> $application
 * @var array<int,array<string,mixed>> $history
 * @var array<int,array<string,mixed>> $documents
 * @var array<int,string> $actions
 * @var \App\Services\FinancingApplicationService $service
 */
$status = (string) $application['status'];
?>
<a class="fs-sm text-muted-np" href="<?= e(url('/admin/finance/applications')) ?>">→ طابور الفرز</a>

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
            <div class="np-card__header">الأطراف</div>
            <div class="np-card__body">
                <table class="np-table">
                    <tbody>
                        <tr>
                            <th>المشروع</th>
                            <td>
                                <a target="_blank" rel="noopener"
                                   href="<?= e(url('/admin/verifications/' . $application['organization_id'])) ?>">
                                    <?= e($application['applicant_trading_name']
                                        ?: $application['applicant_legal_name']) ?></a>
                                <span class="np-badge <?= $application['applicant_status'] === 'verified'
                                    ? 'np-badge--success' : 'np-badge--danger' ?>">
                                    <?= e($application['applicant_status'] === 'verified'
                                        ? 'موثّقة' : 'غير موثّقة') ?></span>
                            </td>
                        </tr>
                        <tr><th>المؤسسة المالية</th>
                            <td><?= e($application['provider_trading_name']
                                ?: $application['provider_legal_name']) ?></td></tr>
                        <tr><th>المحافظة والقطاع</th>
                            <td class="fs-sm">
                                <?= e($application['governorate_name'] ?? '—') ?>
                                · <?= e($application['sector_name'] ?? '—') ?></td></tr>
                        <tr><th>اكتمال ملف المشروع</th>
                            <td class="numeric">
                                <?= e(number_ar((int) $application['completion_score'])) ?>٪</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="np-card mb-3">
            <div class="np-card__header">الطلب</div>
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
                </dl>

                <h2 class="h6">الغرض</h2>
                <p class="fs-sm mb-0" style="white-space:pre-line"><?= e($application['purpose_ar']) ?></p>
            </div>
        </div>

        <?php if ($documents !== []): ?>
            <div class="np-card mb-3">
                <div class="np-card__header">المستندات</div>
                <div class="np-card__body p-0">
                    <table class="np-table">
                        <tbody>
                            <?php foreach ($documents as $document): ?>
                                <tr>
                                    <td><?= e($document['label_ar']) ?></td>
                                    <td class="text-end fs-xs text-muted-np">
                                        <?= $document['media_id'] !== null ? 'مرفوع' : 'لم يُرفع' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="np-card">
            <div class="np-card__header">
                سجل الطلب
                <span class="np-badge np-badge--warning">يشمل ملاحظات داخلية</span>
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
                                · <?= e(match ((string) $entry['actor_type']) {
                                    'applicant' => 'المشروع',
                                    'provider'  => 'المؤسسة',
                                    'platform'  => 'المنصة',
                                    default     => 'النظام',
                                }) ?>
                                · <?= e(format_date($entry['created_at'], true)) ?>
                                <?php if (!empty($entry['note_ar'])): ?>
                                    <br><?= e($entry['note_ar']) ?>
                                <?php endif; ?>
                                <?php if (!empty($entry['internal_note_ar'])): ?>
                                    <br><em>داخلي:</em> <?= e($entry['internal_note_ar']) ?>
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
            <div class="np-card__header">الفرز والإحالة</div>
            <div class="np-card__body">
                <?php if ($actions === []): ?>
                    <p class="fs-sm text-muted-np mb-0">
                        لا توجد إجراءات للمنصة على هذه الحالة. الطلب الآن لدى الطرف المعني.
                    </p>
                <?php else: ?>
                    <?php foreach ($actions as $action): ?>
                        <form method="post" class="mb-2" data-guard
                              action="<?= e(url('/admin/finance/applications/' . $application['id'] . '/action')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= e($action) ?>">
                            <details>
                                <summary class="btn btn-sm <?= $service->requiresReason($action)
                                    ? 'btn-outline-danger' : 'btn-primary' ?> w-100" style="cursor:pointer">
                                    <?= e($service->actionLabel($action)) ?></summary>

                                <label class="form-label fs-sm mt-2" for="note_<?= e($action) ?>">
                                    ملاحظة تصل للمشروع
                                    <?= $service->requiresReason($action) ? '<span class="text-danger">*</span>' : '' ?>
                                </label>
                                <textarea class="form-control form-control-sm" id="note_<?= e($action) ?>"
                                          name="note" rows="2" maxlength="1000"
                                    <?= $service->requiresReason($action) ? 'required' : '' ?>></textarea>

                                <label class="form-label fs-sm mt-2" for="internal_<?= e($action) ?>">
                                    ملاحظة داخلية (لا يراها المشروع)</label>
                                <textarea class="form-control form-control-sm" id="internal_<?= e($action) ?>"
                                          name="internal_note" rows="2" maxlength="1000"></textarea>

                                <button type="submit" class="btn btn-sm btn-primary w-100 mt-2">تنفيذ</button>
                            </details>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="alert alert-warning fs-sm mb-0">
            <strong>لا اعتماد ولا رفض من هنا.</strong>
            قرار التمويل من اختصاص المؤسسة المالية وحدها. أي محاولة لتسجيله من حساب المنصة
            تُرفض وتُسجَّل في سجل التدقيق.
        </div>
    </div>
</div>
