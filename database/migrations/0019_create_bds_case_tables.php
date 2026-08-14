<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * إدارة حالات مراكز تطوير الأعمال | BDS centre case management (§4.8).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * الحالة هنا علاقة بين طرفين: **المشروع** المستفيد و**المركز** الذي يدعمه. كل
 * جدول يحمل العمودين معاً (`organization_id` للمشروع و`center_organization_id`
 * للمركز) لأن كليهما يقرأ ويكتب، وكل استعلام يُقيَّد على عمود الجهة القارئة.
 *
 * القاعدة الأهم في هذه المرحلة، وهي شرط صريح في المواصفة (§10):
 * **ملاحظات الأخصائي الداخلية لا يراها المشروع إطلاقاً.**
 *
 * لذلك لم تُبنَ الملاحظات بعمود منطقي واحد يُفلتَر في القالب، بل بحقل
 * `visibility` يُفلتَر في المستودع نفسه: الاستعلام الذي يقرأ نيابةً عن المشروع
 * لا يجلب الصف الداخلي أصلاً. الحجب في الاستعلام لا في العرض، فلا يكفي أن ينسى
 * مطوّر شرطاً في قالب ليتسرّب تقييم صريح كتبه أخصائي عن مشروع.
 *
 * A case is a relationship between two sides, and internal specialist notes are
 * filtered in the repository, not the template: the query that reads on the
 * SME's behalf never fetches the internal row at all.
 * ═══════════════════════════════════════════════════════════════════════════
 */
