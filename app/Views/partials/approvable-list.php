<?php
/**
 * قائمة عناصر خاضعة للاعتماد | Approval-gated catalogue list (§4.5, §4.6).
 *
 * تخدم منتجات المؤسسة المالية وباقات مقدّم الخدمة معاً: نفس دورة الحياة، نفس
 * الحالات، نفس الرسالة التي يحتاج المزوّد أن يفهمها — «المسودة لا يراها أحد،
 * والمنشور اعتمدته المنصة».
 *
 * @var array<int,array<string,mixed>> $items
 * @var array<string,int> $counts
 * @var array<string,string> $filters
 * @var \App\Services\ApprovableCatalogService $service
 * @var string $basePath
 * @var string $newLabel
 * @var string $emptyMessage
 * @var callable $summaryLine  دالة تُرجع سطر الملخّص لكل عنصر
 */
$tabs = ['' => 'الكل', 'draft' => 'مسودة', 'pending_review' => 'بانتظار الاعتماد',
         'published' => 'معتمد', 'rejected' => 'مرفوض', 'archived' => 'مسحوب'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-1">
        <?php foreach ($tabs as $value => $label): ?>
            <?php $count = $value === '' ? array_sum($counts) : ($counts[$value] ?? 0); ?>
            <a class="btn btn-sm <?= $filters['status'] === $value ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= e(url($basePath) . ($value === '' ? '' : '?status=' . $value)) ?>">
                <?= e($label) ?> <span class="np-badge np-badge--muted ms-1"><?= e(number_ar($count)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <a class="btn btn-primary" href="<?= e(url($basePath . '/new')) ?>">+ <?= e($newLabel) ?></a>
</div>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($items === []): ?>
            <div class="np-empty">
                <div class="np-empty__icon" aria-hidden="true">📄</div>
                <p class="mb-3"><?= e($emptyMessage) ?></p>
                <a class="btn btn-primary" href="<?= e(url($basePath . '/new')) ?>"><?= e($newLabel) ?></a>
            </div>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <div class="p-3 border-bottom border-np">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div class="flex-grow-1">
                            <a class="fw-bold" href="<?= e(url($basePath . '/' . $item['id'])) ?>">
                                <?= e($item['name_ar']) ?></a>
                            <span class="np-badge <?= e($service->statusBadgeClass((string) $item['status'])) ?>">
                                <?= e($service->statusLabel((string) $item['status'])) ?></span>

                            <div class="fs-sm text-muted-np mt-1"><?= e($summaryLine($item)) ?></div>

                            <?php if ($item['status'] === 'rejected' && !empty($item['moderation_note'])): ?>
                                <div class="alert alert-warning fs-sm mt-2 mb-0">
                                    <strong>سبب الرفض:</strong> <?= e($item['moderation_note']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($item['status'] === 'published' && !empty($item['approved_at'])): ?>
                                <div class="fs-xs text-muted-np mt-1">
                                    اعتُمد في <?= e(format_date($item['approved_at'])) ?></div>
                            <?php endif; ?>
                        </div>

                        <a class="btn btn-sm btn-outline-primary"
                           href="<?= e(url($basePath . '/' . $item['id'])) ?>">تعديل</a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-info fs-sm mt-3 mb-0">
    المسودة لا يراها أحد خارج جهتك. بعد الإرسال يراجعها فريق المنصة، ولا تظهر للمشروعات
    إلا بعد الاعتماد. أي تعديل على عنصر معتمد يُعيده للمراجعة من جديد.
</div>
