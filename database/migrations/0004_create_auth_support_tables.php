<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * جداول دعم المصادقة | Authentication support tables (§9).
 *
 * ملاحظة أمنية: تُخزَّن رموز الاستعادة والتحقق مجزّأة (SHA-256) وليس نصاً
 * صريحاً، حتى لا يتمكن من يقرأ قاعدة البيانات من انتحال المستخدمين.
 * Security note: reset/verification tokens are stored hashed (SHA-256), never
 * in clear text, so database read access alone cannot be used to impersonate.
 */
return new class extends Migration {
    public function up(): void
    {
        // --- محاولات تسجيل الدخول | Login attempts ---
        // تُستخدم لكشف الهجمات وللقفل المؤقت. لا تُخزَّن كلمة المرور المُجرَّبة.
        $this->create('login_attempts', <<<'SQL'
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `identifier` VARCHAR(190) NOT NULL COMMENT 'البريد المُدخل',
            `ip_address` VARBINARY(16) NOT NULL,
            `user_agent` VARCHAR(255) NULL,
            `successful` TINYINT(1) NOT NULL DEFAULT 0,
            `user_id` INT UNSIGNED NULL,
            `failure_reason` VARCHAR(60) NULL COMMENT 'invalid_credentials|locked|suspended|unverified',
            `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_login_attempts_identifier` (`identifier`, `attempted_at`),
            KEY `idx_login_attempts_ip` (`ip_address`, `attempted_at`),
            KEY `idx_login_attempts_user` (`user_id`, `attempted_at`),
            CONSTRAINT `fk_login_attempts_user`
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- استعادة كلمة المرور | Password resets ---
        $this->create('password_resets', <<<'SQL'
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `token_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 للرمز المُرسل',
            `requested_ip` VARBINARY(16) NULL,
            `expires_at` DATETIME NOT NULL,
            `used_at` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_password_resets_token` (`token_hash`),
            KEY `idx_password_resets_user` (`user_id`, `used_at`),
            CONSTRAINT `fk_password_resets_user`
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        // --- تحقق البريد/الهاتف | Email & phone verifications ---
        $this->create('verification_tokens', <<<'SQL'
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `channel` ENUM('email','phone') NOT NULL DEFAULT 'email',
            `destination` VARCHAR(190) NOT NULL COMMENT 'البريد أو الهاتف وقت الإرسال',
            `token_hash` CHAR(64) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `verified_at` DATETIME NULL DEFAULT NULL,
            `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_verification_tokens_token` (`token_hash`),
            KEY `idx_verification_tokens_user` (`user_id`, `channel`, `verified_at`),
            CONSTRAINT `fk_verification_tokens_user`
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        // --- تحديد المعدّل | Rate limiting buckets ---
        // نافذة منزلقة مخزّنة في قاعدة البيانات (لا يوجد Redis في هذه النسخة).
        $this->create('rate_limits', <<<'SQL'
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `bucket` VARCHAR(60) NOT NULL COMMENT 'اسم القاعدة: login|register|…',
            `signature` VARCHAR(190) NOT NULL COMMENT 'IP أو IP+معرّف',
            `hit_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_rate_limits_lookup` (`bucket`, `signature`, `hit_at`)
        SQL);

        // --- الموافقات | Consent records (§10) ---
        // نسخة السياسة ووقتها إلزاميان حتى يمكن إثبات ما وافق عليه المستخدم.
        $this->create('user_consents', <<<'SQL'
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `consent_type` VARCHAR(50) NOT NULL COMMENT 'terms|privacy|marketing|data_sharing',
            `policy_version` VARCHAR(20) NOT NULL,
            `granted` TINYINT(1) NOT NULL DEFAULT 1,
            `ip_address` VARBINARY(16) NULL,
            `user_agent` VARCHAR(255) NULL,
            `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `revoked_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user_consents_user` (`user_id`, `consent_type`, `granted_at`),
            CONSTRAINT `fk_user_consents_user`
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('user_consents');
        $this->drop('rate_limits');
        $this->drop('verification_tokens');
        $this->drop('password_resets');
        $this->drop('login_attempts');
    }
};
