<?php
/**
 * نموذج طلب التمويل | The financing application form (§4.5).
 *
 * @var array<string,mixed> $product
 * @var array<string,mixed> $profile
 * @var \App\Services\FinancingProductService $service
 */
$lines = static fn (?string $text): array => array_values(array_filter(
    array_map('trim', preg_split('/\r?\n/', (string) $text) ?: []),
    static fn (string $line): bool => $line !== '',
));
?>
<a class="fs-sm text-muted-np" href="<?= e(url('/app/finance/opportunities')) ?>">→ فرص التمويل</a>

<div class="row g-3 mt-1">
    <div class="col-lg-7">
        <div class="np-card">
            <div class="np-card__header">بيانات الطلب</div>
            <div class="np-card__body">
                <form method="post"
                      action="<?= e(url('/app/finance/products/' . $product['id'] . '/apply')) ?>" data-guard>
                    <?= csrf_field() ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="requested_amount">
                                المبلغ المطلوب <span class="required">*</span></label>
                            <input type="number" class="form-control <?= has_error('requested_amount') ? 'is-invalid' : '' ?>"
                                   id="requested_amount" name="requested_amount" dir="ltr" required
                                   min="<?= e((string) ($product['min_amount'] ?? 0)) ?>"
                                   max="<?= e((string) ($product['max_amount'] ?? 100000000)) ?>"
                                   step="100" value="<?= e(old('requested_amount')) ?>">
                            <p class="form-text">
                                <?= $product['min_amount'] === null ? 'حسب الطلب'
                                    : 'الشريحة المتاحة: ' . e(money((float) $product['min_amount']))
                                      . ' – ' . e(money((float) $product['max_amount'])) ?>
                            </p>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="requested_tenor_months">مدة السداد (شهور)</label>
                            <input type="number" class="form-control" id="requested_tenor_months"
                                   name="requested_tenor_months" dir="ltr" min="1" max="360"
                                   value="<?= e(old('requested_tenor_months')) ?>">
                            <?php if ($product['max_tenor_months'] !== null): ?>
                                <p class="form-text">
                                    حتى <?= e(number_ar((int) $product['max_tenor_months'])) ?> شهراً.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="purpose_ar">
                            الغرض من التمويل <span class="required">*</span></label>
                        <textarea class="form-control <?= has_error('purpose_ar') ? 'is-invalid' : '' ?>"
                                  id="purpose_ar" name="purpose_ar" rows="5" required
                                  minlength="20" maxlength="2000"
                                  placeholder="اشرح فيمَ سيُستخدم التمويل وكيف سيساعد مشروعك."
                        ><?= e(old('purpose_ar')) ?></textarea>
                        <p class="form-text">وضوح الغرض يختصر دورة الدراسة لدى المؤسسة.</p>
                    </div>

                    <hr class="my-4">

                    <h2 class="h6">بيانات للفرز</h2>
                    <p class="form-text mt-0">
                        بيانات معلنة منك تساعد على الفرز والإحالة. لا نطلب الرقم القومي ولا أرقام
                        الحسابات البنكية.
                    </p>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fs-sm" for="declared_annual_revenue">الإيراد السنوي التقريبي</label>
                            <input type="number" class="form-control form-control-sm" dir="ltr"
                                   id="declared_annual_revenue" name="declared_annual_revenue"
                                   min="0" step="1000" value="<?= e(old('declared_annual_revenue')) ?>">
                        </div>
                        <div class="col-md-4 col-6">
                            <label class="form-label fs-sm" for="declared_employees">عدد العاملين</label>
                            <input type="number" class="form-control form-control-sm" dir="ltr"
                                   id="declared_employees" name="declared_employees" min="0" max="10000"
                                   value="<?= e(old('declared_employees')) ?>">
                        </div>
                        <div class="col-md-4 col-6">
                            <label class="form-label fs-sm" for="years_in_business">سنوات النشاط</label>
                            <input type="number" class="form-control form-control-sm" dir="ltr"
                                   id="years_in_business" name="years_in_business" min="0" max="100"
                                   value="<?= e(old('years_in_business', $profile['years_in_business'] ?? '')) ?>">
                        </div>
                    </div>

                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" value="1" id="ack" required>
                        <label class="form-check-label fs-sm" for="ack">
                            أقرّ بأن المنصة وسيط تقني لا يمنح تمويلاً، وأن الموافقة قرار المؤسسة
                            المالية وحدها بعد دراستها للطلب.
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary mt-3">إرسال الطلب</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="np-card mb-3">
            <div class="np-card__header">المنتج المطلوب</div>
            <div class="np-card__body">
                <h2 class="h6"><?= e($product['name_ar']) ?></h2>
                <p class="fs-sm text-muted-np">
                    <?= e($product['trading_name'] ?: $product['legal_name']) ?>
                    · <?= e($service->typeLabel((string) $product['financing_type'])) ?>
                </p>

                <?php if (!empty($product['eligibility_summary_ar'])): ?>
                    <h3 class="h6 fs-sm mt-3">شروط الأهلية</h3>
                    <ul class="fs-sm mb-0">
                        <?php foreach ($lines($product['eligibility_summary_ar']) as $line): ?>
                            <li><?= e($line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($product['required_documents_ar'])): ?>
                    <h3 class="h6 fs-sm mt-3">المستندات المتوقّعة</h3>
                    <ul class="fs-sm mb-0">
                        <?php foreach ($lines($product['required_documents_ar']) as $line): ?>
                            <li><?= e($line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="fs-xs text-muted-np mb-0 mt-2">
                        تطلبها المؤسسة بعد الإحالة، وترفعها من صفحة الطلب.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <?= $view->partial('partials/finance-disclaimer') ?>
    </div>
</div>
