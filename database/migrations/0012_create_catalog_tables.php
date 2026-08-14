<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * كتالوج السوق | Marketplace catalogue (§4.4).
 *
 * `listings` يخدم المنتجات المادية والخدمات المهنية معاً، لأن معظم الحقول
 * مشتركة والاختلاف في القليل منها (الكمية والوحدة للمنتجات، مدة التنفيذ ونطاق
 * الخدمة للخدمات). فصلهما إلى جدولين كان سيضاعف البحث والتصفية والطلبات دون
 * مقابل.
 * One table serves both physical products and professional services: most
 * fields are shared, and splitting them would have doubled search, filtering
 * and ordering logic for little gain.
 *
 * التسعير له وضعان (§4.4): سعر ثابت، أو «اطلب عرض سعر» — والثاني لا يدخل
 * سلة الشراء إطلاقاً بل مسار طلب عرض السعر.
 */
return new class extends Migration {
    public function up(): void
    {
        // --- التصنيفات | Categories (single table, type-discriminated) ---
        $this->create('categories', <<<SQL
            `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `parent_id` SMALLINT UNSIGNED NULL,
            `type` VARCHAR(20) NOT NULL DEFAULT 'listing'
                COMMENT 'listing|service|article|financial',
            `code` VARCHAR(50) NOT NULL,
            `name_ar` VARCHAR(150) NOT NULL,
            `name_en` VARCHAR(150) NULL,
            `description_ar` VARCHAR(500) NULL,
            `icon` VARCHAR(60) NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_categories_code` (`type`, `code`),
            KEY `idx_categories_type` (`type`, `is_active`, `sort_order`),
            KEY `idx_categories_parent` (`parent_id`),
            CONSTRAINT `fk_categories_parent`
                FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- الإعلانات: منتجات وخدمات | Listings ---
        $this->create('listings', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,
            `category_id` SMALLINT UNSIGNED NULL,

            `listing_type` ENUM('product','service') NOT NULL DEFAULT 'product',

            -- الأسماء | Names — الإنجليزي جاهز للتوطين لاحقاً (§5)
            `name_ar` VARCHAR(200) NOT NULL,
            `name_en` VARCHAR(200) NULL,
            `slug` VARCHAR(220) NOT NULL,
            `short_description` VARCHAR(500) NULL,
            `description` TEXT NULL,

            -- التسعير | Pricing
            `pricing_mode` ENUM('fixed','quote') NOT NULL DEFAULT 'fixed'
                COMMENT 'fixed = سعر معروض | quote = اطلب عرض سعر',
            `price` DECIMAL(14,2) NULL COMMENT 'مطلوب عندما pricing_mode = fixed',
            `currency_code` CHAR(3) NOT NULL DEFAULT 'EGP',
            `vat_included` TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'هل السعر شامل ضريبة القيمة المضافة؟',
            `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,

            -- المنتجات | Product-specific
            `sku` VARCHAR(60) NULL,
            `unit_of_measure` VARCHAR(40) NULL COMMENT 'قطعة، كيلوجرام، متر…',
            `available_quantity` DECIMAL(14,3) NULL,
            `track_inventory` TINYINT(1) NOT NULL DEFAULT 0,
            `min_order_quantity` DECIMAL(14,3) NOT NULL DEFAULT 1.000,

            -- الخدمات والتسليم | Service and delivery
            `lead_time_days` SMALLINT UNSIGNED NULL,
            `delivery_area` VARCHAR(300) NULL,
            `delivery_fee` DECIMAL(14,2) NULL,

            -- الحالة والمراجعة | State and moderation (§4.4)
            `status` ENUM('draft','pending_review','published','rejected','archived')
                NOT NULL DEFAULT 'draft',
            `moderation_note` VARCHAR(1000) NULL,
            `moderated_by` INT UNSIGNED NULL,
            `moderated_at` DATETIME NULL DEFAULT NULL,
            `published_at` DATETIME NULL DEFAULT NULL,

            `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
            `primary_media_id` BIGINT UNSIGNED NULL,

            -- عدّادات للترتيب والتقارير | Counters
            `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `order_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `rating_average` DECIMAL(3,2) NULL,
            `rating_count` INT UNSIGNED NOT NULL DEFAULT 0,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_listings_slug` (`slug`),
            UNIQUE KEY `uq_listings_sku` (`organization_id`, `sku`),
            KEY `idx_listings_org_status` (`organization_id`, `status`, `deleted_at`),
            -- فهرس التصفية الشائعة في السوق: الحالة ثم النوع ثم التصنيف
            KEY `idx_listings_browse` (`status`, `listing_type`, `category_id`, `deleted_at`),
            KEY `idx_listings_price` (`status`, `price`),
            KEY `idx_listings_featured` (`is_featured`, `status`),
            FULLTEXT KEY `ft_listings_search` (`name_ar`, `short_description`, `description`),
            CONSTRAINT `fk_listings_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_listings_category`
                FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_listings_media`
                FOREIGN KEY (`primary_media_id`) REFERENCES `media` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_listings_moderator`
                FOREIGN KEY (`moderated_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- صور الإعلانات | Listing images ---
        $this->create('listing_images', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `listing_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL,
            `media_id` BIGINT UNSIGNED NOT NULL,
            `alt_text` VARCHAR(200) NULL COMMENT 'نص بديل — متطلّب إمكانية وصول',
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            KEY `idx_listing_images_listing` (`listing_id`, `sort_order`),
            KEY `idx_listing_images_org` (`organization_id`),
            CONSTRAINT `fk_listing_images_listing`
                FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_listing_images_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_listing_images_media`
                FOREIGN KEY (`media_id`) REFERENCES `media` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('listing_images');
        $this->drop('listings');
        $this->drop('categories');
    }
};
