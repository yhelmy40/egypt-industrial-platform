<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * سجل التدقيق وإعدادات النظام | Audit log and system settings (§9, §3.1).
 *
 * سجل التدقيق غير قابل للتعديل من التطبيق: لا توجد دوال تحديث أو حذف عليه،
 * ويُحتفظ به وفق مدة الاحتفاظ المُعدّة (افتراضياً 3 سنوات).
 * The audit log is append-only from the application's point of view: no update
 * or delete path exists, and rows are retained per the configured period.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('audit_logs', <<<'SQL'
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            -- الفاعل | Actor (null = إجراء تلقائي من النظام)
            `user_id` INT UNSIGNED NULL,
            `organization_id` INT UNSIGNED NULL COMMENT 'المنشأة النشطة وقت الإجراء',
            `impersonated_by` INT UNSIGNED NULL COMMENT 'للاستخدام المستقبلي',

            -- الحدث | Event
            `action` VARCHAR(80) NOT NULL COMMENT 'auth.login|org.account.verify|order.status_change…',
            `category` VARCHAR(40) NOT NULL COMMENT 'auth|rbac|verification|order|application|finance|document|export|config|record',
            `severity` ENUM('info','notice','warning','critical') NOT NULL DEFAULT 'info',

            -- الهدف | Target entity
            `entity_type` VARCHAR(60) NULL,
            `entity_id` BIGINT UNSIGNED NULL,

            -- التغيير | Change payload (يُنقَّح من الحقول الحسّاسة قبل الحفظ)
            `changes` TEXT NULL COMMENT 'JSON: {before:{},after:{}} بعد التنقيح',
            `description` VARCHAR(500) NULL COMMENT 'وصف عربي للعرض',

            -- السياق | Request context
            `ip_address` VARBINARY(16) NULL,
            `user_agent` VARCHAR(255) NULL,
            `route` VARCHAR(190) NULL,
            `method` VARCHAR(10) NULL,

            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_audit_logs_user` (`user_id`, `created_at`),
            KEY `idx_audit_logs_org` (`organization_id`, `created_at`),
            KEY `idx_audit_logs_action` (`action`, `created_at`),
            KEY `idx_audit_logs_category` (`category`, `created_at`),
            KEY `idx_audit_logs_entity` (`entity_type`, `entity_id`),
            KEY `idx_audit_logs_created` (`created_at`),
            CONSTRAINT `fk_audit_logs_user`
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_audit_logs_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- إعدادات النظام | System settings ---
        // إعدادات قابلة للتعديل من لوحة الإدارة دون نشر كود.
        $this->create('system_settings', <<<SQL
            `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `group_key` VARCHAR(40) NOT NULL COMMENT 'general|marketplace|privacy|matching|notifications',
            `setting_key` VARCHAR(80) NOT NULL,
            `value` TEXT NULL,
            `value_type` ENUM('string','integer','decimal','boolean','json') NOT NULL DEFAULT 'string',
            `label_ar` VARCHAR(200) NOT NULL,
            `description_ar` VARCHAR(500) NULL,
            -- إعداد عام يمكن قراءته دون مصادقة (مثل اسم المنصة)
            `is_public` TINYINT(1) NOT NULL DEFAULT 0,
            `updated_by` INT UNSIGNED NULL,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_system_settings_key` (`group_key`, `setting_key`),
            KEY `idx_system_settings_public` (`is_public`),
            CONSTRAINT `fk_system_settings_updated_by`
                FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('system_settings');
        $this->drop('audit_logs');
    }
};
