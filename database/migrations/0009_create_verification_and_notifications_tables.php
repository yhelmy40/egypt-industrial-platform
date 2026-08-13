<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * سجل قرارات التوثيق والإشعارات | Verification history and notifications.
 *
 * `organization_verifications` سجل غير قابل للتعديل لكل انتقال حالة في مسار
 * التوثيق: من أرسل، ومن راجع، ومتى، وبأي سبب. حالة المنشأة الحالية في
 * `organizations.status`، وهذا الجدول يحفظ **كيف وصلت إليها** — وهو ما يجعل
 * قرار التوثيق قابلاً للمراجعة والتظلّم (§9).
 * An append-only record of every verification transition: who submitted, who
 * reviewed, when, and why. The current status lives on the organization; this
 * table preserves how it got there, which is what makes a decision auditable
 * and appealable.
 *
 * `notifications` إشعارات داخل المنصة (§4.11). تُنشأ عند كل قرار توثيق حتى
 * يعرف صاحب المنشأة بالنتيجة دون انتظار البريد — الذي قد لا يكون مُفعَّلاً بعد.
 */
return new class extends Migration {
    public function up(): void
    {
        // --- سجل قرارات التوثيق | Verification decision history ---
        $this->create('organization_verifications', <<<'SQL'
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,

            -- الانتقال | The transition
            `from_status` VARCHAR(30) NOT NULL,
            `to_status` VARCHAR(30) NOT NULL,
            `action` VARCHAR(30) NOT NULL
                COMMENT 'submit|start_review|request_info|approve|reject|suspend|reinstate',

            -- الفاعل | Actor (null = إجراء نظام)
            `actor_user_id` INT UNSIGNED NULL,
            `actor_role` VARCHAR(50) NULL COMMENT 'دور الفاعل وقت القرار',

            -- المبرّر | Justification
            `reason` VARCHAR(1000) NULL COMMENT 'يظهر لصاحب المنشأة',
            `internal_note` VARCHAR(1000) NULL COMMENT 'ملاحظة داخلية لفريق المراجعة',

            -- لقطة من بيانات المراجعة وقت القرار | Snapshot at decision time
            `completion_score` TINYINT UNSIGNED NULL,
            `documents_accepted` SMALLINT UNSIGNED NULL,
            `documents_pending` SMALLINT UNSIGNED NULL,

            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_org_verifications_org` (`organization_id`, `created_at`),
            KEY `idx_org_verifications_actor` (`actor_user_id`),
            KEY `idx_org_verifications_action` (`action`, `created_at`),
            CONSTRAINT `fk_org_verifications_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_org_verifications_actor`
                FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- الإشعارات داخل المنصة | In-app notifications ---
        $this->create('notifications', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            -- المستقبِل | Recipient
            `user_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NULL COMMENT 'سياق المنشأة إن وُجد',

            `type` VARCHAR(60) NOT NULL
                COMMENT 'organization.verified|organization.rejected|document.rejected…',
            `severity` ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',

            `title` VARCHAR(200) NOT NULL,
            `body` VARCHAR(1000) NULL,
            `action_url` VARCHAR(255) NULL,
            `action_label` VARCHAR(80) NULL,

            -- الكيان المرتبط | Related entity
            `entity_type` VARCHAR(60) NULL,
            `entity_id` BIGINT UNSIGNED NULL,

            `read_at` DATETIME NULL DEFAULT NULL,
            {$this->timestamps()},

            PRIMARY KEY (`id`),
            KEY `idx_notifications_user` (`user_id`, `read_at`, `created_at`),
            KEY `idx_notifications_org` (`organization_id`, `created_at`),
            KEY `idx_notifications_type` (`type`),
            CONSTRAINT `fk_notifications_user`
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_notifications_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('notifications');
        $this->drop('organization_verifications');
    }
};
