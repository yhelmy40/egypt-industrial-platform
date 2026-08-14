<?php
/**
 * إتمام الطلب | Checkout (§4.4).
 * الدفع في هذه النسخة غير إلكتروني: دفع عند الاستلام أو تحويل بنكي بإثبات.
 * @var array{groups:array,totals:array,count:int} $contents
 * @var array<int,string> $issues
 */
?>
<section class="container py-4">
    <h1 class="h4 mb-3">إتمام الطلب</h1>

    <?php if ($issues !== []): ?>
        <div class="alert alert-warning" role="alert">
            <strong>يلزم تعديل السلة قبل المتابعة:</strong>
            <ul class="mb-0 mt-1 fs-sm">
                <?php foreach ($issues as $issue): ?><li><?= e($issue) ?></li><?php endforeach; ?>
            </ul>
            <a class="btn btn-sm btn-outline-primary mt-2" href="<?= e(url('/cart')) ?>">العودة إلى السلة</a>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/checkout')) ?>" novalidate data-guard>
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="np-card mb-3">
                    <div class="np-card__header">بيانات التسليم</div>
                    <div class="np-card__body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="customer_name">الاسم<span class="required">*</span></label>
                                <input type="text" class="form-control<?= has_error('customer_name') ? ' is-invalid' : '' ?>"
                                       id="customer_name" name="customer_name" required maxlength="150"
                                       value="<?= e(old('customer_name', $currentUser['name'] ?? '')) ?>">
                                <?php if (has_error('customer_name')): ?><div class="invalid-feedback"><?= e(error_for('customer_name')) ?></div><?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="customer_phone">رقم الهاتف<span class="required">*</span></label>
                                <input type="tel" class="form-control<?= has_error('customer_phone') ? ' is-invalid' : '' ?>"
                                       id="customer_phone" name="customer_phone" required dir="ltr"
                                       placeholder="01012345678" value="<?= e(old('customer_phone')) ?>">
                                <?php if (has_error('customer_phone')): ?><div class="invalid-feedback"><?= e(error_for('customer_phone')) ?></div><?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="customer_email">البريد الإلكتروني</label>
                                <input type="email" class="form-control" id="customer_email" name="customer_email"
                                       dir="ltr" value="<?= e(old('customer_email', $currentUser['email'] ?? '')) ?>">
                                <div class="form-text">لإرسال رابط تتبّع الطلب.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="governorate_id">المحافظة<span class="required">*</span></label>
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
                                <?php if (has_error('governorate_id')): ?><div class="invalid-feedback"><?= e(error_for('governorate_id')) ?></div><?php endif; ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="delivery_address">عنوان التسليم<span class="required">*</span></label>
                                <textarea class="form-control<?= has_error('delivery_address') ? ' is-invalid' : '' ?>"
                                          id="delivery_address" name="delivery_address" rows="2" required
                                          minlength="10" maxlength="500"><?= e(old('delivery_address')) ?></textarea>
                                <?php if (has_error('delivery_address')): ?><div class="invalid-feedback"><?= e(error_for('delivery_address')) ?></div><?php endif; ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="customer_note">ملاحظات للبائع</label>
                                <textarea class="form-control" id="customer_note" name="customer_note"
                                          rows="2" maxlength="1000"></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="np-card">
                    <div class="np-card__header">طريقة الدفع</div>
                    <div class="np-card__body">
                        <?php foreach ($paymentMethods as $index => $method): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="payment_method_id"
                                       id="pm<?= e((string) $method['id']) ?>" value="<?= e((string) $method['id']) ?>"
                                    <?= $index === 0 ? ' checked' : '' ?>>
                                <label class="form-check-label" for="pm<?= e((string) $method['id']) ?>">
                                    <strong><?= e($method['name_ar']) ?></strong>
                                    <?php if (!empty($method['instructions_ar'])): ?>
                                        <span class="d-block fs-sm text-muted-np"><?= e($method['instructions_ar']) ?></span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        <?php endforeach; ?>

                        <div class="alert alert-info fs-sm mb-0 mt-3" role="alert">
                            الدفع الإلكتروني غير مُفعَّل في هذه النسخة. تتم التسوية مباشرة مع المنشأة البائعة.
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="np-card">
                    <div class="np-card__header">مراجعة الطلب</div>
                    <div class="np-card__body p-0">
                        <?php foreach ($contents['groups'] as $group): ?>
                            <div class="p-3 border-bottom border-np">
                                <div class="fw-bold fs-sm mb-2"><?= e($group['seller_name']) ?></div>
                                <?php foreach ($group['items'] as $item): ?>
                                    <div class="d-flex justify-content-between fs-sm">
                                        <span><?= e($item['name']) ?> × <?= e(number_ar($item['quantity'], 0)) ?></span>
                                        <span class="numeric"><?= e($item['line_total']->format()) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="np-card__footer">
                        <div class="d-flex justify-content-between fs-sm py-1">
                            <span>الأصناف</span><span class="numeric"><?= e($contents['totals']['subtotal']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between fs-sm py-1">
                            <span>الضريبة</span><span class="numeric"><?= e($contents['totals']['vat']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between fs-sm py-1">
                            <span>التوصيل</span><span class="numeric"><?= e($contents['totals']['delivery']) ?></span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between mb-3">
                            <strong>الإجمالي</strong>
                            <strong class="numeric text-primary"><?= e($contents['totals']['total']) ?></strong>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"
                                data-busy-label="جارٍ تسجيل الطلب…" <?= $issues !== [] ? 'disabled' : '' ?>>
                            تأكيد الطلب
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</section>
