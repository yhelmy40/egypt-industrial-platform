<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * الصفحات التعريفية العامة | SME public landing pages (§4.3).
 *
 * قرار المرحلة صفر: **لا محرّر صفحات حرّ**. الصفحة تتكوّن من أقسام مُعرَّفة
 * مسبقاً يمكن ترتيبها وإظهارها وإخفاؤها، وسمة مختارة من قائمة، وألوان علامة.
 * هذا يمنع كسر التصميم أو حقن محتوى تعسّفي، ويُبقي الصفحات متسقة ومقروءة على
 * الهاتف — مع إبقاء الباب مفتوحاً لمحرّر أغنى لاحقاً فوق نفس البيانات.
 *
 * Phase 0 decision: no free-form page builder. A page is a set of predefined
 * sections that can be reordered and toggled, plus a chosen theme and brand
 * colours. This prevents broken layouts and arbitrary content injection while
 * leaving room for a richer editor later over the same data.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('public_pages', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,

            -- الحالة | Publication state
            `status` ENUM('draft','published','unpublished') NOT NULL DEFAULT 'draft',
            `published_at` DATETIME NULL DEFAULT NULL,
            `published_by` INT UNSIGNED NULL,

            -- المظهر | Appearance (from a fixed set — not free-form CSS)
            `theme` VARCHAR(30) NOT NULL DEFAULT 'classic'
                COMMENT 'classic|modern|warm|minimal',
            `primary_color` CHAR(7) NOT NULL DEFAULT '#0b4f8a'
                COMMENT 'لون العلامة، يُتحقق من صيغته قبل الحفظ',
            `cover_media_id` BIGINT UNSIGNED NULL,

            -- المحتوى الرئيسي | Headline content
            `headline` VARCHAR(200) NULL COMMENT 'العنوان الرئيسي على الصفحة',
            `tagline` VARCHAR(300) NULL,
            `story` TEXT NULL COMMENT 'قصة المنشأة أو قصة نجاحها',
            `operating_hours` VARCHAR(500) NULL,

            -- إعدادات الخصوصية (§4.3) | Contact privacy settings
            -- صاحب المنشأة يقرّر ما يظهر للعامة؛ الافتراضي هو الأقل كشفاً.
            `show_phone` TINYINT(1) NOT NULL DEFAULT 0,
            `show_email` TINYINT(1) NOT NULL DEFAULT 0,
            `show_address` TINYINT(1) NOT NULL DEFAULT 0,
            `show_whatsapp` TINYINT(1) NOT NULL DEFAULT 0,
            `enable_enquiry_form` TINYINT(1) NOT NULL DEFAULT 1,
            `enable_quote_request` TINYINT(1) NOT NULL DEFAULT 1,

            -- تحسين الظهور في محركات البحث بالعربية (§4.3)
            `meta_title` VARCHAR(200) NULL,
            `meta_description` VARCHAR(300) NULL,
            `meta_keywords` VARCHAR(300) NULL,

            -- إحصاء بسيط للزيارات | Simple view counter
            `view_count` INT UNSIGNED NOT NULL DEFAULT 0,

            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_public_pages_org` (`organization_id`),
            KEY `idx_public_pages_status` (`status`),
            CONSTRAINT `fk_public_pages_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_public_pages_cover`
                FOREIGN KEY (`cover_media_id`) REFERENCES `media` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_public_pages_publisher`
                FOREIGN KEY (`published_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- أقسام الصفحة | Page sections ---
        $this->create('page_sections', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `public_page_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL COMMENT 'مكرّر عمداً لتبسيط التقييد بالمنشأة',

            -- نوع القسم من مجموعة مغلقة | Section type from a closed set
            `section_type` VARCHAR(40) NOT NULL
                COMMENT 'about|products|services|gallery|certificates|story|hours|contact|social',
            `title` VARCHAR(200) NULL COMMENT 'عنوان مخصّص يكتبه صاحب المنشأة',
            `body` TEXT NULL,

            `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

            -- إعدادات القسم | Section settings as JSON-encoded text
            -- تُقرأ عبر خريطة معروفة ولا تُعرض خاماً في القالب أبداً.
            `settings` TEXT NULL,

            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_page_sections_type` (`public_page_id`, `section_type`),
            KEY `idx_page_sections_order` (`public_page_id`, `sort_order`),
            KEY `idx_page_sections_org` (`organization_id`),
            CONSTRAINT `fk_page_sections_page`
                FOREIGN KEY (`public_page_id`) REFERENCES `public_pages` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_page_sections_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        // --- معرض الصور والشهادات | Gallery and certificates ---
        $this->create('page_media', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,
            `media_id` BIGINT UNSIGNED NOT NULL,
            `collection` ENUM('gallery','certificate') NOT NULL DEFAULT 'gallery',
            `caption` VARCHAR(200) NULL,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            KEY `idx_page_media_org` (`organization_id`, `collection`, `sort_order`),
            CONSTRAINT `fk_page_media_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_page_media_media`
                FOREIGN KEY (`media_id`) REFERENCES `media` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('page_media');
        $this->drop('page_sections');
        $this->drop('public_pages');
    }
};
