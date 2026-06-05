<?php
/** الشريط العلوي | Top bar */
$u = Auth::user();
$initial = mb_substr($u['name'] ?? '؟', 0, 1, 'UTF-8');

// عدّاد الإشعارات غير المقروءة | unread notifications count
require_once APP_PATH . '/models/Notification.php';
$unread = (new Notification())->unreadCount(Auth::id());

$pt = $pageTitle ?? 'لوحة المعلومات';
?>
<header class="topbar">
    <div class="d-flex align-items-center gap-2">
        <button class="btn-burger" id="btnBurger" aria-label="القائمة">☰</button>
        <h1 class="page-title"><?= e($pt) ?></h1>
    </div>

    <div class="topbar-actions">
        <a href="<?= url('notification') ?>" class="bell" title="الإشعارات">
            🔔
            <?php if ($unread > 0): ?>
                <span class="count"><?= $unread > 99 ? '99+' : $unread ?></span>
            <?php endif; ?>
        </a>

        <div class="dropdown">
            <a href="#" class="user-chip text-decoration-none text-dark" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="avatar"><?= e($initial) ?></span>
                <span class="d-none d-md-block text-end">
                    <strong style="font-size:13px"><?= e($u['name']) ?></strong>
                    <small><?= e(Auth::roleLabel()) ?></small>
                </span>
            </a>
            <ul class="dropdown-menu dropdown-menu-start">
                <li><span class="dropdown-item-text small text-muted"><?= e($u['email']) ?></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= url('notification') ?>">الإشعارات</a></li>
                <li><a class="dropdown-item text-danger" href="<?= url('auth/logout') ?>">تسجيل الخروج</a></li>
            </ul>
        </div>
    </div>
</header>
