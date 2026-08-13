<?php
/**
 * الإشعارات | Notifications (§4.11).
 * @var array<int,array<string,mixed>> $notifications
 * @var int $unreadCount
 */

$severityBadges = [
    'info'    => 'np-badge--info',
    'success' => 'np-badge--success',
    'warning' => 'np-badge--warning',
    'danger'  => 'np-badge--danger',
];
?>
<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="np-card">
            <div class="np-card__header">
                الإشعارات
                <?php if ($unreadCount > 0): ?>
                    <form method="post" action="<?= e(url('/app/notifications/read-all')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                            تعليم الكل كمقروء (<?= e(number_ar($unreadCount)) ?>)
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="np-card__body p-0">
                <?php if ($notifications === []): ?>
                    <div class="np-empty">
                        <div class="np-empty__icon" aria-hidden="true">🔔</div>
                        <p class="mb-0">لا توجد إشعارات بعد.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <?php $isUnread = $notification['read_at'] === null; ?>
                        <div class="p-3 border-bottom border-np<?= $isUnread ? ' bg-canvas' : '' ?>">
                            <div class="d-flex justify-content-between gap-3 align-items-start">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="np-badge <?= e($severityBadges[$notification['severity']] ?? 'np-badge--info') ?>">
                                            <?= $isUnread ? 'جديد' : 'مقروء' ?>
                                        </span>
                                        <strong><?= e($notification['title']) ?></strong>
                                    </div>
                                    <p class="fs-sm text-muted-np mb-1"><?= e($notification['body']) ?></p>
                                    <span class="fs-xs text-muted-np"><?= e(time_ago($notification['created_at'])) ?></span>
                                </div>

                                <div class="d-flex flex-column gap-2 align-items-end">
                                    <?php if (!empty($notification['action_url'])): ?>
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="<?= e($notification['action_url']) ?>">
                                            <?= e($notification['action_label'] ?? __('common.view')) ?>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($isUnread): ?>
                                        <form method="post"
                                              action="<?= e(url('/app/notifications/' . $notification['id'] . '/read')) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-link text-muted-np p-0">
                                                تعليم كمقروء
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
