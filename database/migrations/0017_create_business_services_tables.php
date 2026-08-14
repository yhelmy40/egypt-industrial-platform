<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * الخدمات غير المالية | Non-financial business services (§4.6).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * نفس قاعدة الاعتماد المطبَّقة على المنتجات التمويلية تسري هنا: **أي باقة خدمة
 * يعرضها مقدّم خدمة أو منظمة أهلية لا تظهر للعامة قبل اعتماد المنصة.** مقدّم
 * الخدمة يُدخل الباقة ويرسلها، والاعتماد قرار موثّق بفاعله وتاريخه.
 *
 * The same approval rule as financing products applies: no service package
 * from a provider or NGO reaches the public without a recorded platform
 * approval.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * لماذا جدول مستقل عن `listings`؟ الخدمة هنا ليست سلعة تُشترى من السلة: لها
 * مسار طلب ← عرض ← تنفيذ بمراحل، وقد تكون مجانية ضمن برنامج تنموي، وقد
 * يمولها طرف ثالث. حشرها في كتالوج السوق كان سيخلط مسارين مختلفين تماماً.
 */
return new class extends Migration {
    public function up(): void
    {
        // --- باقات الخدمات | Service offerings ---
        $this->create('service_offerings', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL COMMENT 'مقدّم الخدمة أو المنظمة',
            `category_id` SMALLINT UNSIGNED NULL COMMENT 'تصنيف من نوع service',

            `name_ar` VARCHAR(200) NOT NULL,
            `slug` VARCHAR(220) NOT NULL,
            `short_description` VARCHAR(500) NULL,
            `description` TEXT NULL,

            `service_type` ENUM(
                'consulting','training','technical','marketing','legal',
                'accounting','design','digital','certification','other'
            ) NOT NULL DEFAULT 'consulting',

            `delivery_mode` ENUM('onsite','remote','hybrid') NOT NULL DEFAULT 'hybrid',
            `duration_note_ar` VARCHAR(300) NULL COMMENT 'مدة التنفيذ كما يصفها المزوّد',

            -- التسعير | Pricing
            `pricing_mode` ENUM('fixed','range','quote','free') NOT NULL DEFAULT 'quote',
            `price_from` DECIMAL(14,2) NULL,
            `price_to` DECIMAL(14,2) NULL,
            `currency_code` CHAR(3) NOT NULL DEFAULT 'EGP',
            -- الخدمة المجانية ضمن برنامج تنموي: من يتحمّل التكلفة يُذكر صراحةً
            -- حتى لا يُفهم المجاني على أنه تبرّع من المنصة.
            `funded_by_ar` VARCHAR(200) NULL COMMENT 'الجهة الممولة إن كانت الخدمة مجانية',

            `target_audience_ar` VARCHAR(1000) NULL,
            `deliverables_ar` VARCHAR(2000) NULL,

            -- نطاق التغطية | Coverage
            `covers_all_governorates` TINYINT(1) NOT NULL DEFAULT 1,
            `governorate_ids` VARCHAR(500) NULL COMMENT 'قائمة معرّفات مفصولة بفواصل',
            `sector_ids` VARCHAR(500) NULL,

            -- الاعتماد | Approval
            `status` ENUM('draft','pending_review','published','rejected','archived')
                NOT NULL DEFAULT 'draft',
            `moderation_note` VARCHAR(1000) NULL,
            `approved_by` INT UNSIGNED NULL,
            `approved_at` DATETIME NULL DEFAULT NULL,
            `published_at` DATETIME NULL DEFAULT NULL,

            `is_demo` TINYINT(1) NOT NULL DEFAULT 0,

            `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `rating_average` DECIMAL(3,2) NULL,
            `rating_count` INT UNSIGNED NOT NULL DEFAULT 0,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_service_offerings_slug` (`slug`),
            KEY `idx_service_offerings_org` (`organization_id`, `status`, `deleted_at`),
            KEY `idx_service_offerings_browse` (`status`, `service_type`, `category_id`, `deleted_at`),
            FULLTEXT KEY `ft_service_offerings` (`name_ar`, `short_description`, `description`),
            CONSTRAINT `fk_service_offerings_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_service_offerings_category`
                FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_service_offerings_approver`
                FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- طلبات الخدمة | Service requests ---
        $this->create('service_requests', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `request_number` VARCHAR(30) NOT NULL,

            `organization_id` INT UNSIGNED NOT NULL COMMENT 'المشروع طالب الخدمة',
            `provider_organization_id` INT UNSIGNED NOT NULL,
            `service_offering_id` INT UNSIGNED NULL,
            `offering_name_ar` VARCHAR(200) NOT NULL COMMENT 'نسخة مجمّدة وقت الطلب',

            `details_ar` VARCHAR(2000) NOT NULL,
            `preferred_start_date` DATE NULL,
            `contact_person` VARCHAR(150) NULL,
            `contact_phone` VARCHAR(30) NULL,

            `status` ENUM(
                'submitted','provider_review','proposed','accepted','declined',
                'in_progress','delivered','completed','cancelled'
            ) NOT NULL DEFAULT 'submitted',

            -- عرض المزوّد | The provider's proposal
            `proposed_price` DECIMAL(14,2) NULL,
            `proposed_currency` CHAR(3) NOT NULL DEFAULT 'EGP',
            `proposed_duration_days` SMALLINT UNSIGNED NULL,
            `proposal_note_ar` VARCHAR(2000) NULL,
            `proposed_by` INT UNSIGNED NULL,
            `proposed_at` DATETIME NULL DEFAULT NULL,
            `responded_at` DATETIME NULL DEFAULT NULL COMMENT 'قبول أو رفض المشروع',

            -- الإحالة من المنصة أو من مركز تطوير أعمال (§4.6)
            `referred_by_user_id` INT UNSIGNED NULL,
            `referred_by_organization_id` INT UNSIGNED NULL,

            `completed_at` DATETIME NULL DEFAULT NULL,
            `tracking_token` CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_service_requests_number` (`request_number`),
            UNIQUE KEY `uq_service_requests_token` (`tracking_token`),
            KEY `idx_service_requests_applicant` (`organization_id`, `status`, `created_at`),
            KEY `idx_service_requests_provider` (`provider_organization_id`, `status`, `created_at`),
            KEY `idx_service_requests_offering` (`service_offering_id`),
            CONSTRAINT `fk_service_requests_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_service_requests_provider`
                FOREIGN KEY (`provider_organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_service_requests_offering`
                FOREIGN KEY (`service_offering_id`) REFERENCES `service_offerings` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_service_requests_proposer`
                FOREIGN KEY (`proposed_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_service_requests_referrer`
                FOREIGN KEY (`referred_by_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_service_requests_referrer_org`
                FOREIGN KEY (`referred_by_organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- مراحل التنفيذ | Delivery milestones ---
        $this->create('service_milestones', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `service_request_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL COMMENT 'المشروع المستفيد',
            `provider_organization_id` INT UNSIGNED NOT NULL,

            `title_ar` VARCHAR(200) NOT NULL,
            `description_ar` VARCHAR(1000) NULL,
            `due_date` DATE NULL,
            `status` ENUM('pending','in_progress','done','skipped') NOT NULL DEFAULT 'pending',
            `completed_at` DATETIME NULL DEFAULT NULL,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

            {$this->timestamps()},

            PRIMARY KEY (`id`),
            KEY `idx_service_milestones_request` (`service_request_id`, `sort_order`),
            KEY `idx_service_milestones_org` (`organization_id`),
            KEY `idx_service_milestones_provider` (`provider_organization_id`),
            CONSTRAINT `fk_service_milestones_request`
                FOREIGN KEY (`service_request_id`) REFERENCES `service_requests` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_service_milestones_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_service_milestones_provider`
                FOREIGN KEY (`provider_organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        // --- سجل حالات طلب الخدمة | Service request history ---
        $this->create('service_request_history', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `service_request_id` INT UNSIGNED NOT NULL,
            `from_status` VARCHAR(24) NULL,
            `to_status` VARCHAR(24) NOT NULL,
            `actor_user_id` INT UNSIGNED NULL,
            `actor_type` ENUM('applicant','platform','provider','system')
                NOT NULL DEFAULT 'system',
            `note_ar` VARCHAR(1000) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_service_history_request` (`service_request_id`, `created_at`),
            CONSTRAINT `fk_service_history_request`
                FOREIGN KEY (`service_request_id`) REFERENCES `service_requests` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_service_history_actor`
                FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('service_request_history');
        $this->drop('service_milestones');
        $this->drop('service_requests');
        $this->drop('service_offerings');
    }
};
