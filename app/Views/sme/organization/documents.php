<?php
/**
 * مستندات المنشأة | Organization documents (§4.2).
 *
 * قائمة تحقّق تعرض كل مستند مطلوب وحالته، فيعرف صاحب المنشأة بالضبط ما ينقصه
 * ولماذا رُفض مستند إن رُفض.
 *
 * @var array<string,mixed> $organization
 * @var array<int,array<string,mixed>> $checklist
 * @var bool $canUpload
 * @var string $maxSize
 * @var string $allowedTypes
 */

$statusBadges = [
    'pending'  => ['np-badge--pending', 'بانتظار المراجعة'],
    'accepted' => ['np-badge--success', 'مقبول'],
    'rejected' => ['np-badge--danger',  'مرفوض'],
];
?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="np-card">
            <div class="np-card__header">
                قائمة المستندات
                <span class="np-badge np-badge--muted">
                    <?= e($organization['type_name']) ?>
                </span>
            </div>
            <div class="np-card__body p-0">
                <?php if ($checklist === []): ?>
                    <div class="np-empty"><p class="mb-0">لا توجد مستندات مطلوبة لهذا النوع من المنشآت.</p></div>
                <?php else: ?>
                    <?php foreach ($checklist as $row): ?>
                        <?php
                        $uploaded = $row['document_id'] !== null;
                        $status   = (string) ($row['status'] ?? '');
                        [$badgeClass, $badgeLabel] = $statusBadges[$status] ?? ['np-badge--draft', 'لم يُرفع'];
                        ?>
                        <div class="p-3 border-bottom border-np">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div class="flex-grow-1">
                                    <div class="fw-bold">
                                        <?= e($row['name_ar']) ?>
                                        <?php if ((int) $row['is_required'] === 1): ?>
                                            <span class="required" title="مستند إلزامي">*</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-muted-np fs-sm mb-1"><?= e($row['description_ar']) ?></p>

                                    <?php if ($uploaded): ?>
                                        <p class="fs-xs text-muted-np mb-0">
                                            <?= e($row['original_name'] ?? '') ?>
                                            · رُفع <?= e(time_ago($row['uploaded_at'])) ?>
                                            <?php if (!empty($row['expiry_date'])): ?>
                                                · ينتهي في <?= e(format_date($row['expiry_date'])) ?>
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if ($status === 'rejected' && !empty($row['review_note'])): ?>
                                        <div class="alert alert-danger fs-sm mt-2 mb-0" role="alert">
                                            <strong>سبب الرفض:</strong> <?= e($row['review_note']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="text-start d-flex flex-column gap-2 align-items-end">
                                    <span class="np-badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span>

                                    <?php if ($uploaded): ?>
                                        <div class="d-flex gap-1">
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="<?= e(url('/app/organization/documents')) ?>#doc-<?= e((string) $row['document_id']) ?>"
                                               hidden>عرض</a>

                                            <?php if ($canUpload && $status !== 'accepted'): ?>
                                                <form method="post"
                                                      action="<?= e(url('/app/organization/documents/' . $row['document_id'] . '/delete')) ?>">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn-sm btn-link text-danger p-0"
                                                            data-confirm="سيتم حذف هذا المستند نهائياً. هل تريد المتابعة؟">
                                                        <?= __e('common.delete') ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="np-card">
            <div class="np-card__header">رفع مستند</div>

            <?php if (!$canUpload): ?>
                <div class="np-card__body">
                    <p class="text-muted-np mb-0">
                        لا يمكن رفع مستندات في الحالة الحالية للمنشأة.
                    </p>
                </div>
            <?php else: ?>
                <form method="post" action="<?= e(url('/app/organization/documents')) ?>"
                      enctype="multipart/form-data" novalidate data-guard>
                    <div class="np-card__body">
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label class="form-label" for="document_type_id">
                                نوع المستند<span class="required" aria-hidden="true">*</span>
                            </label>
                            <select class="form-select<?= has_error('document_type_id') ? ' is-invalid' : '' ?>"
                                    id="document_type_id" name="document_type_id" required>
                                <option value=""><?= __e('common.select') ?></option>
                                <?php foreach ($checklist as $row): ?>
                                    <option value="<?= e((string) $row['document_type_id']) ?>">
                                        <?= e($row['name_ar']) ?><?= (int) $row['is_required'] === 1 ? ' (إلزامي)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (has_error('document_type_id')): ?>
                                <div class="invalid-feedback"><?= e(error_for('document_type_id')) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="document">
                                الملف<span class="required" aria-hidden="true">*</span>
                            </label>
                            <input type="file" class="form-control<?= has_error('document') || has_error('file') ? ' is-invalid' : '' ?>"
                                   id="document" name="document" accept=".pdf,.jpg,.jpeg,.png" required>
                            <?php if (has_error('document')): ?>
                                <div class="invalid-feedback"><?= e(error_for('document')) ?></div>
                            <?php elseif (has_error('file')): ?>
                                <div class="invalid-feedback"><?= e(error_for('file')) ?></div>
                            <?php endif; ?>
                            <div class="form-text">
                                الصيغ المقبولة: <?= e($allowedTypes) ?> — بحد أقصى <?= e($maxSize) ?>.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="document_number">رقم المستند</label>
                            <input type="text" class="form-control" id="document_number"
                                   name="document_number" maxlength="100" dir="ltr">
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label" for="issue_date">تاريخ الإصدار</label>
                                <input type="date" class="form-control" id="issue_date" name="issue_date" dir="ltr">
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="expiry_date">تاريخ الانتهاء</label>
                                <input type="date" class="form-control" id="expiry_date" name="expiry_date" dir="ltr">
                            </div>
                        </div>
                    </div>

                    <div class="np-card__footer text-start">
                        <button type="submit" class="btn btn-primary" data-busy-label="جارٍ الرفع…">
                            <?= __e('common.upload') ?>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="alert alert-info fs-sm mt-3" role="alert">
            <strong>خصوصية مستنداتك:</strong> لا تظهر المستندات على صفحتك العامة ولا لأي منشأة
            أخرى. يطّلع عليها فريق مراجعة المنصة فقط لغرض التوثيق، ويُسجَّل كل اطلاع في سجل التدقيق.
        </div>
    </div>
</div>
