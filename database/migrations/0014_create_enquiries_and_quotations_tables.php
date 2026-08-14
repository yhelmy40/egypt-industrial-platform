<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * الاستفسارات وعروض الأسعار والتقييمات والشكاوى | Enquiries, RFQ, reviews, complaints.
 *
 * مسار «اطلب عرض سعر» (§4.4) هو الوجه الآخر للسوق: كثير من المشروعات المصرية
 * تبيع بالتفاوض لا بسعر معروض، خصوصاً في التصنيع لدى الغير والخدمات. لذلك
 * ينتهي المسار بتحويل عرض السعر المقبول إلى طلب دون إعادة إدخال البيانات.
 * The RFQ path is the marketplace's other face: many Egyptian SMEs sell by
 * negotiation rather than list price. An accepted quotation converts into an
 * order without re-entering anything.
 *
 * التقييم مشروط بطلب مكتمل (§4.4) — لا مراجعات من غير عملاء.
 */
return new class extends Migration {
    public function up(): void
    {
        // --- استفسارات العملاء | Customer enquiries ---
        $this->create('customer_enquiries', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,
            `listing_id` INT UNSIGNED NULL COMMENT 'استفسار عن صنف بعينه أو عن المنشأة',

            `customer_user_id` INT UNSIGNED NULL,
            `customer_name` VARCHAR(150) NOT NULL,
            `customer_phone` VARCHAR(30) NOT NULL,
            `customer_email` VARCHAR(190) NULL,

            `subject` VARCHAR(200) NULL,
            `message` VARCHAR(2000) NOT NULL,

            `status` ENUM('new','read','replied','converted','closed','spam')
                NOT NULL DEFAULT 'new',
            `reply` VARCHAR(2000) NULL,
            `replied_by` INT UNSIGNED NULL,
            `replied_at` DATETIME NULL DEFAULT NULL,

            -- تحويل الاستفسار إلى عميل محتمل في المرحلة السادسة (§4.8)
            `converted_to_lead_id` INT UNSIGNED NULL,

            `source_ip` VARBINARY(16) NULL COMMENT 'لكشف الإساءة فقط',
            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            KEY `idx_enquiries_org_status` (`organization_id`, `status`, `created_at`),
            KEY `idx_enquiries_listing` (`listing_id`),
            KEY `idx_enquiries_customer` (`customer_user_id`),
            CONSTRAINT `fk_enquiries_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_enquiries_listing`
                FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_enquiries_customer`
                FOREIGN KEY (`customer_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_enquiries_replier`
                FOREIGN KEY (`replied_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- طلبات عروض الأسعار | Quotation requests and quotations ---
        $this->create('quotations', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `quotation_number` VARCHAR(30) NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL COMMENT 'المنشأة البائعة',
            `listing_id` INT UNSIGNED NULL,

            -- الطالب | Requester
            `customer_user_id` INT UNSIGNED NULL,
            `customer_name` VARCHAR(150) NOT NULL,
            `customer_phone` VARCHAR(30) NOT NULL,
            `customer_email` VARCHAR(190) NULL,
            `governorate_id` SMALLINT UNSIGNED NULL,

            -- تفاصيل الطلب | Request details
            `request_details` VARCHAR(2000) NOT NULL,
            `requested_quantity` DECIMAL(14,3) NULL,
            `needed_by` DATE NULL,

            -- حالة عرض السعر | Quotation state
            `status` ENUM('requested','quoted','accepted','rejected','expired','withdrawn')
                NOT NULL DEFAULT 'requested',

            -- العرض المقدَّم من البائع | The seller's quote
            `subtotal` DECIMAL(14,2) NULL,
            `vat_amount` DECIMAL(14,2) NULL,
            `delivery_fee` DECIMAL(14,2) NULL,
            `total` DECIMAL(14,2) NULL,
            `currency_code` CHAR(3) NOT NULL DEFAULT 'EGP',
            `lead_time_days` SMALLINT UNSIGNED NULL,
            `terms` VARCHAR(2000) NULL,
            `valid_until` DATE NULL,

            `quoted_by` INT UNSIGNED NULL,
            `quoted_at` DATETIME NULL DEFAULT NULL,
            `responded_at` DATETIME NULL DEFAULT NULL COMMENT 'قبول أو رفض العميل',
            `converted_order_id` INT UNSIGNED NULL,

            `tracking_token` CHAR(48) NOT NULL COMMENT 'متابعة الطلب دون حساب',

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_quotations_number` (`quotation_number`),
            UNIQUE KEY `uq_quotations_tracking` (`tracking_token`),
            KEY `idx_quotations_org_status` (`organization_id`, `status`, `created_at`),
            KEY `idx_quotations_customer` (`customer_user_id`),
            CONSTRAINT `fk_quotations_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_quotations_listing`
                FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_quotations_customer`
                FOREIGN KEY (`customer_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_quotations_governorate`
                FOREIGN KEY (`governorate_id`) REFERENCES `governorates` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_quotations_quoter`
                FOREIGN KEY (`quoted_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_quotations_order`
                FOREIGN KEY (`converted_order_id`) REFERENCES `orders` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        $this->create('quotation_items', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `quotation_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL,
            `listing_id` INT UNSIGNED NULL,
            `description` VARCHAR(300) NOT NULL,
            `quantity` DECIMAL(14,3) NOT NULL DEFAULT 1.000,
            `unit_of_measure` VARCHAR(40) NULL,
            `unit_price` DECIMAL(14,2) NOT NULL,
            `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `line_total` DECIMAL(14,2) NOT NULL,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            KEY `idx_quotation_items_quotation` (`quotation_id`, `sort_order`),
            KEY `idx_quotation_items_org` (`organization_id`),
            CONSTRAINT `fk_quotation_items_quotation`
                FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_quotation_items_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_quotation_items_listing`
                FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- التقييمات | Reviews (only after a completed order — §4.4) ---
        $this->create('reviews', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL COMMENT 'المنشأة المُقيَّمة',
            `listing_id` INT UNSIGNED NULL,
            -- الطلب المكتمل هو ما يمنح حق التقييم؛ لا مراجعات بلا معاملة
            `order_id` INT UNSIGNED NOT NULL,
            `customer_user_id` INT UNSIGNED NULL,
            `customer_name` VARCHAR(150) NOT NULL,

            `rating` TINYINT UNSIGNED NOT NULL COMMENT 'من 1 إلى 5',
            `comment` VARCHAR(1500) NULL,

            `status` ENUM('published','pending','rejected') NOT NULL DEFAULT 'published',
            `moderation_note` VARCHAR(500) NULL,
            `moderated_by` INT UNSIGNED NULL,

            -- ردّ البائع | Seller reply
            `seller_reply` VARCHAR(1500) NULL,
            `replied_at` DATETIME NULL DEFAULT NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            -- تقييم واحد لكل طلب | One review per order
            UNIQUE KEY `uq_reviews_order` (`order_id`),
            KEY `idx_reviews_org` (`organization_id`, `status`, `created_at`),
            KEY `idx_reviews_listing` (`listing_id`, `status`),
            CONSTRAINT `fk_reviews_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_reviews_listing`
                FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_reviews_order`
                FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_reviews_customer`
                FOREIGN KEY (`customer_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_reviews_moderator`
                FOREIGN KEY (`moderated_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- الشكاوى والنزاعات | Complaints and disputes ---
        $this->create('complaints', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `complaint_number` VARCHAR(30) NOT NULL,

            -- المشتكى ضده | Subject of the complaint
            `organization_id` INT UNSIGNED NULL,
            `order_id` INT UNSIGNED NULL,
            `listing_id` INT UNSIGNED NULL,

            -- المشتكي | Complainant (customer or organization)
            `complainant_user_id` INT UNSIGNED NULL,
            `complainant_name` VARCHAR(150) NOT NULL,
            `complainant_phone` VARCHAR(30) NULL,
            `complainant_email` VARCHAR(190) NULL,

            `category` VARCHAR(40) NOT NULL
                COMMENT 'product_quality|delivery|pricing|conduct|listing_content|other',
            `subject` VARCHAR(200) NOT NULL,
            `details` VARCHAR(3000) NOT NULL,

            `status` ENUM('new','under_review','awaiting_response','resolved','rejected','escalated')
                NOT NULL DEFAULT 'new',
            `assigned_to` INT UNSIGNED NULL,
            `resolution` VARCHAR(2000) NULL,
            `resolved_at` DATETIME NULL DEFAULT NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_complaints_number` (`complaint_number`),
            KEY `idx_complaints_status` (`status`, `created_at`),
            KEY `idx_complaints_org` (`organization_id`, `status`),
            KEY `idx_complaints_order` (`order_id`),
            CONSTRAINT `fk_complaints_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_complaints_order`
                FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_complaints_listing`
                FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_complaints_complainant`
                FOREIGN KEY (`complainant_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_complaints_assignee`
                FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // ربط الطلب بعرض السعر المُحوَّل | Link order back to its quotation
        $this->run(
            'ALTER TABLE `orders`
             ADD CONSTRAINT `fk_orders_quotation`
             FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`)
             ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        $this->run('ALTER TABLE `orders` DROP FOREIGN KEY `fk_orders_quotation`');
        $this->drop('complaints');
        $this->drop('reviews');
        $this->drop('quotation_items');
        $this->drop('quotations');
        $this->drop('customer_enquiries');
    }
};
