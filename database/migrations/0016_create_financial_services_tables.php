<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * الخدمات المالية | Financial services (§4.5).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * قاعدتان تحكمان تصميم هذه الجداول، وكلتاهما شرط صريح في المواصفة:
 *
 *  1. **لا يظهر منتج تمويلي للعامة إلا باعتماد المنصة.** المؤسسة المالية تُدخل
 *     منتجها بنفسها وترسله للاعتماد، ولا يُنشر قبل قرار موثّق من فريق المنصة.
 *     لذلك `financing_products.status` آلة حالة، وأعمدة `approved_by`
 *     و`approved_at` و`moderation_note` جزء من الجدول لا سجلّ جانبي.
 *
 *  2. **لا يُعرض طلب تمويل كمقبول إلا إذا سجّل المزوّد المسؤول القبول.** المنصة
 *     تفرز وتحيل فقط؛ عمود `decided_by` يشير دائماً إلى مستخدم من المؤسسة
 *     المالية، ولا مسار في التطبيق يكتب `approved` دون تعبئته.
 *
 * Two rules drive this schema, both explicit requirements: no financing product
 * reaches the public without a recorded platform approval, and no application is
 * ever shown as approved unless the responsible provider recorded that decision.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ملاحظة خصوصية (§10): لا يُجمع الرقم القومي ولا أرقام الحسابات البنكية هنا.
 * ما يُجمع هو ما يلزم للفرز فقط: نشاط المنشأة، المبلغ المطلوب، الغرض، وتقدير
 * الإيراد. أي مستند إضافي يطلبه المزوّد يمرّ عبر سجل الوسائط الخاص المفوَّض.
 */
