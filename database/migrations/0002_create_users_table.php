<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * المستخدمون | Users.
 *
 * المستخدم كيان مستقل عن المنشأة: قد ينتمي لأكثر من منشأة بأدوار مختلفة
 * (§7)، وقد لا ينتمي لأي منشأة إطلاقاً (عميل السوق).
 * A user is independent of any organization: they may belong to several with
 * different roles, or to none at all (marketplace customer).
 *
 * لا تُخزَّن هنا أي بيانات هوية حسّاسة (§10) — لا رقم قومي ولا بيانات بنكية.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('users', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(190) NOT NULL,
            `phone` VARCHAR(30) NULL,
            `password_hash` VARCHAR(255) NOT NULL,

            -- حالة الحساب | Account state
            `status` ENUM('pending','active','suspended','deactivated') NOT NULL DEFAULT 'pending',
            `email_verified_at` DATETIME NULL DEFAULT NULL,
            `phone_verified_at` DATETIME NULL DEFAULT NULL,

            -- إجبار تغيير كلمة المرور (يُستخدم لحسابات العرض التجريبي §15)
            -- Forces a password change; used for demo accounts.
            `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
            `password_changed_at` DATETIME NULL DEFAULT NULL,

            -- تتبّع الدخول | Login tracking (no session identifiers stored)
            `last_login_at` DATETIME NULL DEFAULT NULL,
            `last_login_ip` VARBINARY(16) NULL DEFAULT NULL COMMENT 'عنوان IP مضغوط',
            `failed_login_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `locked_until` DATETIME NULL DEFAULT NULL,

            -- تفضيلات | Preferences
            `locale` VARCHAR(5) NOT NULL DEFAULT 'ar',
            `timezone` VARCHAR(60) NOT NULL DEFAULT 'Africa/Cairo',

            -- المنشأة النشطة الأخيرة (تسهيل فقط — يُعاد التحقق من العضوية دائماً)
            -- Last active organization: a convenience only; membership is
            -- re-validated on every request (§7).
            `last_organization_id` INT UNSIGNED NULL DEFAULT NULL,

            `suspended_at` DATETIME NULL DEFAULT NULL,
            `suspended_reason` VARCHAR(500) NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_users_email` (`email`),
            KEY `idx_users_status` (`status`, `deleted_at`),
            KEY `idx_users_phone` (`phone`)
        SQL);
    }

    public function down(): void
    {
        $this->drop('users');
    }
};
