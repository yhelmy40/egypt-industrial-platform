<?php
/**
 * نموذج طلب الدعم | BDS support request form (§4.8).
 *
 * @var array<string,mixed> $center
 * @var array<string,string> $sections
 * @var array<string,mixed>|null $assessment
 */
$priorities = $assessment !== null && !empty($assessment['priority_sections'])
    ? explode(',', (string) $assessment['priority_sections'])
    : [];
?>
<a class="fs-sm text-muted-np" href="<?= e(url('/app/bds/centers')) ?>">→ مراكز تطوير الأعمال</a>

<div class="row g-3 mt-1">
    <div class="col-lg-7">
        <div class="np-card">
            <div class="np-card__header">طلب دعم</div>
            <div class="np-card__body">
                <form method="post" data-guard
                      action="<?= e(url('/app/bds/centers/' . $center['id'] . '/request')) ?>">
                    <?= csrf_field() ?>

                    <?php if ($assessment !== null): ?>
                        <input type="hidden" name="assessment_id"
                               value="<?= e((string) $assessment['id']) ?>">
                        <div class="alert alert-info fs-sm">
                            سيُرفق تقييم احتياجات مشروعك المكتمل في
                            <?= e(format_date($assessment['completed_at'])) ?> مع الطلب،
                            ليبدأ الأخصائي من تشخيص لا من صفحة بيضاء.
                        </div>
                    <?php endif; ?>

                    <label class="form-label" for="title_ar">
                        عنوان الطلب <span class="required">*</span></label>
                    <input type="text" class="form-control <?= has_error('title_ar') ? 'is-invalid' : '' ?>"
                           id="title_ar" name="title_ar" required minlength="5" maxlength="200"
                           value="<?= e(old('title_ar')) ?>"
                           placeholder="مثال: تنظيم حسابات المشروع وإعداد تقارير شهرية">

                    <label class="form-label mt-3" for="request_details_ar">
                        اشرح احتياجك <span class="required">*</span></label>
                    <textarea class="form-control" id="request_details_ar" name="request_details_ar"
                              rows="6" required minlength="20" maxlength="2000"
                              placeholder="ما المشكلة؟ وما الذي جرّبته؟ وما النتيجة التي تريدها؟"
                    ><?= e(old('request_details_ar')) ?></textarea>
                    <p class="form-text">الوضوح هنا يختصر جلسة كاملة من التشخيص.</p>

                    <fieldset class="mt-3">
                        <legend class="form-label">مجالات الاحتياج</legend>
                        <div class="row g-2">
                            <?php foreach ($sections as $key => $label): ?>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="focus_areas[]"
                                               value="<?= e($key) ?>" id="focus_<?= e($key) ?>"
                                            <?= in_array($key, $priorities, true) ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="focus_<?= e($key) ?>">
                                            <?= e($label) ?>
                                            <?php if (in_array($key, $priorities, true)): ?>
                                                <span class="np-badge np-badge--warning">أولوية تقييمك</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <button type="submit" class="btn btn-primary mt-3">إرسال الطلب</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="np-card mb-3">
            <div class="np-card__header">المركز</div>
            <div class="np-card__body">
                <h2 class="h6"><?= e($center['trading_name'] ?: $center['legal_name']) ?></h2>
                <?php if (!empty($center['host_entity'])): ?>
                    <p class="fs-sm text-muted-np mb-1"><?= e($center['host_entity']) ?></p>
                <?php endif; ?>
                <?php if (!empty($center['governorate_name'])): ?>
                    <p class="fs-sm mb-1"><?= e($center['governorate_name']) ?></p>
                <?php endif; ?>
                <?php if (!empty($center['services_offered'])): ?>
                    <p class="fs-sm text-muted-np mb-1 mt-2">
                        <strong>الخدمات:</strong><br><?= e($center['services_offered']) ?></p>
                <?php endif; ?>
                <?php if (!empty($center['working_hours'])): ?>
                    <p class="fs-xs text-muted-np mb-0"><?= e($center['working_hours']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="alert alert-info fs-sm mb-0">
            بعد الإرسال يفرز المركز الطلب ويُسند أخصائياً. ستصلك إشعارات بكل تحديث،
            وتستطيع متابعة الجلسات وخطة العمل من صفحة الحالة.
        </div>
    </div>
</div>
