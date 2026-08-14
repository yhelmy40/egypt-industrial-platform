<?php
/**
 * استفسارات العملاء | Customer enquiry inbox (§4.4).
 *
 * @var array<int,array<string,mixed>> $enquiries
 * @var array<string,int> $counts
 * @var array<string,string> $filters
 */

$tabs = ['' => 'الكل', 'new' => 'جديد', 'read' => 'مقروء', 'replied' => 'تم الرد',
         'converted' => 'تحوّل إلى طلب', 'closed' => 'مغلق'];

$statusBadge = static fn (string $status): array => match ($status) {
    'new'       => ['np-badge--pending', 'جديد'],
    'read'      => ['np-badge--review', 'مقروء'],
    'replied'   => ['np-badge--success', 'تم الرد'],
    'converted' => ['np-badge--success', 'تحوّل إلى طلب'],
    'spam'      => ['np-badge--danger', 'رسالة مزعجة'],
    default     => ['np-badge--muted', 'مغلق'],
};
?>
<div class="d-flex flex-wrap gap-1 mb-3">
    <?php foreach ($tabs as $value => $label): ?>
        <?php $count = $value === '' ? array_sum($counts) : ($counts[$value] ?? 0); ?>
        <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
           href="<?= e(url('/app/enquiries') . ($value === '' ? '' : '?status=' . $value)) ?>">
            <?= e($label) ?> <span class="np-badge np-badge--muted ms-1"><?= e(number_ar($count)) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($enquiries === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">✉️</div>
                <p class="mb-1">لا توجد استفسارات.</p>
                <p class="fs-sm mb-0">يصل إليك هنا كل استفسار يرسله زوّار صفحتك العامة.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>العميل</th><th>الموضوع</th><th>الصنف</th><th>الحالة</th><th>التاريخ</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($enquiries as $enquiry): ?>
                            <?php [$badgeClass, $badgeLabel] = $statusBadge((string) $enquiry['status']); ?>
                            <tr>
                                <td>
                                    <a class="<?= $enquiry['status'] === 'new' ? 'fw-bold' : '' ?>"
                                       href="<?= e(url('/app/enquiries/' . $enquiry['id'])) ?>">
                                        <?= e($enquiry['customer_name']) ?></a>
                                    <div class="fs-xs text-muted-np numeric" dir="ltr">
                                        <?= e($enquiry['customer_phone'] ?? '') ?></div>
                                </td>
                                <td class="fs-sm"><?= e(str_excerpt($enquiry['subject'] ?? $enquiry['message'], 60)) ?></td>
                                <td class="fs-sm text-muted-np"><?= e($enquiry['listing_name'] ?? '—') ?></td>
                                <td><span class="np-badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span></td>
                                <td class="fs-xs text-muted-np"><?= e(time_ago($enquiry['created_at'])) ?></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/app/enquiries/' . $enquiry['id'])) ?>">فتح</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
