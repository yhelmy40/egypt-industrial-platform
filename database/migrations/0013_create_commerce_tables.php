<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * السلة والطلبات | Cart and orders (§4.4).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * قرار جوهري: **طلب واحد لكل بائع**. السلة قد تضمّ أصنافاً من عدة منشآت، لكنها
 * تنقسم عند الدفع إلى طلبات مستقلة — لأن كل منشأة تسلّم وتُسعّر وتتابع حالتها
 * بنفسها، ولأن طلباً واحداً عابراً للبائعين يفرض تسوية مالية بين أطراف، وهي
 * خارج نطاق هذه النسخة صراحةً (§14).
 * One order per seller. A cart may span organizations but splits at checkout,
 * because each seller fulfils and tracks independently — and a cross-seller
 * order would require multi-party settlement, explicitly out of scope.
 *
 * كل مبلغ يُخزَّن DECIMAL لا FLOAT، وتُلتقط أسعار الأصناف كلقطة وقت الطلب حتى
 * لا يتغيّر تاريخ الطلب إذا عدّل البائع سعره لاحقاً.
 * Amounts are DECIMAL, and item prices are snapshotted so an order's history
 * does not change when the seller later edits a price.
 * ═══════════════════════════════════════════════════════════════════════════
 */
return new class extends Migration {
    public function up(): void
    {
        // --- طرق الدفع | Payment methods (§4.4 — offline only in this MVP) ---
        $this->create('payment_methods', <<<SQL
            `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(40) NOT NULL COMMENT 'cash_on_delivery|bank_transfer|offline',
            `name_ar` VARCHAR(120) NOT NULL,
            `instructions_ar` VARCHAR(1000) NULL COMMENT 'تعليمات تظهر للعميل',
            -- المحوّل البرمجي المسؤول — الواجهة موجودة والتكامل الحقيقي مؤجّل (§12)
            `driver` VARCHAR(40) NOT NULL DEFAULT 'offline',
            `requires_proof` TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'هل يطلب من العميل رفع إثبات تحويل؟',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_payment_methods_code` (`code`)
        SQL);

        // --- السلة | Carts (guest or authenticated) ---
        $this->create('carts', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            -- سلة الزائر تُعرَّف برمز عشوائي في كوكي؛ سلة المستخدم بمعرّفه.
            `user_id` INT UNSIGNED NULL,
            `guest_token` CHAR(64) NULL COMMENT 'رمز عشوائي لسلة الزائر',
            `status` ENUM('active','converted','abandoned') NOT NULL DEFAULT 'active',
            `converted_at` DATETIME NULL DEFAULT NULL,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_carts_guest_token` (`guest_token`),
            KEY `idx_carts_user` (`user_id`, `status`),
            KEY `idx_carts_status` (`status`, `updated_at`),
            CONSTRAINT `fk_carts_user`
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        $this->create('cart_items', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `cart_id` INT UNSIGNED NOT NULL,
            `listing_id` INT UNSIGNED NOT NULL,
            -- البائع محفوظ هنا لتقسيم السلة إلى طلبات دون استعلام إضافي
            `seller_organization_id` INT UNSIGNED NOT NULL,
            `quantity` DECIMAL(14,3) NOT NULL DEFAULT 1.000,
            -- سعر وقت الإضافة؛ يُعاد التحقق منه عند الدفع
            `unit_price` DECIMAL(14,2) NOT NULL,
            `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_cart_items` (`cart_id`, `listing_id`),
            KEY `idx_cart_items_listing` (`listing_id`),
            KEY `idx_cart_items_seller` (`seller_organization_id`),
            CONSTRAINT `fk_cart_items_cart`
                FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_cart_items_listing`
                FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_cart_items_seller`
                FOREIGN KEY (`seller_organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        // --- الطلبات | Orders ---
        $this->create('orders', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_number` VARCHAR(30) NOT NULL COMMENT 'رقم معروض للعميل',

            -- البائع: هو مالك السجل من منظور العزل بين المنشآت
            `organization_id` INT UNSIGNED NOT NULL COMMENT 'المنشأة البائعة',

            -- المشتري | Buyer (قد يكون زائراً بلا حساب)
            `customer_user_id` INT UNSIGNED NULL,
            `customer_name` VARCHAR(150) NOT NULL,
            `customer_phone` VARCHAR(30) NOT NULL,
            `customer_email` VARCHAR(190) NULL,
            `governorate_id` SMALLINT UNSIGNED NULL,
            `city_id` INT UNSIGNED NULL,
            `delivery_address` VARCHAR(500) NULL,
            `customer_note` VARCHAR(1000) NULL,

            -- رمز تتبّع للزائر — يسمح بمتابعة الطلب دون حساب (§4.4)
            `tracking_token` CHAR(48) NOT NULL,

            -- الحالة | The ten statuses required by §4.4
            `status` ENUM(
                'new','confirmed','preparing','ready','shipped',
                'delivered','completed','cancelled','disputed','refunded'
            ) NOT NULL DEFAULT 'new',
            `cancelled_reason` VARCHAR(500) NULL,

            -- المبالغ | Money (DECIMAL — never FLOAT)
            `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `vat_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `delivery_fee` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `currency_code` CHAR(3) NOT NULL DEFAULT 'EGP',

            -- الدفع | Payment (offline in this MVP)
            `payment_method_id` TINYINT UNSIGNED NULL,
            `payment_status` ENUM('unpaid','proof_submitted','paid','refunded')
                NOT NULL DEFAULT 'unpaid',
            `paid_at` DATETIME NULL DEFAULT NULL,

            -- مصدر الطلب | Origin
            `source` ENUM('marketplace','storefront','quotation') NOT NULL DEFAULT 'marketplace',
            `quotation_id` INT UNSIGNED NULL COMMENT 'إن نشأ الطلب من عرض سعر مقبول',

            `confirmed_at` DATETIME NULL DEFAULT NULL,
            `delivered_at` DATETIME NULL DEFAULT NULL,
            `completed_at` DATETIME NULL DEFAULT NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_orders_number` (`order_number`),
            UNIQUE KEY `uq_orders_tracking` (`tracking_token`),
            KEY `idx_orders_org_status` (`organization_id`, `status`, `created_at`),
            KEY `idx_orders_customer` (`customer_user_id`, `created_at`),
            KEY `idx_orders_phone` (`customer_phone`),
            KEY `idx_orders_created` (`created_at`),
            CONSTRAINT `fk_orders_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT `fk_orders_customer`
                FOREIGN KEY (`customer_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_orders_governorate`
                FOREIGN KEY (`governorate_id`) REFERENCES `governorates` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_orders_city`
                FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_orders_payment_method`
                FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- أصناف الطلب | Order items (price snapshot) ---
        $this->create('order_items', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL,
            `listing_id` INT UNSIGNED NULL COMMENT 'قد يُحذف الإعلان لاحقاً — الطلب يبقى',

            -- لقطة وقت الطلب | Snapshot at order time
            `name_ar` VARCHAR(200) NOT NULL,
            `sku` VARCHAR(60) NULL,
            `unit_of_measure` VARCHAR(40) NULL,
            `quantity` DECIMAL(14,3) NOT NULL,
            `unit_price` DECIMAL(14,2) NOT NULL,
            `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `line_subtotal` DECIMAL(14,2) NOT NULL,
            `line_vat` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `line_total` DECIMAL(14,2) NOT NULL,

            {$this->timestamps()},
            PRIMARY KEY (`id`),
            KEY `idx_order_items_order` (`order_id`),
            KEY `idx_order_items_org` (`organization_id`),
            KEY `idx_order_items_listing` (`listing_id`),
            CONSTRAINT `fk_order_items_order`
                FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_order_items_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_order_items_listing`
                FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- سجل حالات الطلب | Order status history (§8) ---
        $this->create('order_status_history', <<<'SQL'
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL,
            `from_status` VARCHAR(20) NULL,
            `to_status` VARCHAR(20) NOT NULL,
            `actor_user_id` INT UNSIGNED NULL,
            `actor_type` ENUM('seller','customer','platform','system') NOT NULL DEFAULT 'seller',
            `note` VARCHAR(500) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_order_status_history_order` (`order_id`, `created_at`),
            KEY `idx_order_status_history_org` (`organization_id`),
            CONSTRAINT `fk_order_status_history_order`
                FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_order_status_history_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_order_status_history_actor`
                FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- إثبات الدفع | Payment records with uploaded proof ---
        $this->create('order_payments', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL,
            `payment_method_id` TINYINT UNSIGNED NULL,
            `amount` DECIMAL(14,2) NOT NULL,
            `reference` VARCHAR(120) NULL COMMENT 'رقم التحويل أو المرجع',
            `proof_media_id` BIGINT UNSIGNED NULL,
            `status` ENUM('submitted','verified','rejected') NOT NULL DEFAULT 'submitted',
            `verified_by` INT UNSIGNED NULL,
            `verified_at` DATETIME NULL DEFAULT NULL,
            `note` VARCHAR(500) NULL,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            KEY `idx_order_payments_order` (`order_id`),
            KEY `idx_order_payments_org` (`organization_id`, `status`),
            CONSTRAINT `fk_order_payments_order`
                FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_order_payments_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_order_payments_method`
                FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_order_payments_proof`
                FOREIGN KEY (`proof_media_id`) REFERENCES `media` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_order_payments_verifier`
                FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('order_payments');
        $this->drop('order_status_history');
        $this->drop('order_items');
        $this->drop('orders');
        $this->drop('cart_items');
        $this->drop('carts');
        $this->drop('payment_methods');
    }
};
