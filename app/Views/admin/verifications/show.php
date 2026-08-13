<?php
/**
 * شاشة مراجعة منشأة | Single-organization review screen (§4.2).
 *
 * تعرض كل ما يحتاجه المراجع في مكان واحد: البيانات، المستندات القابلة للتنزيل،
 * الأعضاء، وسجل القرارات — ثم أزرار القرار مع إلزام كتابة السبب عند الرفض.
 *
 * @var array<string,mixed> $organization
 * @var array<string,mixed> $profile
 * @var array<int,array<string,mixed>> $checklist
 * @var array<int,array<string,mixed>> $documents
 * @var array{score:int,completed:array,missing:array} $completion
 * @var array<int,array<string,mixed>> $history
 * @var array<int,string> $availableActions
 * @var array<int,array<string,mixed>> $members
 * @var App\Services\VerificationService $verificationService
 */

$status = (string) $organization['status'];

$statusBadges = [
    'draft'              => ['np-badge--draft',   'مسودة'],
    'submitted'          => ['np-badge--pending', 'بانتظار المراجعة'],
    'under_review'       => ['np-badge--review',  'قيد المراجعة'],
    'more_info_required' => ['np-badge--warning', 'مطلوب استكمال بيانات'],
    'verified'           => ['np-badge--success', 'موثّقة'],
    'rejected'           => ['np-badge--danger',  'مرفوضة'],
    'suspended'          => ['np-badge--danger',  'موقوفة'],
];
[$badgeClass, $badgeLabel] = $statusBadges[$status] ?? ['np-badge--muted', $status];

$actionStyles = [
    'start_review' => 'btn-outline-primary',
    'request_info' => 'btn-outline-primary',
    'approve'      => 'btn-accent',
    'reject'       => 'btn-outline-danger',
    'suspend'      => 'btn-outline-danger',
    'reinstate'    => 'btn-accent',
];

$needsReason = ['request_info', 'reject', 'suspend'];
?>

<nav aria-label="<?= __e('common.breadcrumb') ?>" class="mb-3">
    <ol class="breadcrumb fs-sm mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('/admin/verifications')) ?>">طلبات التوثيق</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($organization['legal_name']) ?></li>
    </ol>
</nav>

