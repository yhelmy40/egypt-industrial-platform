<?php
/**
 * استفسار عميل | Enquiry detail and reply (§4.4).
 *
 * @var array<string,mixed> $enquiry
 */
?>
<a class="fs-sm text-muted-np" href="<?= e(url('/app/enquiries')) ?>">→ كل الاستفسارات</a>

<div class="row g-3 mt-1">
    <div class="col-lg-8">
        <div class="np-card mb-3">
            <div class="np-card__header">
                <?= e($enquiry['subject'] ?: 'استفسار عام') ?>
                <span class="fs-xs text-muted-np fw-normal"><?= e(format_date($enquiry['created_at'], true)) ?></span>
            </div>
            <div class="np-card__body">
                <?php if (!empty($enquiry['listing_name'])): ?>
                    <p class="fs-sm text-muted-np">
                        بخصوص الصنف:
                        <?php if (!empty($enquiry['listing_slug'])): ?>
                            <a href="<?= e(url('/marketplace/' . $enquiry['listing_slug'])) ?>"
                               target="_blank" rel="noopener"><?= e($enquiry['listing_name']) ?></a>
                        <?php else: ?>
                            <?= e($enquiry['listing_name']) ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <p class="mb-0" style="white-space:pre-line"><?= e($enquiry['message']) ?></p>
            </div>
        </div>

        <?php if (!empty($enquiry['reply'])): ?>
            <div class="np-card mb-3">
                <div class="np-card__header">
                    ردّك
                    <span class="fs-xs text-muted-np fw-normal">
                        <?= e(format_date($enquiry['replied_at'], true)) ?></span>
                </div>
                <div class="np-card__body">
                    <p class="mb-0" style="white-space:pre-line"><?= e($enquiry['reply']) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="np-card">
            <div class="np-card__header"><?= empty($enquiry['reply']) ? 'اكتب رداً' : 'تعديل الرد' ?></div>
            <div class="np-card__body">
                <form method="post" action="<?= e(url('/app/enquiries/' . $enquiry['id'] . '/reply')) ?>" data-guard>
                    <?= csrf_field() ?>
                    <label class="form-label" for="reply">نص الرد <span class="required">*</span></label>
                    <textarea class="form-control <?= has_error('reply') ? 'is-invalid' : '' ?>"
                              id="reply" name="reply" rows="5" required minlength="5" maxlength="2000"
                    ><?= e(old('reply', $enquiry['reply'] ?? '')) ?></textarea>
                    <?php if (has_error('reply')): ?>
                        <div class="invalid-feedback"><?= e(error_for('reply')) ?></div>
                    <?php endif; ?>
                    <p class="form-text">
                        يصل الرد للعميل داخل المنصة إن كان مسجَّلاً، وعلى الوسيلة التي تركها إن كان زائراً.
                    </p>
                    <button type="submit" class="btn btn-primary">حفظ الرد</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="np-card">
            <div class="np-card__header">بيانات المُرسِل</div>
            <div class="np-card__body">
                <dl class="row fs-sm mb-0">
                    <dt class="col-5 fw-normal text-muted-np">الاسم</dt>
                    <dd class="col-7"><?= e($enquiry['customer_name']) ?></dd>

                    <dt class="col-5 fw-normal text-muted-np">الهاتف</dt>
                    <dd class="col-7 numeric" dir="ltr"><?= e($enquiry['customer_phone']) ?></dd>

                    <?php if (!empty($enquiry['customer_email'])): ?>
                        <dt class="col-5 fw-normal text-muted-np">البريد</dt>
                        <dd class="col-7" dir="ltr" style="word-break:break-all">
                            <?= e($enquiry['customer_email']) ?></dd>
                    <?php endif; ?>

                    <dt class="col-5 fw-normal text-muted-np">نوع الحساب</dt>
                    <dd class="col-7">
                        <?= $enquiry['customer_user_id'] !== null ? 'عميل مسجَّل' : 'زائر' ?></dd>
                </dl>

                <p class="fs-xs text-muted-np mb-0 mt-3">
                    هذه البيانات تركها العميل ليتواصل معك بشأن هذا الاستفسار وحده. استخدامها في رسائل
                    تسويقية غير مطلوبة مخالف لشروط استخدام المنصة.
                </p>
            </div>
        </div>
    </div>
</div>
