<?php
/** الإشعارات | Notifications list */
$pageTitle = 'الإشعارات';
$hasUnread = false;
foreach ($notifications as $n) { if (empty($n['is_read'])) { $hasUnread = true; break; } }
?>

<div class="page-head d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="section-title mb-1">الإشعارات</h2>
        <p class="text-muted small mb-0">آخر التنبيهات الخاصة بحسابك</p>
    </div>
    <?php if ($hasUnread): ?>
        <form method="post" action="<?= url('notification/readAll') ?>">
            <?= Csrf::field() ?>
            <button class="btn btn-outline-navy btn-sm">✓ تعليم الكل كمقروء</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($notifications)): ?>
            <div class="empty-state p-5"><div class="ico">🔔</div><p>لا توجد إشعارات.</p></div>
        <?php else: ?>
            <div class="notif-list">
                <?php foreach ($notifications as $n): ?>
                    <div class="notif-item <?= empty($n['is_read']) ? 'unread' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong><?= e($n['title']) ?></strong>
                                <p class="mb-1 text-muted small"><?= e($n['message']) ?></p>
                                <small class="text-muted"><?= fmt_date($n['created_at'], true) ?></small>
                            </div>
                            <?php if (empty($n['is_read'])): ?>
                                <form method="post" action="<?= url('notification/read/' . $n['id']) ?>">
                                    <?= Csrf::field() ?>
                                    <button class="btn btn-teal btn-sm">تعليم كمقروء</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