return new class extends Migration {
    public function up(): void
    {
        // --- حالات الدعم | Support cases ---
        $this->create('bds_cases', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `case_number` VARCHAR(30) NOT NULL,

            `organization_id` INT UNSIGNED NOT NULL COMMENT 'المشروع المستفيد',
            `center_organization_id` INT UNSIGNED NOT NULL COMMENT 'مركز تطوير الأعمال',

            -- الأخصائي المسؤول | The assigned specialist
            `assigned_to` INT UNSIGNED NULL,
            `assigned_at` DATETIME NULL DEFAULT NULL,
            `assigned_by` INT UNSIGNED NULL,

            `title_ar` VARCHAR(200) NOT NULL,
            `request_details_ar` VARCHAR(2000) NOT NULL COMMENT 'ما كتبه المشروع عند الطلب',

            -- مجالات الاحتياج، مشتقّة من مجالات التقييم (§4.7)
            `focus_areas` VARCHAR(200) NULL
                COMMENT 'finance|market|operations|digital|compliance|skills — مفصولة بفواصل',
            `assessment_id` INT UNSIGNED NULL COMMENT 'التقييم الذي بُني عليه الطلب إن وُجد',

            `status` ENUM(
                'requested','triage','assigned','in_progress','on_hold',
                'closed_completed','closed_referred','closed_unreachable','cancelled'
            ) NOT NULL DEFAULT 'requested',

            `priority` ENUM('low','normal','high') NOT NULL DEFAULT 'normal',

            -- الإغلاق | Closure
            `closed_at` DATETIME NULL DEFAULT NULL,
            `closed_by` INT UNSIGNED NULL,
            `outcome_summary_ar` VARCHAR(2000) NULL COMMENT 'ملخّص يراه المشروع',

            -- تقييم المشروع للخدمة بعد الإغلاق | The SME's rating after closure
            `satisfaction_rating` TINYINT UNSIGNED NULL COMMENT 'من 1 إلى 5',
            `satisfaction_comment_ar` VARCHAR(1000) NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_bds_cases_number` (`case_number`),
            KEY `idx_bds_cases_org` (`organization_id`, `status`, `created_at`),
            KEY `idx_bds_cases_center` (`center_organization_id`, `status`, `created_at`),
            KEY `idx_bds_cases_specialist` (`assigned_to`, `status`),
            CONSTRAINT `fk_bds_cases_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_cases_center`
                FOREIGN KEY (`center_organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_cases_assignee`
                FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_cases_assigner`
                FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_cases_closer`
                FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_cases_assessment`
                FOREIGN KEY (`assessment_id`) REFERENCES `needs_assessments` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- ملاحظات الحالة | Case notes ---
        // `visibility` هو الفارق بين مساحة عمل الأخصائي وما يصل المشروع.
        // الاستعلام الذي يقرأ للمشروع يشترط 'shared'، فالصف الداخلي لا يُجلب أصلاً.
        $this->create('bds_case_notes', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `case_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL COMMENT 'المشروع — للتقييد',
            `center_organization_id` INT UNSIGNED NOT NULL,

            `visibility` ENUM('internal','shared') NOT NULL DEFAULT 'internal'
                COMMENT 'internal = لا يراها المشروع إطلاقاً',
            `body_ar` VARCHAR(3000) NOT NULL,

            `author_user_id` INT UNSIGNED NULL,
            -- من كتبها: الأخصائي أو المشروع نفسه (ردّ على ملاحظة مشتركة)
            `author_side` ENUM('center','organization') NOT NULL DEFAULT 'center',

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            KEY `idx_bds_notes_case` (`case_id`, `visibility`, `created_at`),
            KEY `idx_bds_notes_org` (`organization_id`),
            CONSTRAINT `fk_bds_notes_case`
                FOREIGN KEY (`case_id`) REFERENCES `bds_cases` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_notes_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_notes_center`
                FOREIGN KEY (`center_organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_notes_author`
                FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- الجلسات الاستشارية | Consultation sessions ---
        $this->create('bds_consultations', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `case_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL,
            `center_organization_id` INT UNSIGNED NOT NULL,

            `title_ar` VARCHAR(200) NOT NULL,
            `scheduled_at` DATETIME NOT NULL,
            `duration_minutes` SMALLINT UNSIGNED NULL,
            -- لا مؤتمرات فيديو داخل المنصة (§14): الموعد يُسجَّل ورابط اللقاء
            -- يُتفق عليه خارجها، ولذلك لا يوجد عمود لرابط جلسة.
            `mode` ENUM('onsite','phone','online') NOT NULL DEFAULT 'onsite',
            `location_ar` VARCHAR(300) NULL,

            `status` ENUM('scheduled','completed','no_show','cancelled')
                NOT NULL DEFAULT 'scheduled',

            -- ملخّص يراه المشروع | Summary the SME sees
            `summary_ar` VARCHAR(2000) NULL,
            -- ملاحظة الأخصائي الخاصة | The specialist's private note
            `internal_note_ar` VARCHAR(2000) NULL,

            `specialist_user_id` INT UNSIGNED NULL,
            `completed_at` DATETIME NULL DEFAULT NULL,

            {$this->timestamps()},

            PRIMARY KEY (`id`),
            KEY `idx_bds_consultations_case` (`case_id`, `scheduled_at`),
            KEY `idx_bds_consultations_org` (`organization_id`),
            KEY `idx_bds_consultations_center` (`center_organization_id`, `scheduled_at`),
            CONSTRAINT `fk_bds_consultations_case`
                FOREIGN KEY (`case_id`) REFERENCES `bds_cases` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_consultations_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_consultations_center`
                FOREIGN KEY (`center_organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_consultations_specialist`
                FOREIGN KEY (`specialist_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- خطط العمل ومهامها | Action plans and their tasks ---
        // الخطة مشتركة بطبيعتها: لا معنى لخطة عمل لا يراها من سينفّذها.
        $this->create('bds_action_plans', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `case_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL,
            `center_organization_id` INT UNSIGNED NOT NULL,

            `title_ar` VARCHAR(200) NOT NULL,
            `objective_ar` VARCHAR(2000) NULL,
            `starts_on` DATE NULL,
            `ends_on` DATE NULL,

            `status` ENUM('draft','active','completed','abandoned') NOT NULL DEFAULT 'draft',
            `shared_at` DATETIME NULL DEFAULT NULL COMMENT 'متى صارت مرئية للمشروع',

            `created_by` INT UNSIGNED NULL,

            {$this->timestamps()},

            PRIMARY KEY (`id`),
            KEY `idx_bds_plans_case` (`case_id`, `status`),
            KEY `idx_bds_plans_org` (`organization_id`),
            KEY `idx_bds_plans_center` (`center_organization_id`),
            CONSTRAINT `fk_bds_plans_case`
                FOREIGN KEY (`case_id`) REFERENCES `bds_cases` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_plans_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_plans_center`
                FOREIGN KEY (`center_organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_plans_author`
                FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        $this->create('bds_plan_tasks', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plan_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL,
            `center_organization_id` INT UNSIGNED NOT NULL,

            `title_ar` VARCHAR(200) NOT NULL,
            `description_ar` VARCHAR(1000) NULL,
            -- من ينفّذ: المشروع أم المركز | Who carries the task
            `owner_side` ENUM('organization','center') NOT NULL DEFAULT 'organization',
            `due_date` DATE NULL,

            `status` ENUM('pending','in_progress','done','skipped') NOT NULL DEFAULT 'pending',
            `completed_at` DATETIME NULL DEFAULT NULL,
            `completed_by` INT UNSIGNED NULL,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

            {$this->timestamps()},

            PRIMARY KEY (`id`),
            KEY `idx_bds_tasks_plan` (`plan_id`, `sort_order`),
            KEY `idx_bds_tasks_org` (`organization_id`, `status`),
            CONSTRAINT `fk_bds_tasks_plan`
                FOREIGN KEY (`plan_id`) REFERENCES `bds_action_plans` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_tasks_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_tasks_center`
                FOREIGN KEY (`center_organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_tasks_completer`
                FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- الإحالات | Referrals ---
        // المركز يحيل المشروع إلى منتج تمويلي أو باقة خدمة معتمدة. الإحالة
        // **توصية لا التزام**: لا تُنشئ طلباً ولا تلزم الجهة المُحال إليها بشيء،
        // والقرار يبقى للمشروع أولاً ثم للجهة (§4.5).
        $this->create('bds_referrals', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `case_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL COMMENT 'المشروع المُحال',
            `center_organization_id` INT UNSIGNED NOT NULL COMMENT 'المركز المُحيل',

            `target_type` ENUM('financing_product','service_offering','organization')
                NOT NULL,
            `target_id` INT UNSIGNED NOT NULL,
            `target_name_ar` VARCHAR(200) NOT NULL COMMENT 'نسخة مجمّدة وقت الإحالة',

            `reason_ar` VARCHAR(1000) NOT NULL COMMENT 'لماذا يناسب المشروع — يراه المشروع',

            `status` ENUM('suggested','accepted','declined','acted') NOT NULL DEFAULT 'suggested',
            `responded_at` DATETIME NULL DEFAULT NULL,
            `response_note_ar` VARCHAR(500) NULL,

            `referred_by` INT UNSIGNED NULL,

            {$this->timestamps()},

            PRIMARY KEY (`id`),
            KEY `idx_bds_referrals_case` (`case_id`, `status`),
            KEY `idx_bds_referrals_org` (`organization_id`, `status`),
            KEY `idx_bds_referrals_target` (`target_type`, `target_id`),
            CONSTRAINT `fk_bds_referrals_case`
                FOREIGN KEY (`case_id`) REFERENCES `bds_cases` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_referrals_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_referrals_center`
                FOREIGN KEY (`center_organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_referrals_author`
                FOREIGN KEY (`referred_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- سجل حالات الدعم | Case history (append-only) ---
        $this->create('bds_case_history', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `case_id` INT UNSIGNED NOT NULL,
            `from_status` VARCHAR(24) NULL,
            `to_status` VARCHAR(24) NOT NULL,
            `actor_user_id` INT UNSIGNED NULL,
            `actor_side` ENUM('organization','center','platform','system')
                NOT NULL DEFAULT 'system',
            `note_ar` VARCHAR(1000) NULL COMMENT 'يراه الطرفان',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_bds_history_case` (`case_id`, `created_at`),
            CONSTRAINT `fk_bds_history_case`
                FOREIGN KEY (`case_id`) REFERENCES `bds_cases` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_bds_history_actor`
                FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('bds_case_history');
        $this->drop('bds_referrals');
        $this->drop('bds_plan_tasks');
        $this->drop('bds_action_plans');
        $this->drop('bds_consultations');
        $this->drop('bds_case_notes');
        $this->drop('bds_cases');
    }
};
