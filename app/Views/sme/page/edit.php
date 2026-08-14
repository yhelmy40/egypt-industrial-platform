<?php
/**
 * محرّر الصفحة التعريفية | Public page editor (§4.3).
 *
 * محرّر مقيَّد: أقسام معروفة، سمة من قائمة، لون بصيغة مُتحقَّق منها.
 * لا محرّر صفحات حرّ — هذا خارج نطاق النسخة الأولى (§14).
 *
 * @var array<string,mixed> $organization
 * @var array<string,mixed> $page
 * @var array<int,array<string,mixed>> $sections
 * @var array<string,string> $sectionLabels
 * @var array<string,string> $themes
 * @var array<int,array<string,mixed>> $gallery
 * @var array<int,array<string,mixed>> $certificates
 * @var bool $canPublish
 */

$status     = (string) $page['status'];
$publicUrl  = url('/business/' . (string) $organization['slug']);
$isPublished = $status === 'published';
?>
<div class="np-card mb-3">
    <div class="np-card__body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <span class="np-badge <?= $isPublished ? 'np-badge--success' : 'np-badge--draft' ?>">
                <?= e(match ($status) {
                    'published'   => 'منشورة',
                    'unpublished' => 'أُلغي نشرها',
                    default       => 'مسودة',
                }) ?>
            </span>
            <?php if ($isPublished): ?>
                <a class="fs-sm ms-2" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">
                    <?= e($publicUrl) ?> ↗</a>
                <div class="fs-xs text-muted-np mt-1">
                    عدد الزيارات: <?= e(number_ar((int) $page['view_count'])) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="d-flex gap-2">
            <?php if ($isPublished): ?>
                <form method="post" action="<?= e(url('/app/page/unpublish')) ?>" data-guard>
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-danger"
                            data-confirm="سيختفي رابط صفحتك من نتائج البحث ومن الدليل. متابعة؟">
                        إلغاء النشر</button>
                </form>
            <?php elseif ($canPublish): ?>
                <form method="post" action="<?= e(url('/app/page/publish')) ?>" data-guard>
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary">نشر الصفحة</button>
                </form>
            <?php else: ?>
                <div class="alert alert-warning mb-0 fs-sm">
                    النشر متاح بعد توثيق المنشأة.
                    <a href="<?= e(url('/app/organization')) ?>">إكمال ملف التوثيق</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <?php // ─── المحتوى والمظهر ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">المحتوى والمظهر</div>
            <div class="np-card__body">
                <form method="post" action="<?= e(url('/app/page/settings')) ?>" data-guard>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="headline">العنوان الرئيسي</label>
                        <input type="text" class="form-control <?= has_error('headline') ? 'is-invalid' : '' ?>"
                               id="headline" name="headline" maxlength="200"
                               value="<?= e(old('headline', $page['headline'] ?? '')) ?>"
                               placeholder="<?= e($organization['trading_name'] ?: $organization['legal_name']) ?>">
                        <?php if (has_error('headline')): ?>
                            <div class="invalid-feedback"><?= e(error_for('headline')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="tagline">الجملة التعريفية</label>
                        <input type="text" class="form-control" id="tagline" name="tagline" maxlength="300"
                               value="<?= e(old('tagline', $page['tagline'] ?? '')) ?>"
                               placeholder="سطر واحد يصف ما تقدّمه منشأتك">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="story">قصة المنشأة</label>
                        <textarea class="form-control" id="story" name="story" rows="6" maxlength="10000"
                        ><?= e(old('story', $page['story'] ?? '')) ?></textarea>
                        <p class="form-text">نص عادي فقط — لا تُقبل وسوم HTML، وتُعرض الأسطر كما كتبتها.</p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="operating_hours">مواعيد العمل</label>
                        <textarea class="form-control" id="operating_hours" name="operating_hours"
                                  rows="3" maxlength="500"
                                  placeholder="السبت – الخميس: ٩ ص – ٥ م"
                        ><?= e(old('operating_hours', $page['operating_hours'] ?? '')) ?></textarea>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label" for="theme">السمة</label>
                            <select class="form-select" id="theme" name="theme">
                                <?php foreach ($themes as $code => $label): ?>
                                    <option value="<?= e($code) ?>"
                                        <?= (string) $page['theme'] === $code ? ' selected' : '' ?>>
                                        <?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="primary_color">لون العلامة</label>
                            <input type="color" class="form-control form-control-color" id="primary_color"
                                   name="primary_color" value="<?= e((string) $page['primary_color']) ?>">
                        </div>
                    </div>

                    <?php // ─── الخصوصية ─── ?>
                    <fieldset class="mb-3">
                        <legend class="form-label">ما الذي يظهر للعامة؟</legend>
                        <p class="form-text mt-0">
                            الافتراضي هو الأقل كشفاً. بيانات التسجيل الحسّاسة لا تُعرض على الصفحة العامة
                            بأي حال، حتى لو فُعِّلت الخيارات أدناه.
                        </p>

                        <?php
                        $toggles = [
                            'show_phone'           => 'رقم الهاتف',
                            'show_whatsapp'        => 'رقم واتساب',
                            'show_email'           => 'البريد الإلكتروني',
                            'show_address'         => 'العنوان',
                            'enable_enquiry_form'  => 'نموذج الاستفسار',
                            'enable_quote_request' => 'طلب عرض سعر',
                        ];
                        ?>
                        <div class="row g-2">
                            <?php foreach ($toggles as $field => $label): ?>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1"
                                               id="<?= e($field) ?>" name="<?= e($field) ?>"
                                            <?= (int) $page[$field] === 1 ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="<?= e($field) ?>">
                                            <?= e($label) ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <?php // ─── محركات البحث ─── ?>
                    <details class="mb-3">
                        <summary class="form-label" style="cursor:pointer">الظهور في محركات البحث</summary>
                        <div class="mt-2">
                            <label class="form-label fs-sm" for="meta_title">عنوان الصفحة</label>
                            <input type="text" class="form-control form-control-sm" id="meta_title"
                                   name="meta_title" maxlength="200"
                                   value="<?= e(old('meta_title', $page['meta_title'] ?? '')) ?>">

                            <label class="form-label fs-sm mt-2" for="meta_description">وصف مختصر</label>
                            <textarea class="form-control form-control-sm" id="meta_description"
                                      name="meta_description" rows="2" maxlength="300"
                            ><?= e(old('meta_description', $page['meta_description'] ?? '')) ?></textarea>
                        </div>
                    </details>

                    <button type="submit" class="btn btn-primary">حفظ المحتوى</button>
                </form>
            </div>
        </div>

        <?php // ─── الأقسام ─── ?>
        <div class="np-card">
            <div class="np-card__header">أقسام الصفحة وترتيبها</div>
            <div class="np-card__body">
                <form method="post" action="<?= e(url('/app/page/sections')) ?>" data-guard>
                    <?= csrf_field() ?>

                    <?php foreach ($sections as $section): ?>
                        <?php $type = (string) $section['section_type']; ?>
                        <div class="border-bottom border-np pb-3 mb-3">
                            <div class="d-flex flex-wrap align-items-center gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           id="visible_<?= e($type) ?>" name="visible[]" value="<?= e($type) ?>"
                                        <?= (int) $section['is_visible'] === 1 ? ' checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="visible_<?= e($type) ?>">
                                        <?= e($sectionLabels[$type] ?? $type) ?></label>
                                </div>

                                <div class="ms-auto d-flex align-items-center gap-2">
                                    <label class="fs-xs text-muted-np mb-0" for="order_<?= e($type) ?>">الترتيب</label>
                                    <input type="number" class="form-control form-control-sm" dir="ltr"
                                           id="order_<?= e($type) ?>" name="order[<?= e($type) ?>]"
                                           min="0" max="999" style="width:5rem"
                                           value="<?= e((string) $section['sort_order']) ?>">
                                </div>
                            </div>

                            <?php if (in_array($type, ['about', 'story', 'contact', 'hours'], true)): ?>
                                <div class="row g-2 mt-2">
                                    <div class="col-md-5">
                                        <label class="visually-hidden" for="title_<?= e($type) ?>">عنوان القسم</label>
                                        <input type="text" class="form-control form-control-sm"
                                               id="title_<?= e($type) ?>" name="title[<?= e($type) ?>]"
                                               maxlength="200" placeholder="عنوان مخصّص (اختياري)"
                                               value="<?= e((string) ($section['title'] ?? '')) ?>">
                                    </div>
                                    <div class="col-md-7">
                                        <label class="visually-hidden" for="body_<?= e($type) ?>">نص القسم</label>
                                        <textarea class="form-control form-control-sm" rows="2" maxlength="5000"
                                                  id="body_<?= e($type) ?>" name="body[<?= e($type) ?>]"
                                                  placeholder="نص القسم (اختياري)"
                                        ><?= e((string) ($section['body'] ?? '')) ?></textarea>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <button type="submit" class="btn btn-primary">حفظ الأقسام</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <?php // ─── الغلاف ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">صورة الغلاف</div>
            <div class="np-card__body">
                <?php if (!empty($page['cover_media_id'])): ?>
                    <img src="<?= e(url('/files/' . $page['cover_media_id'])) ?>" alt="صورة غلاف الصفحة"
                         class="w-100 mb-3"
                         style="max-height:180px;object-fit:cover;border-radius:var(--np-radius-sm)">
                <?php endif; ?>

                <form method="post" action="<?= e(url('/app/page/media')) ?>"
                      enctype="multipart/form-data" data-guard>
                    <?= csrf_field() ?>
                    <input type="hidden" name="collection" value="cover">
                    <label class="form-label fs-sm" for="cover_image">اختر صورة</label>
                    <input type="file" class="form-control form-control-sm" id="cover_image"
                           name="image" accept="image/jpeg,image/png,image/webp" required>
                    <p class="form-text">JPG أو PNG أو WebP، بحد أقصى ٥ ميجابايت.</p>
                    <button type="submit" class="btn btn-sm btn-outline-primary">رفع الغلاف</button>
                </form>
            </div>
        </div>

        <?php // ─── المعرض والشهادات ─── ?>
        <?php
        $collections = [
            'gallery'     => ['معرض الصور', $gallery],
            'certificate' => ['الشهادات والاعتمادات', $certificates],
        ];
        ?>
        <?php foreach ($collections as $collection => [$label, $rows]): ?>
            <div class="np-card mb-3">
                <div class="np-card__header"><?= e($label) ?></div>
                <div class="np-card__body">
                    <?php if ($rows === []): ?>
                        <p class="fs-sm text-muted-np">لم تُضف صور بعد.</p>
                    <?php else: ?>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <?php foreach ($rows as $row): ?>
                                <div class="text-center">
                                    <img src="<?= e(url('/files/' . $row['media_id'])) ?>"
                                         alt="<?= e((string) ($row['caption'] ?? $label)) ?>" loading="lazy"
                                         style="width:88px;height:88px;object-fit:cover;border-radius:var(--np-radius-sm);border:1px solid var(--np-line)">
                                    <form method="post"
                                          action="<?= e(url('/app/page/media/' . $row['id'] . '/delete')) ?>"
                                          data-guard>
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-link text-danger p-0 fs-xs"
                                                data-confirm="حذف هذه الصورة نهائياً؟">حذف</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('/app/page/media')) ?>"
                          enctype="multipart/form-data" data-guard>
                        <?= csrf_field() ?>
                        <input type="hidden" name="collection" value="<?= e($collection) ?>">
                        <label class="form-label fs-sm" for="image_<?= e($collection) ?>">إضافة صورة</label>
                        <input type="file" class="form-control form-control-sm" id="image_<?= e($collection) ?>"
                               name="image" accept="image/jpeg,image/png,image/webp" required>
                        <label class="form-label fs-sm mt-2" for="caption_<?= e($collection) ?>">تعليق (اختياري)</label>
                        <input type="text" class="form-control form-control-sm" id="caption_<?= e($collection) ?>"
                               name="caption" maxlength="200">
                        <button type="submit" class="btn btn-sm btn-outline-primary mt-2">رفع</button>
                    </form>

                    <?php if ($collection === 'certificate'): ?>
                        <p class="fs-xs text-muted-np mb-0 mt-3">
                            عرض شهادة هنا لا يُعدّ تصديقاً من المنصة على صحتها. المستندات الرسمية تُراجَع
                            ضمن مسار التوثيق لا عبر هذا المعرض.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
