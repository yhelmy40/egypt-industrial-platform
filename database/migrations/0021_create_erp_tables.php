<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * جداول إدارة الموارد المبسّطة | ERP-lite tables (§4.10, §14).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **هذه ليست نظاماً محاسبياً.** لا قيد مزدوج ولا دليل حسابات ولا أجور ولا
 * تخطيط إنتاج — كلها خارج نطاق النسخة صراحةً (§14). ما هنا سجلّ تشغيلي
 * بسيط: أصناف ومخزون وفواتير ومقبوضات ومصروفات، على **الأساس النقدي**.
 *
 * قراران يحكمان المخطط:
 *
 *  1. **دفتر المخزون مضاف إليه فقط.** `erp_items.quantity_on_hand` رصيد
 *     مشتقّ لا مُدخَل: كل تغيّر يمرّ بحركة في `erp_stock_movements` تحمل سببها
 *     وفاعلها. التصحيح حركة تسوية جديدة لا كتابة فوق الرصيد — الكتابة فوقه
 *     تمحو أثر النقص، وهو بالضبط ما يحتاج صاحب المشروع أن يراه.
 *
 *  2. **الفاتورة المُصدَرة وثيقة لا مسودة.** بعد إصدارها يحمل العميل نسخة
 *     منها، فتعديل بنودها يجعل نسختين مختلفتين تحملان الرقم نفسه. التصحيح
 *     يكون بإلغاء موثّق بسبب، لا بتحرير صامت.
 * ═══════════════════════════════════════════════════════════════════════════
 */
