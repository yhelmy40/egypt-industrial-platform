<?php
/**
 * ملف المنشأة | Organization profile screen (§4.2).
 *
 * يجمع في شاشة واحدة: الحالة، نسبة الاكتمال مع البنود الناقصة بالاسم،
 * تحرير البيانات، البيانات التفصيلية حسب النوع، وسجل قرارات التوثيق.
 *
 * @var array<string,mixed> $organization
 * @var array<string,mixed> $profile
 * @var array{score:int,completed:array,missing:array} $completion
 * @var array<int,array<string,mixed>> $checklist
 * @var array<int,array<string,mixed>> $history
 * @var bool $canEdit
 * @var bool $canSubmit
 */

$typeCode = (string) $organization['type_code'];
$status   = (string) $organization['status'];

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

$formalizationOptions = [
    // القيمة الفارغة تعني «لم يُحدَّد» وهي مختلفة عن «غير رسمي» كإجابة صريحة
    ''                    => 'لم يُحدَّد بعد',
    'informal'            => 'غير رسمي / تحت التأسيس',
    'sole_proprietorship' => 'منشأة فردية',
    'partnership'         => 'شركة تضامن / توصية',
    'llc'                 => 'شركة ذات مسؤولية محدودة',
    'joint_stock'         => 'شركة مساهمة',
    'cooperative'         => 'جمعية تعاونية',
    'other'               => 'أخرى',
];

$sizeOptions = [
    'micro'  => 'متناهي الصغر',
    'small'  => 'صغير',
    'medium' => 'متوسط',
];

$revenueOptions = [
    'under_250k'     => 'أقل من 250 ألف جنيه',
    '250k_1m'        => 'من 250 ألف إلى مليون',
    '1m_5m'          => 'من مليون إلى 5 ملايين',
    '5m_20m'         => 'من 5 إلى 20 مليون',
    '20m_50m'        => 'من 20 إلى 50 مليون',
    'over_50m'       => 'أكثر من 50 مليون',
    'prefer_not_say' => 'أفضّل عدم الإفصاح',
];

$field = static fn (string $key, mixed $default = '') => $profile[$key] ?? $default;
?>

