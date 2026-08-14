<?php
/**
 * نموذج طلب خدمة | Service request form (§4.6).
 *
 * @var array<string,mixed> $offering
 * @var \App\Services\ServiceOfferingService $service
 */
?>
<a class="fs-sm text-muted-np" href="<?= e(url('/app/services/browse')) ?>">→ تصفّح الخدمات</a>

<div class="row g-3 mt-1">
    <div class="col-lg-7">
        <div class="np-card">
            <div class="np-card__header">تفاصيل الطلب</div>
            <div class="np-card__body">
                <form method="post" data-guard
                      action="<?= e(url('/app/services/browse/' . $offering['id'] . '/request')) ?>">
                    <?= csrf_field() ?>

                    <label class="form-label" for="details_ar">
                        اشرح احتياجك <span class="required">*</span></label>
                    <textarea class="form-control <?= has_error('details_ar') ? 'is-invalid' : '' ?>"
                              id="details_ar" name="details_ar" rows="6" required
                              minlength="20" maxlength="2000"
                              placeholder="ما المشكلة أو الهدف؟ وما وضع مشروعك الحالي؟"
                    ><?= e(old('details_ar')) ?></textarea>
                    <p class="form-text">كلما وضح الاحتياج، جاء عرض المزوّد أدقّ وأسرع.</p>

                    <div class="row g-3 mt-1">
                        <div class="col-md-4">
                            <label class="form-label fs-sm" for="preferred_start_date">تاريخ البدء المفضّل</label>
                            <input type="date" class="form-control form-control-sm" dir="ltr"
                                   id="preferred_start_date" name="preferred_start_date"
                                   value="<?= e(old('preferred_start_date')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fs-sm" for="contact_person">مسؤول التواصل</label>
                            <input type="text" class="form-control form-control-sm" id="contact_person"
                                   name="contact_person" maxlength="150" value="<?= e(old('contact_person')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fs-sm" for="contact_phone">هاتف التواصل</label>
                            <input type="tel" class="form-control form-control-sm" dir="ltr" id="contact_phone"
                                   name="contact_phone" maxlength="30" value="<?= e(old('contact_phone')) ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary mt-3">إرسال الطلب</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="np-card mb-3">
            <div class="np-card__header">الخدمة المطلوبة</div>
            <div class="np-card__body">
                <h2 class="h6"><?= e($offering['name_ar']) ?></h2>
                <p class="fs-sm text-muted-np">
                    <?= e($offering['trading_name'] ?: $offering['legal_name']) ?>
                    · <?= e($service->typeLabel((string) $offering['service_type'])) ?>
                </p>
                <div class="stat-value fs-5"><?= e($service->priceLabel($offering)) ?></div>
                <?php if (!empty($offering['duration_note_ar'])): ?>
                    <p class="fs-sm text-muted-np mb-0 mt-2">
                        المدة: <?= e($offering['duration_note_ar']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="alert alert-info fs-sm mb-0">
            إرسال الطلب ليس تعاقداً. يدرس مقدّم الخدمة طلبك ويرسل عرضاً، ولك وحدك قبوله أو
            رفضه بعد الاطّلاع عليه.
        </div>
    </div>
</div>
