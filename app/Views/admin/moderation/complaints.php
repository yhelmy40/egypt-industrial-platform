<?php
/**
 * الشكاوى والنزاعات | Complaints and disputed orders (§4.4).
 *
 * شاشة اطّلاع وإحالة في هذه المرحلة: معالجة الشكوى نفسها تتم على الطلب أو مع
 * المنشأة، والمنصة لا تسجّل قراراً نيابةً عن أحد.
 *
 * @var array<int,array<string,mixed>> $complaints
 * @var array<int,array<string,mixed>> $disputed
 * @var array<string,string> $filters
 */

$tabs = ['new' => 'جديدة', 'under_review' => 'قيد الفحص', 'awaiting_response' => 'بانتظار رد',
         'resolved' => 'محلولة', 'rejected' => 'مرفوضة', 'escalated' => 'مُصعَّدة', '' => 'الكل'];

$categoryLabel = static fn (string $category): string => match ($category) {
    'product_quality' => 'جودة المنتج',
    'delivery'        => 'التسليم',
    'pricing'         => 'التسعير',
    'conduct'         => 'سلوك التعامل',
    'listing_content' => 'محتوى الإعلان',
    default           => 'أخرى',
};

$statusBadge = static fn (string $status): string => match ($status) {
    'new'               => 'np-badge--pending',
    'under_review'      => 'np-badge--review',
    'awaiting_response' => 'np-badge--info',
    'resolved'          => 'np-badge--success',
    'escalated'         => 'np-badge--danger',
    default             => 'np-badge--muted',
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-1">
        <?php foreach ($tabs as $value => $label): ?>
            <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= e(url('/admin/complaints') . ($value === '' ? '?status=' : '?status=' . $value)) ?>">
                <?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/moderation')) ?>">مراجعة الإعلانات ←</a>
</div>

<?php // ─── الطلبات محل النزاع ─── ?>
<div class="np-card mb-3">
    <div class="np-card__header">
        الطلبات محل النزاع
        <span class="fs-sm text-muted-np fw-normal"><?= e(number_ar(count($disputed))) ?></span>
    </div>
    <div class="np-card__body p-0">
        <?php if ($disputed === []): ?>
            <div class="np-empty py-4"><p class="fs-sm mb-0">لا توجد طلبات محل نزاع.</p></div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>رقم الطلب</th><th>المنشأة</th><th>العميل</th><th>الإجمالي</th><th>منذ</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($disputed as $order): ?>
                            <tr>
                                <td class="numeric fw-bold" dir="ltr"><?= e($order['order_number']) ?></td>
                                <td class="fs-sm"><?= e($order['trading_name'] ?: $order['legal_name']) ?></td>
                                <td class="fs-sm"><?= e($order['customer_name']) ?></td>
                                <td class="numeric fs-sm"><?= e(money((float) $order['total'])) ?></td>
                                <td class="fs-xs text-muted-np"><?= e(time_ago($order['updated_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php // ─── الشكاوى ─── ?>
<div class="np-card">
    <div class="np-card__header">الشكاوى</div>
    <div class="np-card__body p-0">
        <?php if ($complaints === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">🗂️</div>
                <p class="mb-0">لا توجد شكاوى في هذه القائمة.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>الرقم</th><th>الموضوع</th><th>التصنيف</th><th>المنشأة</th>
                        <th>الطلب</th><th>الحالة</th><th>التاريخ</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($complaints as $complaint): ?>
                            <tr>
                                <td class="numeric fs-sm" dir="ltr"><?= e($complaint['complaint_number']) ?></td>
                                <td>
                                    <div class="fs-sm fw-bold"><?= e($complaint['subject']) ?></div>
                                    <div class="fs-xs text-muted-np">
                                        <?= e(str_excerpt($complaint['details'], 90)) ?></div>
                                </td>
                                <td class="fs-sm"><?= e($categoryLabel((string) $complaint['category'])) ?></td>
                                <td class="fs-sm">
                                    <?= e($complaint['trading_name'] ?: ($complaint['legal_name'] ?? '—')) ?></td>
                                <td class="fs-xs numeric" dir="ltr"><?= e($complaint['order_number'] ?? '—') ?></td>
                                <td>
                                    <span class="np-badge <?= e($statusBadge((string) $complaint['status'])) ?>">
                                        <?= e(match ((string) $complaint['status']) {
                                            'new'               => 'جديدة',
                                            'under_review'      => 'قيد الفحص',
                                            'awaiting_response' => 'بانتظار رد',
                                            'resolved'          => 'محلولة',
                                            'rejected'          => 'مرفوضة',
                                            'escalated'         => 'مُصعَّدة',
                                            default             => (string) $complaint['status'],
                                        }) ?></span>
                                </td>
                                <td class="fs-xs text-muted-np"><?= e(time_ago($complaint['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<p class="fs-xs text-muted-np mt-3">
    شاشة اطّلاع وإحالة. تحديث حالة الشكوى وتسجيل القرار يأتيان مع وحدة الدعم في مرحلة لاحقة،
    ولا تُعرض شكوى على أنها «محلولة» ما لم يسجّل ذلك مسؤول مختصّ.
</p>