return new class extends Migration {
    public function up(): void
    {
        // --- المنتجات التمويلية | Financing products ---
        $this->create('financing_products', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL COMMENT 'المؤسسة المالية المالكة',
            `category_id` SMALLINT UNSIGNED NULL COMMENT 'تصنيف من نوع financial',

            `name_ar` VARCHAR(200) NOT NULL,
            `slug` VARCHAR(220) NOT NULL,
            `short_description` VARCHAR(500) NULL,
            `description` TEXT NULL,

            `financing_type` ENUM(
                'working_capital','asset_finance','microfinance','trade_finance',
                'leasing','grant','equity','other'
            ) NOT NULL DEFAULT 'working_capital',

            -- الشريحة المالية | Amount band
            `min_amount` DECIMAL(14,2) NULL,
            `max_amount` DECIMAL(14,2) NULL,
            `currency_code` CHAR(3) NOT NULL DEFAULT 'EGP',

            -- المدة والتكلفة | Tenor and cost
            `min_tenor_months` SMALLINT UNSIGNED NULL,
            `max_tenor_months` SMALLINT UNSIGNED NULL,
            -- النسبة معروضة كما صرّح بها المزوّد، ونصّها الحر يشرح أساس الحساب.
            -- لا تحسب المنصة قسطاً ولا جدول سداد: ذلك اختصاص المزوّد وحده.
            `rate_note_ar` VARCHAR(500) NULL COMMENT 'وصف التكلفة كما صرّح بها المزوّد',
            `fees_note_ar` VARCHAR(500) NULL,

            -- شروط الأهلية المعروضة | Displayed eligibility conditions
            `eligibility_summary_ar` VARCHAR(2000) NULL,
            `required_documents_ar` VARCHAR(2000) NULL,

            -- قواعد فرز آلي بسيطة | Simple screening rules (guidance only)
            -- تُستخدم لترتيب الاقتراحات وتنبيه المشروع، ولا تُنتج قراراً ولا
            -- تقييماً ائتمانياً — وذلك ممنوع صراحةً في هذه النسخة (§14).
            `min_years_in_business` TINYINT UNSIGNED NULL,
            `min_annual_revenue` DECIMAL(14,2) NULL,
            `requires_formal_registration` TINYINT(1) NOT NULL DEFAULT 0,
            `eligible_governorate_ids` VARCHAR(500) NULL COMMENT 'قائمة معرّفات مفصولة بفواصل، فارغة = كل المحافظات',
            `eligible_sector_ids` VARCHAR(500) NULL,

            -- الاعتماد | Approval (rule 1 above)
            `status` ENUM('draft','pending_review','published','rejected','archived')
                NOT NULL DEFAULT 'draft',
            `moderation_note` VARCHAR(1000) NULL COMMENT 'سبب الرفض يصل للمؤسسة',
            `approved_by` INT UNSIGNED NULL COMMENT 'مستخدم من فريق المنصة',
            `approved_at` DATETIME NULL DEFAULT NULL,
            `published_at` DATETIME NULL DEFAULT NULL,

            -- وسم البيانات التجريبية (§15)
            `is_demo` TINYINT(1) NOT NULL DEFAULT 0,

            `application_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `view_count` INT UNSIGNED NOT NULL DEFAULT 0,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_financing_products_slug` (`slug`),
            KEY `idx_financing_products_org` (`organization_id`, `status`, `deleted_at`),
            KEY `idx_financing_products_browse` (`status`, `financing_type`, `deleted_at`),
            KEY `idx_financing_products_amount` (`status`, `min_amount`, `max_amount`),
            FULLTEXT KEY `ft_financing_products` (`name_ar`, `short_description`, `description`),
            CONSTRAINT `fk_financing_products_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_financing_products_category`
                FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_financing_products_approver`
                FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- طلبات التمويل | Financing applications ---
        $this->create('financing_applications', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `application_number` VARCHAR(30) NOT NULL,

            -- الطرفان | Both sides, each scoped independently
            `organization_id` INT UNSIGNED NOT NULL COMMENT 'المشروع مقدّم الطلب',
            `provider_organization_id` INT UNSIGNED NOT NULL COMMENT 'المؤسسة المالية',
            `financing_product_id` INT UNSIGNED NULL COMMENT 'قد يُؤرشف المنتج والطلب يبقى',

            -- نسخة مجمّدة من اسم المنتج وقت التقديم | Frozen product name
            `product_name_ar` VARCHAR(200) NOT NULL,

            `requested_amount` DECIMAL(14,2) NOT NULL,
            `currency_code` CHAR(3) NOT NULL DEFAULT 'EGP',
            `requested_tenor_months` SMALLINT UNSIGNED NULL,
            `purpose_ar` VARCHAR(2000) NOT NULL,

            -- بيانات الفرز | Screening inputs, self-declared by the applicant
            `declared_annual_revenue` DECIMAL(14,2) NULL,
            `declared_employees` SMALLINT UNSIGNED NULL,
            `years_in_business` TINYINT UNSIGNED NULL,

            `status` ENUM(
                'draft','submitted','screening','forwarded','provider_review',
                'info_requested','approved','rejected','withdrawn','cancelled'
            ) NOT NULL DEFAULT 'draft',

            -- الفرز على مستوى المنصة | Platform screening (routing only)
            `screened_by` INT UNSIGNED NULL,
            `screened_at` DATETIME NULL DEFAULT NULL,
            `screening_note` VARCHAR(1000) NULL COMMENT 'ملاحظة داخلية لا تُعرض للمشروع',

            -- القرار من المزوّد وحده | The provider's decision (rule 2 above)
            `decided_by` INT UNSIGNED NULL COMMENT 'مستخدم من المؤسسة المالية',
            `decided_at` DATETIME NULL DEFAULT NULL,
            `decision_note_ar` VARCHAR(2000) NULL COMMENT 'يصل للمشروع',
            `approved_amount` DECIMAL(14,2) NULL,
            `approved_tenor_months` SMALLINT UNSIGNED NULL,

            `submitted_at` DATETIME NULL DEFAULT NULL,
            `tracking_token` CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_financing_applications_number` (`application_number`),
            UNIQUE KEY `uq_financing_applications_token` (`tracking_token`),
            KEY `idx_financing_applications_applicant` (`organization_id`, `status`, `created_at`),
            KEY `idx_financing_applications_provider` (`provider_organization_id`, `status`, `created_at`),
            KEY `idx_financing_applications_product` (`financing_product_id`),
            CONSTRAINT `fk_financing_applications_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_financing_applications_provider`
                FOREIGN KEY (`provider_organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_financing_applications_product`
                FOREIGN KEY (`financing_product_id`) REFERENCES `financing_products` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_financing_applications_screener`
                FOREIGN KEY (`screened_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_financing_applications_decider`
                FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- مستندات الطلب | Application documents ---
        // المزوّد يطلب مستنداً باسمه، والمشروع يرفعه. الملف نفسه في `media`
        // بمرئية `private`، فلا يُقدَّم إلا عبر المتحكّم المفوِّض.
        $this->create('financing_application_documents', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `application_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL COMMENT 'المشروع صاحب الطلب — للتقييد',
            `provider_organization_id` INT UNSIGNED NOT NULL COMMENT 'المزوّد المصرَّح له بالاطلاع',

            `label_ar` VARCHAR(200) NOT NULL COMMENT 'ما طلبه المزوّد',
            `note_ar` VARCHAR(500) NULL,
            `is_required` TINYINT(1) NOT NULL DEFAULT 1,
            `requested_by` INT UNSIGNED NULL,

            `media_id` BIGINT UNSIGNED NULL COMMENT 'فارغ حتى يرفع المشروع',
            `uploaded_at` DATETIME NULL DEFAULT NULL,
            `uploaded_by` INT UNSIGNED NULL,

            {$this->timestamps()},

            PRIMARY KEY (`id`),
            KEY `idx_financing_docs_application` (`application_id`),
            KEY `idx_financing_docs_org` (`organization_id`),
            KEY `idx_financing_docs_provider` (`provider_organization_id`),
            CONSTRAINT `fk_financing_docs_application`
                FOREIGN KEY (`application_id`) REFERENCES `financing_applications` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_financing_docs_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_financing_docs_provider`
                FOREIGN KEY (`provider_organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_financing_docs_media`
                FOREIGN KEY (`media_id`) REFERENCES `media` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_financing_docs_requester`
                FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_financing_docs_uploader`
                FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- سجل حالات الطلب | Application history (append-only) ---
        $this->create('financing_application_history', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `application_id` INT UNSIGNED NOT NULL,
            `from_status` VARCHAR(24) NULL,
            `to_status` VARCHAR(24) NOT NULL,
            `actor_user_id` INT UNSIGNED NULL,
            `actor_organization_id` INT UNSIGNED NULL,
            `actor_type` ENUM('applicant','platform','provider','system')
                NOT NULL DEFAULT 'system',
            -- ما يراه المشروع | Shown to the applicant
            `note_ar` VARCHAR(1000) NULL,
            -- ما لا يراه المشروع | Never shown to the applicant
            `internal_note_ar` VARCHAR(1000) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_financing_history_application` (`application_id`, `created_at`),
            CONSTRAINT `fk_financing_history_application`
                FOREIGN KEY (`application_id`) REFERENCES `financing_applications` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_financing_history_actor`
                FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('financing_application_history');
        $this->drop('financing_application_documents');
        $this->drop('financing_applications');
        $this->drop('financing_products');
    }
};
