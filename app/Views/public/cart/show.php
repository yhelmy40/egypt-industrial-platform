<?php
/**
 * سلة المشتريات | Cart (§4.4).
 * تُعرض مجمّعة حسب البائع لأنها ستنقسم إلى طلب لكل بائع عند الدفع.
 * @var array{groups:array,totals:array,count:int} $contents
 */
?>
<section class="container py-4">
    <h1 class="h4 mb-3">سلة المشتريات</h1>

    <?php if ($contents['count'] === 0): ?>
        <div class="np-card">
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">🛒</div>
                <p class="mb-3">سلة المشتريات فارغة.</p>
                <a class="btn btn-primary" href="<?= e(url('/marketplace')) ?>">تصفّح السوق</a>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info fs-sm" role="alert">
            سلتك تضم أصنافاً من <?= e(number_ar(count($contents['groups']))) ?> منشأة.
            سيُنشأ <strong>طلب منفصل لكل منشأة</strong> لأن كل منشأة تجهّز وتسلّم طلبها بنفسها.
        </div>

        <div class="row g-3">
            <div class="col-lg-8">
                <?php foreach ($contents['groups'] as $group): ?>
                    <div class="np-card mb-3">
                        <div class="np-card__header">
                            <a href="<?= e(url('/business/' . $group['seller_slug'])) ?>">
                                <?= e($group['seller_name']) ?>
                            </a>
                            <span class="fs-sm text-muted-np fw-normal">
                                <?= e(number_ar(count($group['items']))) ?> صنف
                            </span>
                        </div>
                        <div class="np-card__body p-0">
                            <?php foreach ($group['items'] as $item): ?>
                                <div class="p-3 border-bottom border-np">
                                    <div class="d-flex gap-3 align-items-start">
                                        <?php if (!empty($item['media_id'])): ?>
                                            <img src="<?= e(url('/files/' . $item['media_id'])) ?>" alt=""
                                                 width="64" height="64" loading="lazy"
                                                 style="width:64px;height:64px;object-fit:cover;border-radius:var(--np-radius-sm)">
                                        <?php endif; ?>

                                        <div class="flex-grow-1">
                                            <a href="<?= e(url('/marketplace/' . $item['slug'])) ?>" class="fw-bold">
                                                <?= e($item['name']) ?>
                                            </a>
                                            <p class="fs-sm text-muted-np mb-1">
                                                <?= e($item['unit_price']->format()) ?>
                                                <?php if (!empty($item['unit'])): ?>/ <?= e($item['unit']) ?><?php endif; ?>
                                            </p>

                                            <?php if ($item['issue'] !== null): ?>
                                                <p class="fs-sm text-danger mb-0">⚠ <?= e($item['issue']) ?></p>
                                            <?php endif; ?>
                                        </div>

                                        <div class="text-start">
                                            <form method="post" action="<?= e(url('/cart/update')) ?>" class="d-flex gap-1 mb-1">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                                                <label class="visually-hidden" for="qty<?= e((string) $item['id']) ?>">الكمية</label>
                                                <input type="number" class="form-control form-control-sm"
                                                       id="qty<?= e((string) $item['id']) ?>" name="quantity"
                                                       value="<?= e((string) $item['quantity']) ?>" min="0" step="0.001"
                                                       style="width:5.5rem" dir="ltr">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">تحديث</button>
                                            </form>

                                            <div class="fw-bold numeric"><?= e($item['line_total']->format()) ?></div>

                                            <form method="post" action="<?= e(url('/cart/remove')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                                                <button type="submit" class="btn btn-sm btn-link text-danger p-0">إزالة</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="np-card__footer d-flex justify-content-between fs-sm">
                            <span>إجمالي أصناف هذه المنشأة</span>
                            <span class="fw-bold numeric"><?= e($group['subtotal']->format()) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="col-lg-4">
                <div class="np-card">
                    <div class="np-card__header">ملخّص الطلب</div>
                    <div class="np-card__body">
                        <dl class="mb-0">
                            <div class="d-flex justify-content-between py-1">
                                <dt class="fw-normal text-muted-np">إجمالي الأصناف</dt>
                                <dd class="mb-0 numeric"><?= e($contents['totals']['subtotal']) ?></dd>
                            </div>
                            <div class="d-flex justify-content-between py-1">
                                <dt class="fw-normal text-muted-np">ضريبة القيمة المضافة</dt>
                                <dd class="mb-0 numeric"><?= e($contents['totals']['vat']) ?></dd>
                            </div>
                            <div class="d-flex justify-content-between py-1">
                                <dt class="fw-normal text-muted-np">رسوم التوصيل</dt>
                                <dd class="mb-0 numeric"><?= e($contents['totals']['delivery']) ?></dd>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <dt>الإجمالي</dt>
                                <dd class="mb-0 fw-bold numeric text-primary"><?= e($contents['totals']['total']) ?></dd>
                            </div>
                        </dl>
                    </div>
                    <div class="np-card__footer">
                        <a class="btn btn-primary w-100" href="<?= e(url('/checkout')) ?>">متابعة إتمام الطلب</a>
                        <a class="btn btn-link w-100 text-muted-np" href="<?= e(url('/marketplace')) ?>">
                            متابعة التسوّق
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
