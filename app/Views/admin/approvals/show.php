<?php
/**
 * مراجعة عنصر للاعتماد | Reviewing one catalogue item (§4.5, §4.6).
 *
 * @var string $kind
 * @var string $basePath
 * @var array<string,mixed> $item
 * @var string $typeLabel
 * @var \App\Services\ApprovableCatalogService $service
 */
$isFinancing = $kind === 'financing';
$status      = (string) $item['status'];
$canDecide   = $status === 'pending_review';

$lines = static fn (?string $text): array => array_values(array_filter(
    array_map('trim', preg_split('/\r?\n/', (string) $text) ?: []),
    static fn (string $line): bool => $line !== '',
));
?>
<a class="fs-sm text-muted-np" href="<?= e(url($basePath)) ?>">→ طابور الاعتماد</a>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-1 mb-3">
    <h1 class="h5 mb-0">
        <?= e($item['name_ar']) ?>
        <span class="np-badge <?= e($service->statusBadgeClass($status)) ?>">
            <?= e($service->statusLabel($status)) ?></span>
        <?php if (!empty($item['is_demo'])): ?>
            <span class="np-demo-tag"><?= __e('common.demo_data') ?></span>
        <?php endif; ?>
    </h1>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="np-card mb-3">
            <div class="np-card__header">المحتوى المعروض على المشروعات</div>
            <div class="np-card__body">
                <table class="np-table">
                    <tbody>
                        <tr><th>النوع</th><td><?= e($typeLabel) ?></td></tr>
                        <tr><th>الرابط العام</th>
                            <td dir="ltr" class="fs-sm">
                                <?= e(($isFinancing ? '/financing/' : '/services/') . $item['slug']) ?></td></tr>

                        <?php if ($isFinancing): ?>
                            <tr><th>شريحة التمويل</th>
                                <td class="numeric">
                                    <?= $item['min_amount'] === null ? '— غير محدَّدة'
                                        : e(money((float) $item['min_amount']) . ' – '
                                            . money((float) $item['max_amount'])) ?></td></tr>
                            <tr><th>مدة السداد</th>
                                <td><?= $item['max_tenor_months'] === null ? '—'
                                    : 'حتى ' . e(number_ar((int) $item['max_tenor_months'])) . ' شهراً' ?></td></tr>
                            <tr><th>بيان التكلفة</th>
                                <td class="fs-sm"><?= e((string) ($item['rate_note_ar'] ?? '— غير مذكور')) ?></td></tr>
                            <tr><th>يشترط التقنين</th>
                                <td><?= (int) $item['requires_formal_registration'] === 1 ? 'نعم' : 'لا' ?></td></tr>
                        <?php else: ?>
                            <tr><th>التسعير</th><td><?= e($service->priceLabel($item)) ?></td></tr>
                            <tr><th>طريقة التنفيذ</th>
                                <td><?= e(\App\Services\ServiceOfferingService::DELIVERY_MODES[$item['delivery_mode']]
                                    ?? (string) $item['delivery_mode']) ?></td></tr>
                            <tr><th>المدة</th>
                                <td><?= e((string) ($item['duration_note_ar'] ?? '—')) ?></td></tr>
                            <?php if ((string) $item['pricing_mode'] === 'free'): ?>
                                <tr><th>الجهة الممولة</th>
                                    <td><?= e((string) ($item['funded_by_ar'] ?? '— غير مذكورة')) ?></td></tr>
                            <?php endif; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if (!empty($item['short_description'])): ?>
                    <h2 class="h6 mt-3">الوصف المختصر</h2>
                    <p class="fs-sm"><?= e($item['short_description']) ?></p>
                <?php endif; ?>

                <?php if (!empty($item['description'])): ?>
                    <h2 class="h6 mt-3">الوصف التفصيلي</h2>
                    <p class="fs-sm mb-0" style="white-space:pre-line"><?= e($item['description']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isFinancing): ?>
            <div class="np-card mb-3">
                <div class="np-card__header">الأهلية والمستندات</div>
                <div class="np-card__body">
                    <h3 class="h6">شروط الأهلية</h3>
                    <ul class="fs-sm">
                        <?php foreach ($lines($item['eligibility_summary_ar']) as $line): ?>
                            <li><?= e($line) ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <h3 class="h6 mt-3">المستندات المطلوبة</h3>
                    <ul class="fs-sm mb-0">
                        <?php foreach ($lines($item['required_documents_ar']) as $line): ?>
                            <li><?= e($line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php else: ?>
            <div class="np-card mb-3">
                <div class="np-card__header">المخرجات والفئة المستهدفة</div>
                <div class="np-card__body">
                    <h3 class="h6">المخرجات</h3>
                    <ul class="fs-sm">
                        <?php foreach ($lines($item['deliverables_ar']) as $line): ?>
                            <li><?= e($line) ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <h3 class="h6 mt-3">الفئة المستهدفة</h3>
                    <p class="fs-sm mb-0" style="white-space:pre-line">
                        <?= e((string) ($item['target_audience_ar'] ?? '—')) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="np-card">
            <div class="np-card__header">ما ينبغي التحقّق منه</div>
            <div class="np-card__body">
                <ul class="fs-sm mb-0">
                    <li>هل الجهة موثّقة ومصرّح لها بمزاولة هذا النشاط؟</li>
                    <li>هل الشروط مكتوبة بوضوح لا يقبل تأويلاً يضرّ صاحب المشروع؟</li>
                    <li>هل التكلفة أو السعر مذكور بما يكفي لاتخاذ قرار؟</li>
                    <li>هل يخلو النص من ادّعاء اعتماد رسمي أو ضمان لا تملكه الجهة؟</li>
                    <?php if (!$isFinancing): ?>
                        <li>إن كانت الخدمة مجانية، هل الجهة الممولة مذكورة صراحةً؟</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="np-card mb-3">
            <div class="np-card__header">القرار</div>
            <div class="np-card__body">
                <?php if (!$canDecide): ?>
                    <p class="fs-sm text-muted-np mb-0">
                        هذا العنصر ليس بانتظار الاعتماد، فلا قرار مطلوب الآن.
                    </p>
                <?php else: ?>
                    <?php if ($item['provider_status'] !== 'verified'): ?>
                        <div class="alert alert-warning fs-sm">
                            الجهة المالكة غير موثّقة. الاعتماد سيفشل حتى تُوثَّق.
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url($basePath . '/' . $item['id'] . '/decide')) ?>"
                          data-guard>
                        <?= csrf_field() ?>

                        <label class="form-label" for="note">سبب القرار</label>
                        <textarea class="form-control <?= has_error('note') ? 'is-invalid' : '' ?>"
                                  id="note" name="note" rows="4" maxlength="1000"
                        ><?= e(old('note')) ?></textarea>
                        <?php if (has_error('note')): ?>
                            <div class="invalid-feedback"><?= e(error_for('note')) ?></div>
                        <?php endif; ?>
                        <p class="form-text">
                            السبب إلزامي عند الرفض ويصل إلى الجهة. الرفض بلا سبب يترك الجهة
                            بلا طريق للتصحيح.
                        </p>

                        <div class="d-flex gap-2">
                            <button type="submit" name="decision" value="approve"
                                    class="btn btn-primary flex-grow-1">اعتماد ونشر</button>
                            <button type="submit" name="decision" value="reject"
                                    class="btn btn-outline-danger flex-grow-1">رفض</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="np-card">
            <div class="np-card__header">الجهة المالكة</div>
            <div class="np-card__body">
                <dl class="row fs-sm mb-0">
                    <dt class="col-5 fw-normal text-muted-np">الاسم</dt>
                    <dd class="col-7"><?= e($item['trading_name'] ?: $item['legal_name']) ?></dd>

                    <dt class="col-5 fw-normal text-muted-np">حالة التوثيق</dt>
                    <dd class="col-7">
                        <span class="np-badge <?= $item['provider_status'] === 'verified'
                            ? 'np-badge--success' : 'np-badge--danger' ?>">
                            <?= e($item['provider_status'] === 'verified' ? 'موثّقة' : 'غير موثّقة') ?></span>
                    </dd>

                    <?php if (!empty($item['governorate_name'])): ?>
                        <dt class="col-5 fw-normal text-muted-np">المحافظة</dt>
                        <dd class="col-7"><?= e($item['governorate_name']) ?></dd>
                    <?php endif; ?>
                </dl>

                <a class="btn btn-sm btn-outline-primary mt-3"
                   href="<?= e(url('/admin/verifications/' . $item['provider_id'])) ?>">ملف التوثيق</a>
            </div>
        </div>

        <?php if (!empty($item['moderation_note'])): ?>
            <div class="np-card mt-3">
                <div class="np-card__header">آخر ملاحظة مراجعة</div>
                <div class="np-card__body">
                    <p class="fs-sm mb-0" style="white-space:pre-line"><?= e($item['moderation_note']) ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