return new class extends Migration {
    public function up(): void
    {
        // --- الأصناف | Items ---
        // كتالوج داخلي أوسع من إعلانات السوق: يشمل الخامات واللوازم التي لا
        // تُعرض للبيع. `listing_id` ربط اختياري يجعل الصنف مصدر الرصيد الوحيد
        // للإعلان المرتبط به.
        $this->create('erp_items', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,

            `sku` VARCHAR(60) NOT NULL,
            `name_ar` VARCHAR(200) NOT NULL,
            `description_ar` VARCHAR(1000) NULL,

            `item_type` ENUM('product','raw_material','supply','service')
                NOT NULL DEFAULT 'product',
            `unit_of_measure` VARCHAR(40) NOT NULL DEFAULT 'قطعة',

            -- الخدمة لا مخزون لها؛ العمود يبقى صفراً ولا تُقبل حركات عليها
            `track_stock` TINYINT(1) NOT NULL DEFAULT 1,
            `quantity_on_hand` DECIMAL(14,3) NOT NULL DEFAULT 0.000
                COMMENT 'رصيد مشتقّ من دفتر الحركات لا يُحرَّر مباشرةً',
            `reorder_level` DECIMAL(14,3) NULL COMMENT 'حدّ التنبيه لإعادة الطلب',

            `cost_price` DECIMAL(14,2) NULL COMMENT 'آخر تكلفة شراء معروفة',
            `sale_price` DECIMAL(14,2) NULL,
            `currency_code` CHAR(3) NOT NULL DEFAULT 'EGP',
            `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,

            `listing_id` INT UNSIGNED NULL COMMENT 'إعلان السوق المرتبط إن وُجد',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_erp_items_sku` (`organization_id`, `sku`),
            UNIQUE KEY `uq_erp_items_listing` (`listing_id`),
            KEY `idx_erp_items_org` (`organization_id`, `is_active`, `name_ar`),
            KEY `idx_erp_items_low_stock` (`organization_id`, `track_stock`, `quantity_on_hand`),
            CONSTRAINT `fk_erp_items_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_items_listing`
                FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- الموردون | Suppliers ---
        $this->create('erp_suppliers', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,

            `code` VARCHAR(30) NOT NULL,
            `name_ar` VARCHAR(200) NOT NULL,
            `contact_person_ar` VARCHAR(150) NULL,
            `phone` VARCHAR(30) NULL,
            `email` VARCHAR(190) NULL,
            `governorate_id` SMALLINT UNSIGNED NULL,
            `address` VARCHAR(500) NULL,
            `tax_number` VARCHAR(30) NULL,

            `payment_terms_ar` VARCHAR(200) NULL COMMENT 'شروط السداد كما اتُّفق عليها',
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `notes_ar` VARCHAR(1000) NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_erp_suppliers_code` (`organization_id`, `code`),
            KEY `idx_erp_suppliers_org` (`organization_id`, `status`, `name_ar`),
            CONSTRAINT `fk_erp_suppliers_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_suppliers_gov`
                FOREIGN KEY (`governorate_id`) REFERENCES `governorates` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- دفتر حركات المخزون | The stock movement ledger ---
        // مضاف إليه فقط: لا تعديل ولا حذف. `quantity_delta` موجب للوارد وسالب
        // للمنصرف، و`balance_after` يُثبَّت لحظة الحركة ليبقى الدفتر مقروءاً
        // دون إعادة حساب كل التاريخ.
        $this->create('erp_stock_movements', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,
            `item_id` INT UNSIGNED NOT NULL,

            `movement_type` ENUM(
                'opening','purchase','sale','return_in','return_out',
                'adjustment','damage','transfer_out','production_in'
            ) NOT NULL,
            `quantity_delta` DECIMAL(14,3) NOT NULL COMMENT 'موجب وارد وسالب منصرف',
            `balance_after` DECIMAL(14,3) NOT NULL COMMENT 'الرصيد بعد الحركة',

            `unit_cost` DECIMAL(14,2) NULL COMMENT 'تكلفة الوحدة للحركات الواردة',

            -- المرجع: طلب سوق، فاتورة، أمر شراء، أو لا شيء للتسوية اليدوية
            `reference_type` ENUM('order','invoice','purchase_order','manual') NOT NULL DEFAULT 'manual',
            `reference_id` INT UNSIGNED NULL,

            `reason_ar` VARCHAR(500) NULL COMMENT 'إلزامي للتسوية والتالف',
            `moved_at` DATETIME NOT NULL,
            `created_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_erp_movements_item` (`item_id`, `moved_at`, `id`),
            KEY `idx_erp_movements_org` (`organization_id`, `moved_at`),
            KEY `idx_erp_movements_ref` (`reference_type`, `reference_id`),
            CONSTRAINT `fk_erp_movements_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_movements_item`
                FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_movements_creator`
                FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- فواتير البيع | Sales invoices ---
        // `status` آلة حالة قصيرة: مسودة تُحرَّر، ومُصدَرة لا تُحرَّر.
        // `amount_paid` مشتقّ من `erp_payments` ولا يُكتب يدوياً.
        $this->create('erp_invoices', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,

            `invoice_number` VARCHAR(30) NOT NULL,
            `customer_id` INT UNSIGNED NULL COMMENT 'عميل مسجَّل إن وُجد',
            `customer_name_ar` VARCHAR(200) NOT NULL COMMENT 'يُنسخ ليبقى المستند مقروءاً',
            `customer_phone` VARCHAR(30) NULL,
            `customer_tax_number` VARCHAR(30) NULL,

            `order_id` INT UNSIGNED NULL COMMENT 'طلب السوق الذي وُلدت منه',

            `issue_date` DATE NOT NULL,
            `due_date` DATE NULL,

            `status` ENUM('draft','issued','partially_paid','paid','cancelled')
                NOT NULL DEFAULT 'draft',

            `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `vat_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `amount_paid` DECIMAL(14,2) NOT NULL DEFAULT 0.00
                COMMENT 'مجموع المقبوضات؛ مشتقّ لا يُحرَّر',
            `currency_code` CHAR(3) NOT NULL DEFAULT 'EGP',

            `notes_ar` VARCHAR(1000) NULL,
            `cancelled_reason_ar` VARCHAR(500) NULL,
            `cancelled_at` DATETIME NULL DEFAULT NULL,
            `cancelled_by` INT UNSIGNED NULL,

            `issued_at` DATETIME NULL DEFAULT NULL,
            `issued_by` INT UNSIGNED NULL,
            `created_by` INT UNSIGNED NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_erp_invoices_number` (`organization_id`, `invoice_number`),
            KEY `idx_erp_invoices_org` (`organization_id`, `status`, `issue_date`),
            KEY `idx_erp_invoices_customer` (`customer_id`, `status`),
            KEY `idx_erp_invoices_due` (`organization_id`, `status`, `due_date`),
            CONSTRAINT `fk_erp_invoices_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_invoices_customer`
                FOREIGN KEY (`customer_id`) REFERENCES `crm_customers` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_invoices_order`
                FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_invoices_issuer`
                FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_invoices_canceller`
                FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_invoices_creator`
                FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- بنود الفاتورة | Invoice lines ---
        // اسم الصنف وسعره يُنسخان لحظة الإضافة: تغيير سعر الصنف لاحقاً يجب
        // ألّا يغيّر فاتورة صدرت بسعر آخر.
        $this->create('erp_invoice_items', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `invoice_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL,
            `item_id` INT UNSIGNED NULL,

            `name_ar` VARCHAR(200) NOT NULL COMMENT 'منسوخ لحظة الإضافة',
            `unit_of_measure` VARCHAR(40) NULL,
            `quantity` DECIMAL(14,3) NOT NULL,
            `unit_price` DECIMAL(14,2) NOT NULL,
            `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `line_total` DECIMAL(14,2) NOT NULL COMMENT 'قبل الضريبة',
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_erp_invoice_items_invoice` (`invoice_id`, `sort_order`),
            KEY `idx_erp_invoice_items_org` (`organization_id`),
            KEY `idx_erp_invoice_items_item` (`item_id`),
            CONSTRAINT `fk_erp_invoice_items_invoice`
                FOREIGN KEY (`invoice_id`) REFERENCES `erp_invoices` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_invoice_items_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_invoice_items_item`
                FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- المقبوضات | Receipts against invoices ---
        // المنصة **لا تحصّل** شيئاً: هذا تسجيل لمبلغ استلمه صاحب المشروع خارجها.
        $this->create('erp_payments', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,
            `invoice_id` INT UNSIGNED NOT NULL,

            `receipt_number` VARCHAR(30) NOT NULL,
            `amount` DECIMAL(14,2) NOT NULL,
            `currency_code` CHAR(3) NOT NULL DEFAULT 'EGP',
            `paid_at` DATE NOT NULL,
            `method` ENUM('cash','bank_transfer','cheque','wallet','other')
                NOT NULL DEFAULT 'cash',
            `reference_ar` VARCHAR(200) NULL COMMENT 'رقم الشيك أو التحويل كما كتبه صاحب المشروع',
            `notes_ar` VARCHAR(500) NULL,

            `created_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_erp_payments_number` (`organization_id`, `receipt_number`),
            KEY `idx_erp_payments_invoice` (`invoice_id`, `paid_at`),
            KEY `idx_erp_payments_org` (`organization_id`, `paid_at`),
            CONSTRAINT `fk_erp_payments_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_payments_invoice`
                FOREIGN KEY (`invoice_id`) REFERENCES `erp_invoices` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_payments_creator`
                FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- المصروفات | Expenses ---
        // على الأساس النقدي: ما خرج فعلاً بتاريخ خروجه. لا استحقاق ولا إهلاك.
        $this->create('erp_expenses', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,

            `expense_number` VARCHAR(30) NOT NULL,
            `category` ENUM(
                'materials','rent','utilities','salaries','transport','marketing',
                'maintenance','fees','taxes','other'
            ) NOT NULL DEFAULT 'other',
            `description_ar` VARCHAR(500) NOT NULL,

            `amount` DECIMAL(14,2) NOT NULL,
            `currency_code` CHAR(3) NOT NULL DEFAULT 'EGP',
            `spent_at` DATE NOT NULL,
            `method` ENUM('cash','bank_transfer','cheque','wallet','other')
                NOT NULL DEFAULT 'cash',

            `supplier_id` INT UNSIGNED NULL,
            `purchase_order_id` INT UNSIGNED NULL,
            `receipt_media_id` BIGINT UNSIGNED NULL COMMENT 'صورة الإيصال — ملف لا BLOB',
            `notes_ar` VARCHAR(1000) NULL,

            `created_by` INT UNSIGNED NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_erp_expenses_number` (`organization_id`, `expense_number`),
            KEY `idx_erp_expenses_org` (`organization_id`, `spent_at`, `category`),
            KEY `idx_erp_expenses_supplier` (`supplier_id`, `spent_at`),
            CONSTRAINT `fk_erp_expenses_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_expenses_supplier`
                FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_expenses_media`
                FOREIGN KEY (`receipt_media_id`) REFERENCES `media` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_expenses_creator`
                FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- أوامر الشراء | Purchase orders ---
        // الاستلام هو ما يزيد المخزون، لا إصدار الأمر: أمر شراء لم يصل بعد
        // ليس بضاعة في المخزن.
        $this->create('erp_purchase_orders', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,
            `supplier_id` INT UNSIGNED NOT NULL,

            `po_number` VARCHAR(30) NOT NULL,
            `order_date` DATE NOT NULL,
            `expected_date` DATE NULL,

            `status` ENUM('draft','sent','partially_received','received','cancelled')
                NOT NULL DEFAULT 'draft',

            `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `vat_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `currency_code` CHAR(3) NOT NULL DEFAULT 'EGP',

            `notes_ar` VARCHAR(1000) NULL,
            `cancelled_reason_ar` VARCHAR(500) NULL,
            `sent_at` DATETIME NULL DEFAULT NULL,
            `received_at` DATETIME NULL DEFAULT NULL,
            `created_by` INT UNSIGNED NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_erp_po_number` (`organization_id`, `po_number`),
            KEY `idx_erp_po_org` (`organization_id`, `status`, `order_date`),
            KEY `idx_erp_po_supplier` (`supplier_id`, `status`),
            CONSTRAINT `fk_erp_po_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_po_supplier`
                FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_po_creator`
                FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- بنود أمر الشراء | Purchase order lines ---
        // `quantity_received` يتراكم مع كل استلام جزئي ولا يتجاوز المطلوب.
        $this->create('erp_purchase_order_items', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `purchase_order_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL,
            `item_id` INT UNSIGNED NOT NULL,

            `name_ar` VARCHAR(200) NOT NULL COMMENT 'منسوخ لحظة الإضافة',
            `unit_of_measure` VARCHAR(40) NULL,
            `quantity_ordered` DECIMAL(14,3) NOT NULL,
            `quantity_received` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `unit_cost` DECIMAL(14,2) NOT NULL,
            `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `line_total` DECIMAL(14,2) NOT NULL,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (`id`),
            KEY `idx_erp_po_items_po` (`purchase_order_id`, `sort_order`),
            KEY `idx_erp_po_items_org` (`organization_id`),
            KEY `idx_erp_po_items_item` (`item_id`),
            CONSTRAINT `fk_erp_po_items_po`
                FOREIGN KEY (`purchase_order_id`) REFERENCES `erp_purchase_orders` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_po_items_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_erp_po_items_item`
                FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('erp_purchase_order_items');
        $this->drop('erp_purchase_orders');
        $this->drop('erp_expenses');
        $this->drop('erp_payments');
        $this->drop('erp_invoice_items');
        $this->drop('erp_invoices');
        $this->drop('erp_stock_movements');
        $this->drop('erp_suppliers');
        $this->drop('erp_items');
    }
};