<div class="row g-3">
    <div class="col-lg-8">
        <?php // ─── ملخص المنشأة ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">
                بيانات المنشأة
                <span class="np-badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span>
            </div>
            <div class="np-card__body">
                <?php if (!empty($organization['is_demo'])): ?>
                    <p><span class="np-demo-tag"><?= __e('common.demo_data') ?></span></p>
                <?php endif; ?>

                <div class="table-scroll" style="border:0">
                    <table class="np-table">
                        <tbody>
                            <tr><th style="width:34%">الاسم القانوني</th><td><?= e($organization['legal_name']) ?></td></tr>
                            <tr><th>الاسم التجاري</th><td><?= e($organization['trading_name'] ?: '—') ?></td></tr>
                            <tr><th>النوع</th><td><?= e($organization['type_name']) ?></td></tr>
                            <tr><th>القطاع</th><td><?= e($organization['sector_name'] ?? '—') ?> / <?= e($organization['sub_sector_name'] ?? '—') ?></td></tr>
                            <tr><th>الموقع</th><td><?= e($organization['governorate_name'] ?? '—') ?> — <?= e($organization['city_name'] ?? '—') ?></td></tr>
                            <tr><th>العنوان</th><td><?= e($organization['address'] ?? '—') ?></td></tr>
                            <tr><th>الهاتف</th><td dir="ltr"><?= e($organization['public_phone'] ?? '—') ?></td></tr>
                            <tr><th>البريد</th><td dir="ltr"><?= e($organization['public_email'] ?? '—') ?></td></tr>
                            <tr><th>الموقع الإلكتروني</th><td dir="ltr"><?= e($organization['website'] ?? '—') ?></td></tr>
                            <tr><th>الوصف المختصر</th><td><?= e($organization['short_description'] ?? '—') ?></td></tr>
                            <tr><th>تاريخ التسجيل</th><td><?= e(format_date($organization['created_at'])) ?></td></tr>
                            <tr><th>تاريخ الإرسال</th><td><?= e(format_date($organization['submitted_at'], true)) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php // ─── البيانات التفصيلية ─── ?>
        <?php if ($profile !== []): ?>
            <div class="np-card mb-3">
                <div class="np-card__header">
                    البيانات التفصيلية
                    <span class="np-badge np-badge--warning">بيانات مراجعة — لا تظهر للعامة</span>
                </div>
                <div class="np-card__body">
                    <div class="table-scroll" style="border:0">
                        <table class="np-table">
                            <tbody>
                                <?php
                                // تسميات عربية للحقول المعروضة؛ ما لا تسمية له لا يُعرض
                                $labels = [
                                    'formalization_status'   => 'الوضع القانوني',
                                    'commercial_register_no' => 'رقم السجل التجاري',
                                    'tax_registration_no'    => 'رقم التسجيل الضريبي',
                                    'establishment_date'     => 'تاريخ التأسيس',
                                    'company_size'           => 'حجم المنشأة',
                                    'employees_count'        => 'عدد العاملين',
                                    'female_employees_count' => 'منهم عاملات',
                                    'annual_revenue_range'   => 'نطاق الإيراد السنوي',
                                    'is_exporting'           => 'يُصدّر حالياً',
                                    'financing_needs'        => 'الاحتياجات التمويلية',
                                    'financing_amount_needed' => 'مبلغ التمويل المطلوب',
                                    'bds_needs'              => 'الاحتياجات غير المالية',
                                    'specializations'        => 'مجالات التخصص',
                                    'years_experience'       => 'سنوات الخبرة',
                                    'team_size'              => 'حجم الفريق',
                                    'license_number'         => 'رقم الترخيص',
                                    'services_offered'       => 'الخدمات المقدّمة',
                                    'monthly_capacity'       => 'الطاقة الشهرية',
                                    'specialists_count'      => 'عدد الأخصائيين',
                                    'contact_person_name'    => 'مسؤول التواصل',
                                    'contact_person_role'    => 'الصفة الوظيفية',
                                    'contact_person_phone'   => 'هاتف المسؤول',
                                    'contact_person_email'   => 'بريد المسؤول',
                                ];
                                ?>
                                <?php foreach ($labels as $key => $label): ?>
                                    <?php
                                    $value = $profile[$key] ?? null;
                                    if ($value === null || $value === '') {
                                        continue;
                                    }
                                    if ($key === 'is_exporting') {
                                        $value = ((int) $value === 1) ? 'نعم' : 'لا';
                                    }
                                    ?>
                                    <tr>
                                        <th style="width:34%"><?= e($label) ?></th>
                                        <td><?= e((string) $value) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php // ─── المستندات ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">
                المستندات
                <span class="text-muted-np fs-sm fw-normal">كل تنزيل يُسجَّل في سجل التدقيق</span>
            </div>
            <div class="np-card__body p-0">
                <?php if ($documents === []): ?>
                    <div class="np-empty"><p class="mb-0">لم تُرفع أي مستندات.</p></div>
                <?php else: ?>
                    <?php foreach ($documents as $document): ?>
                        <?php
                        $docStatus = (string) $document['status'];
                        $docBadge  = match ($docStatus) {
                            'accepted' => ['np-badge--success', 'مقبول'],
                            'rejected' => ['np-badge--danger', 'مرفوض'],
                            default    => ['np-badge--pending', 'بانتظار المراجعة'],
                        };
                        ?>
                        <div class="p-3 border-bottom border-np">
                            <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
                                <div>
                                    <div class="fw-bold"><?= e($document['type_name']) ?></div>
                                    <p class="fs-xs text-muted-np mb-1">
                                        <?= e($document['original_name']) ?>
                                        · <?= e(number_ar((int) $document['size_bytes'] / 1024)) ?> ك.ب
                                        · رُفع <?= e(time_ago($document['created_at'])) ?>
                                        <?php if (!empty($document['expiry_date'])): ?>
                                            · ينتهي <?= e(format_date($document['expiry_date'])) ?>
                                        <?php endif; ?>
                                    </p>
                                    <span class="np-badge <?= e($docBadge[0]) ?>"><?= e($docBadge[1]) ?></span>
                                    <?php if (!empty($document['review_note'])): ?>
                                        <p class="fs-xs text-muted-np mt-1 mb-0">ملاحظة: <?= e($document['review_note']) ?></p>
                                    <?php endif; ?>
                                </div>

                                <div class="d-flex flex-column gap-2 align-items-end">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/files/' . $document['media_id'])) ?>" target="_blank" rel="noopener">
                                        <?= __e('common.download') ?>
                                    </a>

                                    <?php if (in_array($status, ['submitted', 'under_review'], true)): ?>
                                        <form method="post" class="d-flex gap-1 align-items-start"
                                              action="<?= e(url('/admin/verifications/' . $organization['id'] . '/documents/' . $document['id'])) ?>">
                                            <?= csrf_field() ?>
                                            <input type="text" class="form-control form-control-sm"
                                                   name="review_note" placeholder="سبب الرفض" maxlength="1000"
                                                   style="min-width:12rem">
                                            <button type="submit" name="status" value="accepted"
                                                    class="btn btn-sm btn-accent">قبول</button>
                                            <button type="submit" name="status" value="rejected"
                                                    class="btn btn-sm btn-outline-danger">رفض</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php // ─── العمود الجانبي: القرار ─── ?>
    <div class="col-lg-4">
        <div class="np-card mb-3">
            <div class="np-card__header">اتخاذ قرار</div>
            <div class="np-card__body">
                <?php if ($availableActions === []): ?>
                    <p class="text-muted-np mb-0">لا توجد إجراءات متاحة على الحالة الحالية.</p>
                <?php else: ?>
                    <?php foreach ($availableActions as $action): ?>
                        <form method="post" class="mb-3"
                              action="<?= e(url('/admin/verifications/' . $organization['id'] . '/decide')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= e($action) ?>">

                            <?php if (in_array($action, $needsReason, true)): ?>
                                <label class="form-label fs-sm" for="reason_<?= e($action) ?>">
                                    السبب<span class="required" aria-hidden="true">*</span>
                                    <span class="text-muted-np fw-normal">(يظهر للمنشأة)</span>
                                </label>
                                <textarea class="form-control form-control-sm mb-2" rows="3" required
                                          id="reason_<?= e($action) ?>" name="reason" maxlength="1000"
                                          placeholder="وضّح ما المطلوب من المنشأة بدقة."></textarea>

                                <label class="form-label fs-sm" for="note_<?= e($action) ?>">
                                    ملاحظة داخلية
                                    <span class="text-muted-np fw-normal">(لا تظهر للمنشأة)</span>
                                </label>
                                <textarea class="form-control form-control-sm mb-2" rows="2"
                                          id="note_<?= e($action) ?>" name="internal_note" maxlength="1000"></textarea>
                            <?php endif; ?>

                            <button type="submit"
                                    class="btn btn-sm w-100 <?= e($actionStyles[$action] ?? 'btn-outline-primary') ?>"
                                <?php if (in_array($action, ['approve', 'reject', 'suspend'], true)): ?>
                                    data-confirm="تأكيد الإجراء: <?= e($verificationService->actionLabel($action)) ?>؟"
                                <?php endif; ?>>
                                <?= e($verificationService->actionLabel($action)) ?>
                            </button>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="np-card mb-3">
            <div class="np-card__header">اكتمال الملف</div>
            <div class="np-card__body">
                <div class="completion mb-2">
                    <div class="completion__track" role="progressbar"
                         aria-valuenow="<?= e((string) $completion['score']) ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="completion__fill" style="width: <?= e((string) $completion['score']) ?>%"></div>
                    </div>
                    <span class="completion__value"><?= e((string) $completion['score']) ?>%</span>
                </div>
                <?php if ($completion['missing'] !== []): ?>
                    <p class="fs-sm fw-bold mb-1">ناقص:</p>
                    <ul class="fs-xs text-muted-np mb-0 ps-3">
                        <?php foreach ($completion['missing'] as $item): ?>
                            <li><?= e($item['label']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="np-card mb-3">
            <div class="np-card__header">أعضاء المنشأة</div>
            <div class="np-card__body p-0">
                <table class="np-table">
                    <tbody>
                        <?php foreach ($members as $member): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold fs-sm"><?= e($member['name']) ?></div>
                                    <div class="fs-xs text-muted-np" dir="ltr"><?= e($member['email']) ?></div>
                                </td>
                                <td class="fs-xs text-muted-np"><?= e($member['role_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="np-card">
            <div class="np-card__header">سجل القرارات</div>
            <div class="np-card__body">
                <?php if ($history === []): ?>
                    <p class="text-muted-np fs-sm mb-0">لا توجد قرارات سابقة.</p>
                <?php else: ?>
                    <?php foreach ($history as $entry): ?>
                        <div class="step-item">
                            <span class="step-item__marker" aria-hidden="true">•</span>
                            <div>
                                <div class="step-item__title fs-sm">
                                    <?= e($verificationService->actionLabel((string) $entry['action'])) ?>
                                </div>
                                <p class="step-item__desc mb-0">
                                    <?= e($entry['actor_name'] ?? 'النظام') ?>
                                    · <?= e(format_date($entry['created_at'], true)) ?>
                                    <?php if (!empty($entry['reason'])): ?>
                                        <br><strong>السبب:</strong> <?= e($entry['reason']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['internal_note'])): ?>
                                        <br><em>ملاحظة داخلية:</em> <?= e($entry['internal_note']) ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