<?php // ─────────── شريط الحالة والإجراء ─────────── ?>
<div class="np-card mb-3">
    <div class="np-card__body">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div class="d-flex gap-3 align-items-center">
                <?php if (!empty($organization['logo_media_id'])): ?>
                    <img src="<?= e(url('/files/' . $organization['logo_media_id'])) ?>"
                         alt="شعار <?= e($organization['legal_name']) ?>"
                         width="64" height="64"
                         style="width:64px;height:64px;object-fit:cover;border-radius:var(--np-radius-sm);border:1px solid var(--np-line)">
                <?php else: ?>
                    <div class="intent-card__icon mb-0" style="width:64px;height:64px;font-size:1.6rem">🏢</div>
                <?php endif; ?>

                <div>
                    <h2 class="h5 mb-1"><?= e($organization['trading_name'] ?: $organization['legal_name']) ?></h2>
                    <p class="text-muted-np fs-sm mb-2">
                        <?= e($organization['type_name']) ?>
                        <?php if (!empty($organization['sector_name'])): ?> · <?= e($organization['sector_name']) ?><?php endif; ?>
                        <?php if (!empty($organization['governorate_name'])): ?> · <?= e($organization['governorate_name']) ?><?php endif; ?>
                    </p>
                    <span class="np-badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span>
                    <?php if (!empty($organization['is_demo'])): ?>
                        <span class="np-demo-tag"><?= __e('common.demo_data') ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="text-start">
                <?php if ($canSubmit): ?>
                    <form method="post" action="<?= e(url('/app/organization/submit')) ?>" data-guard>
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-primary"
                                data-confirm="سيتم إرسال ملف المنشأة لفريق المنصة للمراجعة. هل تريد المتابعة؟"
                                data-busy-label="جارٍ الإرسال…">
                            إرسال للمراجعة
                        </button>
                    </form>
                    <p class="form-text mb-0 mt-1">يُشترط رفع المستندات الإلزامية واكتمال 60% من الملف.</p>
                <?php elseif ($status === 'submitted' || $status === 'under_review'): ?>
                    <span class="text-muted-np fs-sm">طلبك قيد المراجعة لدى فريق المنصة.</span>
                <?php elseif ($status === 'verified'): ?>
                    <span class="np-verified"><span aria-hidden="true">✓</span> منشأة موثّقة</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($organization['rejection_reason'])
            && in_array($status, ['more_info_required', 'rejected'], true)): ?>
            <div class="alert alert-warning mt-3 mb-0" role="alert">
                <strong>ملاحظات فريق المراجعة:</strong>
                <?= e($organization['rejection_reason']) ?>
            </div>
        <?php endif; ?>

        <?php if ($status === 'suspended' && !empty($organization['suspension_reason'])): ?>
            <div class="alert alert-danger mt-3 mb-0" role="alert">
                <strong>سبب الإيقاف:</strong> <?= e($organization['suspension_reason']) ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <?php // ─────────── العمود الأيمن: النماذج ─────────── ?>
    <div class="col-lg-8">

        <?php // البيانات الأساسية ?>
        <div class="np-card mb-3">
            <div class="np-card__header">البيانات الأساسية</div>
            <form method="post" action="<?= e(url('/app/organization/basics')) ?>" novalidate data-guard>
                <div class="np-card__body">
                    <?= csrf_field() ?>
                    <fieldset <?= $canEdit ? '' : 'disabled' ?>>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="legal_name">الاسم القانوني<span class="required">*</span></label>
                                <input type="text" class="form-control<?= has_error('legal_name') ? ' is-invalid' : '' ?>"
                                       id="legal_name" name="legal_name"
                                       value="<?= e(old('legal_name', $organization['legal_name'])) ?>" required maxlength="200">
                                <?php if (has_error('legal_name')): ?><div class="invalid-feedback"><?= e(error_for('legal_name')) ?></div><?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="trading_name">الاسم التجاري</label>
                                <input type="text" class="form-control" id="trading_name" name="trading_name"
                                       value="<?= e(old('trading_name', $organization['trading_name'])) ?>" maxlength="200">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="sector_id">القطاع<span class="required">*</span></label>
                                <select class="form-select" id="sector_id" name="sector_id" required
                                        data-dependent-target="sub_sector_id"
                                        data-dependent-url="<?= e(url('/app/reference/sub-sectors')) ?>"
                                        data-dependent-param="sector_id">
                                    <option value=""><?= __e('common.select') ?></option>
                                    <?php foreach ($sectors as $sector): ?>
                                        <option value="<?= e((string) $sector['id']) ?>"
                                            <?= (int) $organization['sector_id'] === (int) $sector['id'] ? ' selected' : '' ?>>
                                            <?= e($sector['name_ar']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="sub_sector_id">النشاط الفرعي</label>
                                <select class="form-select" id="sub_sector_id" name="sub_sector_id">
                                    <option value=""><?= __e('common.select') ?></option>
                                    <?php foreach ($subSectors as $subSector): ?>
                                        <option value="<?= e((string) $subSector['id']) ?>"
                                            <?= (int) $organization['sub_sector_id'] === (int) $subSector['id'] ? ' selected' : '' ?>>
                                            <?= e($subSector['name_ar']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="governorate_id">المحافظة<span class="required">*</span></label>
                                <select class="form-select" id="governorate_id" name="governorate_id" required
                                        data-dependent-target="city_id"
                                        data-dependent-url="<?= e(url('/app/reference/cities')) ?>"
                                        data-dependent-param="governorate_id">
                                    <option value=""><?= __e('common.select') ?></option>
                                    <?php foreach ($governorates as $governorate): ?>
                                        <option value="<?= e((string) $governorate['id']) ?>"
                                            <?= (int) $organization['governorate_id'] === (int) $governorate['id'] ? ' selected' : '' ?>>
                                            <?= e($governorate['name_ar']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="city_id">المدينة</label>
                                <select class="form-select" id="city_id" name="city_id">
                                    <option value=""><?= __e('common.select') ?></option>
                                    <?php foreach ($cities as $city): ?>
                                        <option value="<?= e((string) $city['id']) ?>"
                                            <?= (int) $organization['city_id'] === (int) $city['id'] ? ' selected' : '' ?>>
                                            <?= e($city['name_ar']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="address">العنوان</label>
                                <input type="text" class="form-control" id="address" name="address"
                                       value="<?= e(old('address', $organization['address'])) ?>" maxlength="500">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="public_phone">هاتف المنشأة</label>
                                <input type="tel" class="form-control<?= has_error('public_phone') ? ' is-invalid' : '' ?>"
                                       id="public_phone" name="public_phone" dir="ltr"
                                       value="<?= e(old('public_phone', $organization['public_phone'])) ?>">
                                <?php if (has_error('public_phone')): ?><div class="invalid-feedback"><?= e(error_for('public_phone')) ?></div><?php endif; ?>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="public_email">بريد المنشأة</label>
                                <input type="email" class="form-control<?= has_error('public_email') ? ' is-invalid' : '' ?>"
                                       id="public_email" name="public_email" dir="ltr"
                                       value="<?= e(old('public_email', $organization['public_email'])) ?>">
                                <?php if (has_error('public_email')): ?><div class="invalid-feedback"><?= e(error_for('public_email')) ?></div><?php endif; ?>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="website">الموقع الإلكتروني</label>
                                <input type="url" class="form-control<?= has_error('website') ? ' is-invalid' : '' ?>"
                                       id="website" name="website" dir="ltr" placeholder="https://"
                                       value="<?= e(old('website', $organization['website'])) ?>">
                                <?php if (has_error('website')): ?><div class="invalid-feedback"><?= e(error_for('website')) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="short_description">وصف مختصر</label>
                                <textarea class="form-control" id="short_description" name="short_description"
                                          rows="2" maxlength="500"><?= e(old('short_description', $organization['short_description'])) ?></textarea>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="description">وصف تفصيلي</label>
                                <textarea class="form-control" id="description" name="description"
                                          rows="5" maxlength="5000"><?= e(old('description', $organization['description'])) ?></textarea>
                                <div class="form-text">اشرح منتجاتك أو خدماتك وما يميّز منشأتك. سيظهر على صفحتك العامة.</div>
                            </div>
                        </div>
                    </fieldset>
                </div>

                <?php if ($canEdit): ?>
                    <div class="np-card__footer text-start">
                        <button type="submit" class="btn btn-primary"><?= __e('common.save') ?></button>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <?php // البيانات التفصيلية حسب النوع ?>
        <div class="np-card mb-3">
            <div class="np-card__header">
                البيانات التفصيلية
                <span class="np-badge np-badge--muted">لا تظهر على الصفحة العامة</span>
            </div>
            <form method="post" action="<?= e(url('/app/organization/profile')) ?>" novalidate data-guard>
                <div class="np-card__body">
                    <?= csrf_field() ?>
                    <fieldset <?= $canEdit ? '' : 'disabled' ?>>
                        <div class="row g-3">

                            <?php if ($typeCode === 'sme'): ?>
                                <div class="col-md-6">
                                    <label class="form-label" for="formalization_status">الوضع القانوني</label>
                                    <select class="form-select" id="formalization_status" name="formalization_status">
                                        <?php foreach ($formalizationOptions as $value => $label): ?>
                                            <option value="<?= e($value) ?>"
                                                <?= (string) $field('formalization_status') === $value ? ' selected' : '' ?>>
                                                <?= e($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="establishment_date">تاريخ التأسيس</label>
                                    <input type="date" class="form-control" id="establishment_date" name="establishment_date"
                                           value="<?= e((string) $field('establishment_date')) ?>" dir="ltr">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label" for="company_size">حجم المنشأة</label>
                                    <select class="form-select" id="company_size" name="company_size">
                                        <option value=""><?= __e('common.select') ?></option>
                                        <?php foreach ($sizeOptions as $value => $label): ?>
                                            <option value="<?= e($value) ?>"
                                                <?= (string) $field('company_size') === $value ? ' selected' : '' ?>>
                                                <?= e($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label" for="employees_count">عدد العاملين</label>
                                    <input type="number" class="form-control<?= has_error('employees_count') ? ' is-invalid' : '' ?>"
                                           id="employees_count" name="employees_count" min="0" max="100000"
                                           value="<?= e((string) $field('employees_count')) ?>">
                                    <?php if (has_error('employees_count')): ?><div class="invalid-feedback"><?= e(error_for('employees_count')) ?></div><?php endif; ?>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label" for="female_employees_count">منهم عاملات</label>
                                    <input type="number" class="form-control<?= has_error('female_employees_count') ? ' is-invalid' : '' ?>"
                                           id="female_employees_count" name="female_employees_count" min="0" max="100000"
                                           value="<?= e((string) $field('female_employees_count')) ?>">
                                    <?php if (has_error('female_employees_count')): ?><div class="invalid-feedback"><?= e(error_for('female_employees_count')) ?></div><?php endif; ?>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="annual_revenue_range">نطاق الإيراد السنوي</label>
                                    <select class="form-select" id="annual_revenue_range" name="annual_revenue_range">
                                        <option value=""><?= __e('common.select') ?></option>
                                        <?php foreach ($revenueOptions as $value => $label): ?>
                                            <option value="<?= e($value) ?>"
                                                <?= (string) $field('annual_revenue_range') === $value ? ' selected' : '' ?>>
                                                <?= e($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="commercial_register_no">رقم السجل التجاري</label>
                                    <input type="text" class="form-control" id="commercial_register_no"
                                           name="commercial_register_no" dir="ltr" maxlength="50"
                                           value="<?= e((string) $field('commercial_register_no')) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="tax_registration_no">رقم التسجيل الضريبي</label>
                                    <input type="text" class="form-control" id="tax_registration_no"
                                           name="tax_registration_no" dir="ltr" maxlength="50"
                                           value="<?= e((string) $field('tax_registration_no')) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="financing_amount_needed">مبلغ التمويل المطلوب (ج.م)</label>
                                    <input type="number" step="0.01" min="0" class="form-control"
                                           id="financing_amount_needed" name="financing_amount_needed" dir="ltr"
                                           value="<?= e((string) $field('financing_amount_needed')) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label d-block">التصدير</label>
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="is_exporting"
                                               name="is_exporting" value="1"
                                            <?= (int) $field('is_exporting', 0) === 1 ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="is_exporting">المنشأة تُصدّر حالياً</label>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label" for="financing_needs">الاحتياجات التمويلية</label>
                                    <textarea class="form-control" id="financing_needs" name="financing_needs"
                                              rows="2" maxlength="1000"
                                              placeholder="مثال: تمويل رأس مال عامل، شراء معدات"><?= e((string) $field('financing_needs')) ?></textarea>
                                </div>

                                <div class="col-12">
                                    <label class="form-label" for="bds_needs">الاحتياجات غير المالية</label>
                                    <textarea class="form-control" id="bds_needs" name="bds_needs"
                                              rows="2" maxlength="1000"
                                              placeholder="مثال: دراسة جدوى، تسويق إلكتروني، تحسين جودة المنتج"><?= e((string) $field('bds_needs')) ?></textarea>
                                    <div class="form-text">تساعدنا في ترشيح الخدمات والبرامج المناسبة لمشروعك.</div>
                                </div>
                            <?php endif; ?>

                            <?php if (in_array($typeCode, ['service_provider', 'bank', 'ngo', 'government'], true)): ?>
                                <div class="col-12">
                                    <label class="form-label" for="specializations">مجالات التخصص</label>
                                    <textarea class="form-control" id="specializations" name="specializations"
                                              rows="2" maxlength="1000"><?= e((string) $field('specializations')) ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="years_experience">سنوات الخبرة</label>
                                    <input type="number" class="form-control" id="years_experience" name="years_experience"
                                           min="0" max="200" value="<?= e((string) $field('years_experience')) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="team_size">حجم الفريق</label>
                                    <input type="number" class="form-control" id="team_size" name="team_size"
                                           min="0" value="<?= e((string) $field('team_size')) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label d-block">نطاق الخدمة</label>
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="serves_all_governorates"
                                               name="serves_all_governorates" value="1"
                                            <?= (int) $field('serves_all_governorates', 0) === 1 ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="serves_all_governorates">كل المحافظات</label>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($typeCode === 'bds_center'): ?>
                                <div class="col-12">
                                    <label class="form-label" for="services_offered">الخدمات التي يقدّمها المركز</label>
                                    <textarea class="form-control" id="services_offered" name="services_offered"
                                              rows="3" maxlength="2000"><?= e((string) $field('services_offered')) ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="monthly_capacity">الطاقة الشهرية (عدد الحالات)</label>
                                    <input type="number" class="form-control" id="monthly_capacity" name="monthly_capacity"
                                           min="0" value="<?= e((string) $field('monthly_capacity')) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="specialists_count">عدد الأخصائيين</label>
                                    <input type="number" class="form-control" id="specialists_count" name="specialists_count"
                                           min="0" value="<?= e((string) $field('specialists_count')) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="working_hours">مواعيد العمل</label>
                                    <input type="text" class="form-control" id="working_hours" name="working_hours"
                                           maxlength="255" value="<?= e((string) $field('working_hours')) ?>">
                                </div>
                            <?php endif; ?>

                            <?php // مسؤول التواصل — مشترك بين كل الأنواع ?>
                            <div class="col-12"><hr class="my-2"></div>
                            <div class="col-12"><h3 class="h6 mb-0">مسؤول التواصل</h3></div>

                            <div class="col-md-6">
                                <label class="form-label" for="contact_person_name">الاسم</label>
                                <input type="text" class="form-control" id="contact_person_name"
                                       name="contact_person_name" maxlength="150"
                                       value="<?= e((string) $field('contact_person_name')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="contact_person_role">الصفة الوظيفية</label>
                                <input type="text" class="form-control" id="contact_person_role"
                                       name="contact_person_role" maxlength="100"
                                       value="<?= e((string) $field('contact_person_role')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="contact_person_phone">الهاتف</label>
                                <input type="tel" class="form-control<?= has_error('contact_person_phone') ? ' is-invalid' : '' ?>"
                                       id="contact_person_phone" name="contact_person_phone" dir="ltr"
                                       value="<?= e((string) $field('contact_person_phone')) ?>">
                                <?php if (has_error('contact_person_phone')): ?><div class="invalid-feedback"><?= e(error_for('contact_person_phone')) ?></div><?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="contact_person_email">البريد الإلكتروني</label>
                                <input type="email" class="form-control<?= has_error('contact_person_email') ? ' is-invalid' : '' ?>"
                                       id="contact_person_email" name="contact_person_email" dir="ltr"
                                       value="<?= e((string) $field('contact_person_email')) ?>">
                                <?php if (has_error('contact_person_email')): ?><div class="invalid-feedback"><?= e(error_for('contact_person_email')) ?></div><?php endif; ?>
                            </div>
                        </div>
                    </fieldset>
                </div>

                <?php if ($canEdit): ?>
                    <div class="np-card__footer text-start">
                        <button type="submit" class="btn btn-primary"><?= __e('common.save') ?></button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php // ─────────── العمود الأيسر: الاكتمال والمستندات والسجل ─────────── ?>
    <div class="col-lg-4">

        <div class="np-card mb-3">
            <div class="np-card__header">اكتمال الملف</div>
            <div class="np-card__body">
                <div class="completion mb-3">
                    <div class="completion__track" role="progressbar"
                         aria-valuenow="<?= e((string) $completion['score']) ?>" aria-valuemin="0" aria-valuemax="100"
                         aria-label="نسبة اكتمال الملف">
                        <div class="completion__fill" style="width: <?= e((string) $completion['score']) ?>%"></div>
                    </div>
                    <span class="completion__value"><?= e((string) $completion['score']) ?>%</span>
                </div>

                <?php if ($completion['missing'] === []): ?>
                    <p class="np-verified mb-0"><span aria-hidden="true">✓</span> اكتمل الملف بالكامل</p>
                <?php else: ?>
                    <p class="fs-sm fw-bold mb-2">بنود ناقصة:</p>
                    <ul class="fs-sm mb-0 ps-3">
                        <?php foreach ($completion['missing'] as $item): ?>
                            <li class="mb-1">
                                <a href="<?= e($item['url']) ?>"><?= e($item['label']) ?></a>
                                <span class="text-muted-np">(+<?= e((string) $item['weight']) ?>)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <?php // الشعار ?>
        <div class="np-card mb-3">
            <div class="np-card__header">شعار المنشأة</div>
            <form method="post" action="<?= e(url('/app/organization/logo')) ?>" enctype="multipart/form-data" data-guard>
                <div class="np-card__body">
                    <?= csrf_field() ?>
                    <?php if (has_error('logo')): ?>
                        <div class="alert alert-danger fs-sm" role="alert"><?= e(error_for('logo')) ?></div>
                    <?php endif; ?>
                    <input type="file" class="form-control" id="logo" name="logo"
                           accept=".jpg,.jpeg,.png,.webp" <?= $canEdit ? '' : 'disabled' ?>>
                    <div class="form-text">JPG أو PNG أو WEBP — بحد أقصى 5 ميجابايت.</div>
                </div>
                <?php if ($canEdit): ?>
                    <div class="np-card__footer text-start">
                        <button type="submit" class="btn btn-outline-primary btn-sm"><?= __e('common.upload') ?></button>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <?php // المستندات ?>
        <div class="np-card mb-3">
            <div class="np-card__header">
                المستندات
                <a class="fs-sm" href="<?= e(url('/app/organization/documents')) ?>">إدارة</a>
            </div>
            <div class="np-card__body">
                <?php
                $required = array_filter($checklist, static fn ($row) => (int) $row['is_required'] === 1);
                $missing  = array_filter($required, static fn ($row) => $row['document_id'] === null);
                ?>
                <?php if ($required === []): ?>
                    <p class="text-muted-np fs-sm mb-0">لا توجد مستندات إلزامية لهذا النوع من المنشآت.</p>
                <?php elseif ($missing === []): ?>
                    <p class="np-verified mb-0"><span aria-hidden="true">✓</span> كل المستندات الإلزامية مرفوعة</p>
                <?php else: ?>
                    <p class="fs-sm mb-2">مستندات إلزامية ناقصة:</p>
                    <ul class="fs-sm mb-0 ps-3">
                        <?php foreach ($missing as $row): ?>
                            <li><?= e($row['name_ar']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <?php // سجل قرارات التوثيق ?>
        <div class="np-card">
            <div class="np-card__header">سجل الطلب</div>
            <div class="np-card__body">
                <?php if ($history === []): ?>
                    <p class="text-muted-np fs-sm mb-0">لم يُرسل الملف للمراجعة بعد.</p>
                <?php else: ?>
                    <?php foreach ($history as $entry): ?>
                        <div class="step-item">
                            <span class="step-item__marker" aria-hidden="true">•</span>
                            <div>
                                <div class="step-item__title fs-sm">
                                    <?= e($verificationService->actionLabel((string) $entry['action']) ?? $entry['action']) ?>
                                </div>
                                <p class="step-item__desc mb-0">
                                    <?= e(format_date($entry['created_at'], true)) ?>
                                    <?php if (!empty($entry['reason'])): ?>
                                        <br><?= e($entry['reason']) ?>
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
