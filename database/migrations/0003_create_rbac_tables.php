<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * الأدوار والصلاحيات | Roles and permissions (§3, §6).
 *
 * تصميم ذو مستويين | Two-tier design:
 *  - أدوار على مستوى المنصة (scope='platform') تُسند عبر user_roles
 *    → super_admin، ops_officer، marketplace_customer
 *  - أدوار على مستوى المنشأة (scope='organization') تُسند عبر
 *    organization_members → sme_owner، bank_officer، bds_specialist …
 *
 * هذا ما يجعل "المستخدم قد ينتمي لأكثر من منشأة بأدوار مختلفة" ممكناً (§7).
 */
return new class extends Migration {
    public function up(): void
    {
        // --- الأدوار | Roles ---
        $this->create('roles', <<<SQL
            `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(50) NOT NULL,
            `name_ar` VARCHAR(120) NOT NULL,
            `name_en` VARCHAR(120) NULL,
            `description_ar` VARCHAR(500) NULL,
            `scope` ENUM('platform','organization') NOT NULL,
            -- نوع المنشأة الذي ينتمي إليه الدور (لأدوار المنشآت فقط)
            `organization_type_code` VARCHAR(30) NULL,
            -- الأدوار النظامية لا يمكن حذفها من لوحة الإدارة
            -- System roles cannot be deleted from the admin UI.
            `is_system` TINYINT(1) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_roles_code` (`code`),
            KEY `idx_roles_scope` (`scope`, `is_active`)
        SQL);

        // --- الصلاحيات | Permissions ---
        // التسمية: {module}.{resource}.{action}
        $this->create('permissions', <<<SQL
            `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(80) NOT NULL COMMENT 'module.resource.action',
            `module` VARCHAR(40) NOT NULL,
            `name_ar` VARCHAR(150) NOT NULL,
            `description_ar` VARCHAR(500) NULL,
            -- صلاحية حسّاسة: تُسجَّل كل ممارسة لها في سجل التدقيق
            `is_sensitive` TINYINT(1) NOT NULL DEFAULT 0,
            -- هل يمكن لمالك المنشأة منحها لموظفيه؟ (§3.5)
            `assignable_by_owner` TINYINT(1) NOT NULL DEFAULT 0,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_permissions_code` (`code`),
            KEY `idx_permissions_module` (`module`)
        SQL);

        // --- ربط الأدوار بالصلاحيات | Role ↔ permission ---
        $this->create('role_permissions', <<<'SQL'
            `role_id` SMALLINT UNSIGNED NOT NULL,
            `permission_id` SMALLINT UNSIGNED NOT NULL,
            `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `granted_by` INT UNSIGNED NULL,
            PRIMARY KEY (`role_id`, `permission_id`),
            KEY `idx_role_permissions_permission` (`permission_id`),
            CONSTRAINT `fk_role_permissions_role`
                FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_role_permissions_permission`
                FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_role_permissions_granted_by`
                FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- أدوار المنصة للمستخدمين | Platform-scope user roles ---
        $this->create('user_roles', <<<'SQL'
            `user_id` INT UNSIGNED NOT NULL,
            `role_id` SMALLINT UNSIGNED NOT NULL,
            `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `assigned_by` INT UNSIGNED NULL,
            PRIMARY KEY (`user_id`, `role_id`),
            KEY `idx_user_roles_role` (`role_id`),
            CONSTRAINT `fk_user_roles_user`
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_user_roles_role`
                FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT `fk_user_roles_assigned_by`
                FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('user_roles');
        $this->drop('role_permissions');
        $this->drop('permissions');
        $this->drop('roles');
    }
};
