<?php
/**
 * الخطوة الأولى من التسجيل | Registration step 1 — the minimum to create a draft.
 *
 * تُطلب هنا البيانات الأساسية فقط. باقي البيانات والمستندات تُستكمل بعد إنشاء
 * المسودة، حتى لا يواجه المستخدم نموذجاً طويلاً قبل أن يرى أي تقدّم.
 * Only the minimum is asked here; the rest is completed after the draft exists,
 * so the user is not confronted with a long form before seeing any progress.
 *
 * @var array<string,mixed> $type
 * @var array<int,array<string,mixed>> $sectors
 * @var array<int,array<string,mixed>> $governorates
 */
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <nav aria-label="<?= __e('common.breadcrumb') ?>" class="mb-3">
            <ol class="breadcrumb fs-sm mb-0">
                <li class="breadcrumb-item"><a href="<?= e(url('/app/organization/new')) ?>">نوع المنشأة</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= e($type['name_ar']) ?></li>
            </ol>
        </nav>

        <div class="np-card">
            <div class="np-card__header">
                تسجيل: <?= e($type['name_ar']) ?>
                <span class="np-badge np-badge--draft">الخطوة 1 من 3</span>
            </div>

            <form method="post" action="<?= e(url('/app/organization')) ?>" novalidate data-guard>
                <div class="np-card__body">
                    <?= csrf_field() ?>
                    <input type="hidden" name="type_code" value="<?= e((string) $type['code']) ?>">

                    <p class="text-muted-np fs-sm mb-4">
                        أدخل البيانات الأساسية لإنشاء ملف المنشأة. ستتمكن بعدها من استكمال
                        التفاصيل ورفع المستندات قبل الإرسال للمراجعة.
                    </p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="legal_name">
                                الاسم القانوني للمنشأة<span class="required" aria-hidden="true">*</span>
                            </label>
                            <input type="text" class="form-control<?= has_error('legal_name') ? ' is-invalid' : '' ?>"
                                   id="legal_name" name="legal_name" value="<?= e(old('legal_name')) ?>"
                                   required maxlength="200" autofocus>
                            <?php if (has_error('legal_name')): ?>
                                <div class="invalid-feedback"><?= e(error_for('legal_name')) ?></div>
                            <?php endif; ?>
                            <div class="form-text">كما هو مسجّل رسمياً، أو الاسم المتعارف عليه إن كانت المنشأة غير رسمية.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="trading_name">
                                الاسم التجاري
                                <span class="text-muted-np fw-normal fs-xs">(<?= __e('common.optional') ?>)</span>
                            </label>
                            <input type="text" class="form-control<?= has_error('trading_name') ? ' is-invalid' : '' ?>"
                                   id="trading_name" name="trading_name" value="<?= e(old('trading_name')) ?>" maxlength="200">
                            <?php if (has_error('trading_name')): ?>
                                <div class="invalid-feedback"><?= e(error_for('trading_name')) ?></div>
                            <?php endif; ?>
                            <div class="form-text">الاسم الذي يظهر للعملاء على صفحتك العامة.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="sector_id">
                                القطاع<span class="required" aria-hidden="true">*</span>
                            </label>
                            <select class="form-select<?= has_error('sector_id') ? ' is-invalid' : '' ?>"
                                    id="sector_id" name="sector_id" required>
                                <option value=""><?= __e('common.select') ?></option>
                                <?php foreach ($sectors as $sector): ?>
                                    <option value="<?= e((string) $sector['id']) ?>"
                                        <?= (string) old('sector_id') === (string) $sector['id'] ? ' selected' : '' ?>>
                                        <?= e($sector['name_ar']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (has_error('sector_id')): ?>
                                <div class="invalid-feedback"><?= e(error_for('sector_id')) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="governorate_id">
                                المحافظة<span class="required" aria-hidden="true">*</span>
                            </label>
                            <select class="form-select<?= has_error('governorate_id') ? ' is-invalid' : '' ?>"
                                    id="governorate_id" name="governorate_id" required>
                                <option value=""><?= __e('common.select') ?></option>
                                <?php foreach ($governorates as $governorate): ?>
                                    <option value="<?= e((string) $governorate['id']) ?>"
                                        <?= (string) old('governorate_id') === (string) $governorate['id'] ? ' selected' : '' ?>>
                                        <?= e($governorate['name_ar']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (has_error('governorate_id')): ?>
                                <div class="invalid-feedback"><?= e(error_for('governorate_id')) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="public_phone">
                                رقم الهاتف<span class="required" aria-hidden="true">*</span>
                            </label>
                            <input type="tel" class="form-control<?= has_error('public_phone') ? ' is-invalid' : '' ?>"
                                   id="public_phone" name="public_phone" value="<?= e(old('public_phone')) ?>"
                                   required placeholder="01012345678" dir="ltr">
                            <?php if (has_error('public_phone')): ?>
                                <div class="invalid-feedback"><?= e(error_for('public_phone')) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="public_email">
                                البريد الإلكتروني للمنشأة
                                <span class="text-muted-np fw-normal fs-xs">(<?= __e('common.optional') ?>)</span>
                            </label>
                            <input type="email" class="form-control<?= has_error('public_email') ? ' is-invalid' : '' ?>"
                                   id="public_email" name="public_email" value="<?= e(old('public_email')) ?>" dir="ltr">
                            <?php if (has_error('public_email')): ?>
                                <div class="invalid-feedback"><?= e(error_for('public_email')) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="short_description">
                                وصف مختصر للنشاط<span class="required" aria-hidden="true">*</span>
                            </label>
                            <textarea class="form-control<?= has_error('short_description') ? ' is-invalid' : '' ?>"
                                      id="short_description" name="short_description" rows="3"
                                      required minlength="20" maxlength="500"
                                      placeholder="مثال: تصنيع وتعبئة المواد الغذائية والحاصلات المجففة للسوق المحلي."><?= e(old('short_description')) ?></textarea>
                            <?php if (has_error('short_description')): ?>
                                <div class="invalid-feedback"><?= e(error_for('short_description')) ?></div>
                            <?php endif; ?>
                            <div class="form-text">سطران يوضّحان ما تقدّمه المنشأة. سيظهر هذا الوصف في نتائج البحث وصفحتك العامة.</div>
                        </div>
                    </div>
                </div>

                <div class="np-card__footer d-flex justify-content-between align-items-center">
                    <a class="btn btn-link text-muted-np" href="<?= e(url('/app/organization/new')) ?>">
                        <?= __e('common.back') ?>
                    </a>
                    <button type="submit" class="btn btn-primary" data-busy-label="جارٍ الحفظ…">
                        حفظ والمتابعة
                    </button>
                </div>
            </form>
        </div>

        <p class="text-muted-np fs-xs mt-3 mb-0">
            بإنشاء ملف المنشأة أنت توافق على مراجعة فريق المنصة للبيانات والمستندات المرفوعة
            لغرض التوثيق فقط، وفق <a href="<?= e(url('/privacy')) ?>" target="_blank" rel="noopener">سياسة الخصوصية</a>.
        </p>
    </div>
</div>
