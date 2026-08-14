<?php
/**
 * الصفحة التعريفية للمنشأة | SME public landing page (§4.3).
 *
 * الأقسام تُعرض بالترتيب الذي اختاره صاحب المنشأة، والمخفي منها لا يُطبع.
 * بيانات التواصل تظهر فقط وفق إعدادات الخصوصية التي ضبطها بنفسه.
 *
 * @var array<string,mixed> $organization
 * @var array<string,mixed> $page
 * @var array<int,array<string,mixed>> $sections
 * @var array<int,array<string,mixed>> $products
 * @var array<int,array<string,mixed>> $services
 */

$name  = $organization['trading_name'] ?: $organization['legal_name'];
$brand = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $page['primary_color']) === 1
    ? (string) $page['primary_color']
    : '#0b4f8a';

/** أقسام مرئية مفهرسة بنوعها | Visible sections indexed by type. */
$visible = [];
foreach ($sections as $section) {
    $visible[$section['section_type']] = $section;
}

$sectionTitle = static fn (string $type, string $fallback): string
    => (string) (($visible[$type]['title'] ?? '') ?: $fallback);
?>

<?php // لون العلامة يُمرَّر كخاصية مخصّصة مُتحقَّق من صيغتها في الخادم ?>
<div class="storefront" style="--brand: <?= e($brand) ?>">

    <?php // ─────────── الغلاف والترويسة ─────────── ?>
    <header class="storefront__hero<?= $page['cover_media_id'] ? ' storefront__hero--has-cover' : '' ?>">
        <?php if (!empty($page['cover_media_id'])): ?>
            <img class="storefront__cover" src="<?= e(url('/files/' . $page['cover_media_id'])) ?>"
                 alt="غلاف <?= e($name) ?>">
        <?php endif; ?>

        <div class="container storefront__hero-inner">
            <div class="d-flex flex-wrap align-items-end gap-3">
                <?php if (!empty($organization['logo_media_id'])): ?>
                    <img class="storefront__logo" src="<?= e(url('/files/' . $organization['logo_media_id'])) ?>"
                         alt="شعار <?= e($name) ?>">
                <?php else: ?>
                    <div class="storefront__logo storefront__logo--placeholder" aria-hidden="true">🏢</div>
                <?php endif; ?>

                <div class="flex-grow-1">
                    <h1 class="h3 mb-1"><?= e($page['headline'] ?: $name) ?></h1>
                    <p class="mb-2 storefront__tagline">
                        <?= e($page['tagline'] ?: ($organization['short_description'] ?? '')) ?>
                    </p>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="np-badge np-badge--success">
                            <span aria-hidden="true">✓</span> منشأة موثّقة
                        </span>
                        <?php if (!empty($organization['sector_name'])): ?>
                            <span class="np-badge np-badge--muted"><?= e($organization['sector_name']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($organization['governorate_name'])): ?>
                            <span class="np-badge np-badge--muted">
                                <?= e($organization['governorate_name']) ?>
                                <?php if (!empty($organization['city_name'])): ?>
                                    — <?= e($organization['city_name']) ?>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($organization['is_demo'])): ?>
                            <span class="np-demo-tag"><?= __e('common.demo_data') ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <?php if ((int) $page['enable_quote_request'] === 1): ?>
                        <a class="btn btn-primary" href="#quote">اطلب عرض سعر</a>
                    <?php endif; ?>
                    <?php if ((int) $page['enable_enquiry_form'] === 1): ?>
                        <a class="btn btn-outline-primary" href="#enquiry">تواصل معنا</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <div class="container py-4">
        <div class="row g-4">
            <div class="col-lg-8">

                <?php foreach ($sections as $section): ?>
                    <?php $type = (string) $section['section_type']; ?>

                    <?php // ── نبذة ── ?>
                    <?php if ($type === 'about'): ?>
                        <div class="np-card mb-4">
                            <div class="np-card__header"><?= e($sectionTitle('about', 'نبذة عن المنشأة')) ?></div>
                            <div class="np-card__body">
                                <p class="mb-0" style="white-space:pre-line"><?= e(
                                    $section['body'] ?: ($organization['description'] ?: $organization['short_description'])
                                ) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php // ── المنتجات ── ?>
                    <?php if ($type === 'products' && $products !== []): ?>
                        <div class="mb-4" id="products">
                            <h2 class="h5 mb-3"><?= e($sectionTitle('products', 'المنتجات')) ?></h2>
                            <div class="row g-3">
                                <?php foreach ($products as $product): ?>
                                    <div class="col-6 col-md-4">
                                        <?= $view->partial('partials/listing-card', [
                                            'listing' => $product + [
                                                'seller_slug'  => $organization['slug'],
                                                'trading_name' => $organization['trading_name'],
                                                'legal_name'   => $organization['legal_name'],
                                            ],
                                        ]) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php // ── الخدمات ── ?>
                    <?php if ($type === 'services' && $services !== []): ?>
                        <div class="mb-4" id="services">
                            <h2 class="h5 mb-3"><?= e($sectionTitle('services', 'الخدمات')) ?></h2>
                            <div class="row g-3">
                                <?php foreach ($services as $service): ?>
                                    <div class="col-6 col-md-4">
                                        <?= $view->partial('partials/listing-card', [
                                            'listing' => $service + [
                                                'seller_slug'  => $organization['slug'],
                                                'trading_name' => $organization['trading_name'],
                                                'legal_name'   => $organization['legal_name'],
                                            ],
                                        ]) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php // ── قصة المنشأة ── ?>
                    <?php if ($type === 'story' && ($page['story'] || $section['body'])): ?>
                        <div class="np-card mb-4">
                            <div class="np-card__header"><?= e($sectionTitle('story', 'قصتنا')) ?></div>
                            <div class="np-card__body">
                                <p class="mb-0" style="white-space:pre-line"><?= e($section['body'] ?: $page['story']) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php // ── المعرض ── ?>
                    <?php if ($type === 'gallery' && $gallery !== []): ?>
                        <div class="np-card mb-4">
                            <div class="np-card__header"><?= e($sectionTitle('gallery', 'معرض الصور')) ?></div>
                            <div class="np-card__body">
                                <div class="row g-2">
                                    <?php foreach ($gallery as $item): ?>
                                        <div class="col-4 col-md-3">
                                            <img src="<?= e(url('/files/' . $item['media_id'])) ?>"
                                                 alt="<?= e($item['caption'] ?? $name) ?>" loading="lazy"
                                                 class="w-100" style="aspect-ratio:1;object-fit:cover;border-radius:var(--np-radius-sm)">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php // ── الشهادات ── ?>
                    <?php if ($type === 'certificates' && $certificates !== []): ?>
                        <div class="np-card mb-4">
                            <div class="np-card__header"><?= e($sectionTitle('certificates', 'الشهادات والاعتمادات')) ?></div>
                            <div class="np-card__body">
                                <div class="row g-2">
                                    <?php foreach ($certificates as $item): ?>
                                        <div class="col-6 col-md-4">
                                            <img src="<?= e(url('/files/' . $item['media_id'])) ?>"
                                                 alt="<?= e($item['caption'] ?? 'شهادة') ?>" loading="lazy"
                                                 class="w-100" style="border-radius:var(--np-radius-sm);border:1px solid var(--np-line)">
                                            <?php if (!empty($item['caption'])): ?>
                                                <p class="fs-xs text-muted-np mt-1 mb-0"><?= e($item['caption']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php // ─── نموذج الاستفسار ─── ?>
                <?php if ((int) $page['enable_enquiry_form'] === 1): ?>
                    <div class="np-card mb-4" id="enquiry">
                        <div class="np-card__header">تواصل مع المنشأة</div>
                        <form method="post" action="<?= e(url('/enquiry')) ?>" novalidate data-guard>
                            <div class="np-card__body">
                                <?= csrf_field() ?>
                                <input type="hidden" name="organization_id" value="<?= e((string) $organization['id']) ?>">

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="customer_name">الاسم<span class="required">*</span></label>
                                        <input type="text" class="form-control<?= has_error('customer_name') ? ' is-invalid' : '' ?>"
                                               id="customer_name" name="customer_name" required maxlength="150"
                                               value="<?= e(old('customer_name')) ?>">
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
                                        <input type="email" class="form-control" id="customer_email"
                                               name="customer_email" dir="ltr" value="<?= e(old('customer_email')) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="subject">الموضوع</label>
                                        <input type="text" class="form-control" id="subject" name="subject" maxlength="200">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="message">الرسالة<span class="required">*</span></label>
                                        <textarea class="form-control<?= has_error('message') ? ' is-invalid' : '' ?>"
                                                  id="message" name="message" rows="4" required
                                                  minlength="10" maxlength="2000"><?= e(old('message')) ?></textarea>
                                        <?php if (has_error('message')): ?><div class="invalid-feedback"><?= e(error_for('message')) ?></div><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="np-card__footer text-start">
                                <button type="submit" class="btn btn-primary" data-busy-label="جارٍ الإرسال…">
                                    إرسال الاستفسار
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <?php // ─── نموذج طلب عرض السعر ─── ?>
                <?php if ((int) $page['enable_quote_request'] === 1): ?>
                    <div class="np-card" id="quote">
                        <div class="np-card__header">اطلب عرض سعر</div>
                        <form method="post" action="<?= e(url('/quotations/request')) ?>" novalidate data-guard>
                            <div class="np-card__body">
                                <?= csrf_field() ?>
                                <input type="hidden" name="organization_id" value="<?= e((string) $organization['id']) ?>">

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="q_name">الاسم<span class="required">*</span></label>
                                        <input type="text" class="form-control" id="q_name" name="customer_name"
                                               required maxlength="150">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="q_phone">رقم الهاتف<span class="required">*</span></label>
                                        <input type="tel" class="form-control" id="q_phone" name="customer_phone"
                                               required dir="ltr" placeholder="01012345678">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="q_email">البريد الإلكتروني</label>
                                        <input type="email" class="form-control" id="q_email" name="customer_email" dir="ltr">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label" for="q_quantity">الكمية</label>
                                        <input type="number" class="form-control" id="q_quantity"
                                               name="requested_quantity" min="0" step="0.001" dir="ltr">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label" for="q_needed">مطلوب بحلول</label>
                                        <input type="date" class="form-control" id="q_needed" name="needed_by" dir="ltr">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="q_details">تفاصيل الطلب<span class="required">*</span></label>
                                        <textarea class="form-control" id="q_details" name="request_details"
                                                  rows="4" required minlength="15" maxlength="2000"
                                                  placeholder="اذكر المواصفات والكميات وأي متطلبات خاصة."></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="np-card__footer text-start">
                                <button type="submit" class="btn btn-accent" data-busy-label="جارٍ الإرسال…">
                                    إرسال طلب عرض السعر
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <?php // ─────────── العمود الجانبي ─────────── ?>
            <div class="col-lg-4">
                <?php if (isset($visible['contact'])): ?>
                    <div class="np-card mb-3">
                        <div class="np-card__header"><?= e($sectionTitle('contact', 'بيانات التواصل')) ?></div>
                        <div class="np-card__body">
                            <?php
                            // الخصوصية (§4.3): لا يظهر إلا ما سمح به صاحب المنشأة صراحةً
                            $hasContact = false;
                            ?>
                            <dl class="mb-0 fs-sm">
                                <?php if ((int) $page['show_phone'] === 1 && !empty($organization['public_phone'])): ?>
                                    <?php $hasContact = true; ?>
                                    <dt class="fw-normal text-muted-np">الهاتف</dt>
                                    <dd dir="ltr"><a href="tel:<?= e($organization['public_phone']) ?>"><?= e($organization['public_phone']) ?></a></dd>
                                <?php endif; ?>

                                <?php if ((int) $page['show_email'] === 1 && !empty($organization['public_email'])): ?>
                                    <?php $hasContact = true; ?>
                                    <dt class="fw-normal text-muted-np mt-2">البريد الإلكتروني</dt>
                                    <dd dir="ltr"><a href="mailto:<?= e($organization['public_email']) ?>"><?= e($organization['public_email']) ?></a></dd>
                                <?php endif; ?>

                                <?php if ((int) $page['show_address'] === 1 && !empty($organization['address'])): ?>
                                    <?php $hasContact = true; ?>
                                    <dt class="fw-normal text-muted-np mt-2">العنوان</dt>
                                    <dd><?= e($organization['address']) ?></dd>
                                <?php endif; ?>

                                <?php if (!empty($organization['website'])): ?>
                                    <?php $hasContact = true; ?>
                                    <dt class="fw-normal text-muted-np mt-2">الموقع الإلكتروني</dt>
                                    <dd dir="ltr">
                                        <a href="<?= e($organization['website']) ?>" target="_blank" rel="noopener nofollow">
                                            <?= e($organization['website']) ?>
                                        </a>
                                    </dd>
                                <?php endif; ?>
                            </dl>

                            <?php if (!$hasContact): ?>
                                <p class="text-muted-np fs-sm mb-0">
                                    استخدم نموذج التواصل أعلاه للوصول إلى المنشأة.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($visible['hours']) && ($page['operating_hours'] || $visible['hours']['body'])): ?>
                    <div class="np-card mb-3">
                        <div class="np-card__header"><?= e($sectionTitle('hours', 'مواعيد العمل')) ?></div>
                        <div class="np-card__body">
                            <p class="mb-0 fs-sm" style="white-space:pre-line"><?= e(
                                $visible['hours']['body'] ?: $page['operating_hours']
                            ) ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($reviews !== []): ?>
                    <div class="np-card mb-3">
                        <div class="np-card__header">آراء العملاء</div>
                        <div class="np-card__body p-0">
                            <?php foreach (array_slice($reviews, 0, 5) as $review): ?>
                                <div class="p-3 border-bottom border-np">
                                    <div class="d-flex justify-content-between fs-sm">
                                        <strong><?= e($review['customer_name']) ?></strong>
                                        <span><?= e(str_repeat('★', (int) $review['rating'])) ?></span>
                                    </div>
                                    <?php if (!empty($review['comment'])): ?>
                                        <p class="fs-sm text-muted-np mb-0 mt-1"><?= e(str_excerpt($review['comment'], 140)) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="np-card">
                    <div class="np-card__body fs-sm text-muted-np">
                        <p class="mb-2">
                            هذه الصفحة منشورة عبر منصة رواد النيل، والمنشأة موثّقة من فريق المنصة.
                        </p>
                        <p class="mb-0">
                            واجهت مشكلة مع هذه المنشأة؟
                            <a href="<?= e(url('/contact')) ?>">أبلغ فريق الدعم</a>.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
