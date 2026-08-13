<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * المنشآت والعضويات | Organizations and memberships (§7).
 *
 * `organizations` هو جذر العزل بين المستأجرين: كل سجل أعمال في المنصة يحمل
 * organization_id ويُقيَّد به على مستوى المستودعات والسياسات.
 * `organizations` is the tenant root: every business record carries an
 * organization_id and is scoped by it at the repository and policy layers.
 *
 * العضوية هي المصدر الوحيد للحقيقة بشأن "هل يحق لهذا المستخدم العمل باسم هذه
 * المنشأة؟" — ولا يُقبل organization_id قادم من المتصفح إطلاقاً.
 * Membership is the single source of truth for "may this user act for this
 * organization?" — an organization_id from the browser is never trusted.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('organizations', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_type_id` TINYINT UNSIGNED NOT NULL,

            -- الهوية | Identity
            `legal_name` VARCHAR(200) NOT NULL COMMENT 'الاسم القانوني',
            `trading_name` VARCHAR(200) NULL COMMENT 'الاسم التجاري',
            `slug` VARCHAR(150) NOT NULL COMMENT 'المعرّف في /business/{slug}',
            `logo_path` VARCHAR(255) NULL,

            -- حالة التسجيل | Registration status (§4.2)
            `status` ENUM(
                'draft','submitted','under_review','more_info_required',
                'verified','rejected','suspended'
            ) NOT NULL DEFAULT 'draft',
            `verified_at` DATETIME NULL DEFAULT NULL,
            `verified_by` INT UNSIGNED NULL,
            `rejection_reason` VARCHAR(1000) NULL,
            `suspended_at` DATETIME NULL DEFAULT NULL,
            `suspension_reason` VARCHAR(1000) NULL,

            -- التصنيف | Classification
            `sector_id` SMALLINT UNSIGNED NULL,
            `sub_sector_id` INT UNSIGNED NULL,
            `governorate_id` SMALLINT UNSIGNED NULL,
            `city_id` INT UNSIGNED NULL,

            -- الاتصال العام | Public contact
            `address` VARCHAR(500) NULL,
            `public_email` VARCHAR(190) NULL,
            `public_phone` VARCHAR(30) NULL,
            `website` VARCHAR(255) NULL,

            -- الوصف | Descriptions
            `short_description` VARCHAR(500) NULL,
            `description` TEXT NULL,

            -- مالك الحساب | Primary owner (also present in organization_members)
            `owner_user_id` INT UNSIGNED NULL,

            -- نسبة اكتمال الملف (§4.2) | Profile completion score 0-100
            `completion_score` TINYINT UNSIGNED NOT NULL DEFAULT 0,

            -- وسم البيانات التجريبية (§15) | Demo-data flag
            `is_demo` TINYINT(1) NOT NULL DEFAULT 0,

            `submitted_at` DATETIME NULL DEFAULT NULL,
            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_organizations_slug` (`slug`),
            KEY `idx_organizations_status` (`status`, `deleted_at`),
            KEY `idx_organizations_type_status` (`organization_type_id`, `status`),
            KEY `idx_organizations_sector` (`sector_id`, `status`),
            KEY `idx_organizations_governorate` (`governorate_id`, `status`),
            KEY `idx_organizations_owner` (`owner_user_id`),
            CONSTRAINT `fk_organizations_type`
                FOREIGN KEY (`organization_type_id`) REFERENCES `organization_types` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT `fk_organizations_sector`
                FOREIGN KEY (`sector_id`) REFERENCES `sectors` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_organizations_sub_sector`
                FOREIGN KEY (`sub_sector_id`) REFERENCES `sub_sectors` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_organizations_governorate`
                FOREIGN KEY (`governorate_id`) REFERENCES `governorates` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_organizations_city`
                FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_organizations_owner`
                FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_organizations_verified_by`
                FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- العضويات | Memberships ---
        $this->create('organization_members', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `role_id` SMALLINT UNSIGNED NOT NULL COMMENT 'دور بنطاق organization',
            `job_title` VARCHAR(120) NULL,
            `status` ENUM('invited','active','suspended','removed') NOT NULL DEFAULT 'active',
            `is_primary_contact` TINYINT(1) NOT NULL DEFAULT 0,
            `invited_by` INT UNSIGNED NULL,
            `joined_at` DATETIME NULL DEFAULT NULL,
            `removed_at` DATETIME NULL DEFAULT NULL,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            -- مستخدم واحد لا يُسجَّل مرتين في نفس المنشأة
            UNIQUE KEY `uq_organization_members` (`organization_id`, `user_id`),
            KEY `idx_organization_members_user` (`user_id`, `status`),
            KEY `idx_organization_members_role` (`role_id`),
            CONSTRAINT `fk_organization_members_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_organization_members_user`
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_organization_members_role`
                FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT `fk_organization_members_invited_by`
                FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- تجاوزات صلاحيات العضو | Per-member permission overrides (§3.5) ---
        // تسمح لمالك المنشأة بمنح/منع صلاحية بعينها لموظف دون تغيير دوره.
        $this->create('organization_member_permissions', <<<'SQL'
            `member_id` INT UNSIGNED NOT NULL,
            `permission_id` SMALLINT UNSIGNED NOT NULL,
            `effect` ENUM('grant','deny') NOT NULL DEFAULT 'grant',
            `granted_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`member_id`, `permission_id`),
            KEY `idx_member_permissions_permission` (`permission_id`),
            CONSTRAINT `fk_member_permissions_member`
                FOREIGN KEY (`member_id`) REFERENCES `organization_members` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_member_permissions_permission`
                FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_member_permissions_granted_by`
                FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- دعوات الانضمام | Invitations ---
        $this->create('organization_invitations', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,
            `email` VARCHAR(190) NOT NULL,
            `role_id` SMALLINT UNSIGNED NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `invited_by` INT UNSIGNED NOT NULL,
            `status` ENUM('pending','accepted','expired','revoked') NOT NULL DEFAULT 'pending',
            `expires_at` DATETIME NOT NULL,
            `accepted_at` DATETIME NULL DEFAULT NULL,
            `accepted_user_id` INT UNSIGNED NULL,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_organization_invitations_token` (`token_hash`),
            KEY `idx_organization_invitations_org` (`organization_id`, `status`),
            KEY `idx_organization_invitations_email` (`email`, `status`),
            CONSTRAINT `fk_organization_invitations_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_organization_invitations_role`
                FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT `fk_organization_invitations_inviter`
                FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_organization_invitations_accepted_user`
                FOREIGN KEY (`accepted_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // مفتاح خارجي مؤجّل: users.last_organization_id → organizations.id
        // Deferred FK: added here because organizations did not exist in 0002.
        $this->run(
            'ALTER TABLE `users`
             ADD CONSTRAINT `fk_users_last_organization`
             FOREIGN KEY (`last_organization_id`) REFERENCES `organizations` (`id`)
             ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        $this->run('ALTER TABLE `users` DROP FOREIGN KEY `fk_users_last_organization`');
        $this->drop('organization_invitations');
        $this->drop('organization_member_permissions');
        $this->drop('organization_members');
        $this->drop('organizations');
    }
};
