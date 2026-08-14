<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * جداول إدارة العملاء | CRM tables (§4.9).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ثلاث طبقات متمايزة عمداً:
 *
 *  1. **المهتمّ (`crm_leads`)** اهتمام لم يُتحقَّق منه بعد. قد يأتي من استفسار
 *     في السوق أو من معرض أو مكالمة. لا يُخلط بالعميل: خلطهما يجعل «عدد
 *     العملاء» رقماً بلا معنى، وهو رقم يبني عليه صاحب المشروع قراراته.
 *  2. **العميل (`crm_customers`)** جهة تعامل فعلي، ومعها أشخاصها
 *     (`crm_contacts`). الجهة تدوم والأشخاص يتغيّرون، فلا يصحّ دمجهما.
 *  3. **الفرصة (`crm_opportunities`)** صفقة محتملة لها مرحلة وقيمة متوقّعة.
 *
 * التحويل يترك أثراً: `crm_leads.converted_customer_id` يحفظ أن هذا العميل
 * كان مهتمّاً، فلا تُفقَد قصة العلاقة عند أول بيع.
 * ═══════════════════════════════════════════════════════════════════════════
 */
return new class extends Migration {
    public function up(): void
    {
        // --- العملاء | Customers ---
        $this->create('crm_customers', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,

            `code` VARCHAR(30) NOT NULL COMMENT 'كود العميل داخل المشروع',
            `name_ar` VARCHAR(200) NOT NULL,
            `customer_type` ENUM('individual','company','government','ngo') NOT NULL DEFAULT 'individual',

            `phone` VARCHAR(30) NULL,
            `email` VARCHAR(190) NULL,
            `governorate_id` SMALLINT UNSIGNED NULL,
            `city_id` INT UNSIGNED NULL,
            `address` VARCHAR(500) NULL,

            -- الرقم الضريبي وحده يُجمع؛ لا رقم قومي ولا بيانات بنكية (§10)
            `tax_number` VARCHAR(30) NULL COMMENT 'الرقم الضريبي للمنشأة العميلة',

            `source` ENUM('marketplace','referral','walk_in','social','event','other')
                NOT NULL DEFAULT 'other',
            `status` ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',

            -- ربط اختياري بحساب على المنصة إن كان العميل مسجَّلاً
            `linked_user_id` INT UNSIGNED NULL,

            `credit_limit` DECIMAL(14,2) NULL COMMENT 'حدّ ائتماني إرشادي يضعه صاحب المشروع',
            `notes_ar` VARCHAR(2000) NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_crm_customers_code` (`organization_id`, `code`),
            KEY `idx_crm_customers_org` (`organization_id`, `status`, `name_ar`),
            KEY `idx_crm_customers_phone` (`organization_id`, `phone`),
            CONSTRAINT `fk_crm_customers_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_customers_gov`
                FOREIGN KEY (`governorate_id`) REFERENCES `governorates` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_customers_city`
                FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_customers_user`
                FOREIGN KEY (`linked_user_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- جهات الاتصال | Contacts ---
        $this->create('crm_contacts', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,
            `customer_id` INT UNSIGNED NOT NULL,

            `name_ar` VARCHAR(150) NOT NULL,
            `job_title_ar` VARCHAR(120) NULL,
            `phone` VARCHAR(30) NULL,
            `email` VARCHAR(190) NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `notes_ar` VARCHAR(1000) NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            KEY `idx_crm_contacts_customer` (`customer_id`, `is_primary`),
            KEY `idx_crm_contacts_org` (`organization_id`),
            CONSTRAINT `fk_crm_contacts_customer`
                FOREIGN KEY (`customer_id`) REFERENCES `crm_customers` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_contacts_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        // --- المهتمّون | Leads ---
        // المهتمّ ليس عميلاً. التحويل ينشئ عميلاً ويحفظ رقمه هنا، فيبقى أثر
        // مصدر العلاقة بعد أول بيع.
        $this->create('crm_leads', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,

            `name_ar` VARCHAR(200) NOT NULL,
            `phone` VARCHAR(30) NULL,
            `email` VARCHAR(190) NULL,
            `interest_ar` VARCHAR(1000) NULL COMMENT 'ما يبحث عنه',

            `source` ENUM('marketplace','referral','walk_in','social','event','other')
                NOT NULL DEFAULT 'other',
            `source_enquiry_id` INT UNSIGNED NULL COMMENT 'استفسار السوق الذي وُلد منه',

            `status` ENUM('new','contacted','qualified','converted','lost')
                NOT NULL DEFAULT 'new',
            `lost_reason_ar` VARCHAR(500) NULL,

            `converted_customer_id` INT UNSIGNED NULL,
            `converted_at` DATETIME NULL DEFAULT NULL,

            `assigned_to` INT UNSIGNED NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            KEY `idx_crm_leads_org` (`organization_id`, `status`, `created_at`),
            KEY `idx_crm_leads_assignee` (`assigned_to`, `status`),
            CONSTRAINT `fk_crm_leads_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_leads_enquiry`
                FOREIGN KEY (`source_enquiry_id`) REFERENCES `customer_enquiries` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_leads_customer`
                FOREIGN KEY (`converted_customer_id`) REFERENCES `crm_customers` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_leads_assignee`
                FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- الفرص | Opportunities ---
        // `expected_value` تقدير صاحب المشروع لا التزام، و`probability` نسبته
        // هو. المنصة لا تتنبّأ ولا تحسب احتمالاً نيابةً عنه.
        $this->create('crm_opportunities', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,
            `customer_id` INT UNSIGNED NOT NULL,

            `title_ar` VARCHAR(200) NOT NULL,
            `description_ar` VARCHAR(2000) NULL,

            `stage` ENUM('new','qualified','proposal','negotiation','won','lost')
                NOT NULL DEFAULT 'new',
            `expected_value` DECIMAL(14,2) NULL COMMENT 'تقدير صاحب المشروع لا التزام',
            `currency_code` CHAR(3) NOT NULL DEFAULT 'EGP',
            `probability` TINYINT UNSIGNED NULL COMMENT 'نسبة يقدّرها صاحب المشروع 0-100',
            `expected_close_date` DATE NULL,

            `closed_at` DATETIME NULL DEFAULT NULL,
            `close_reason_ar` VARCHAR(500) NULL COMMENT 'إلزامي عند الخسارة',

            `assigned_to` INT UNSIGNED NULL,
            `source_quotation_id` INT UNSIGNED NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            KEY `idx_crm_opps_org` (`organization_id`, `stage`, `expected_close_date`),
            KEY `idx_crm_opps_customer` (`customer_id`, `stage`),
            CONSTRAINT `fk_crm_opps_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_opps_customer`
                FOREIGN KEY (`customer_id`) REFERENCES `crm_customers` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_opps_assignee`
                FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_opps_quotation`
                FOREIGN KEY (`source_quotation_id`) REFERENCES `quotations` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- الأنشطة والمهام | Activities and tasks ---
        // سجلّ واحد لما جرى (`logged`) ولما سيجري (`planned`). فصلهما في
        // جدولين كان سيجعل «آخر تواصل مع العميل» استعلامين ودمجاً يدوياً.
        $this->create('crm_activities', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,

            `customer_id` INT UNSIGNED NULL,
            `lead_id` INT UNSIGNED NULL,
            `opportunity_id` INT UNSIGNED NULL,

            `activity_type` ENUM('call','visit','meeting','email','message','note','task')
                NOT NULL DEFAULT 'note',
            `subject_ar` VARCHAR(200) NOT NULL,
            `body_ar` VARCHAR(2000) NULL,

            -- المنجز مقابل المخطَّط | Logged versus planned
            `occurred_at` DATETIME NULL DEFAULT NULL COMMENT 'وقت وقوعه فعلاً',
            `due_at` DATETIME NULL DEFAULT NULL COMMENT 'موعد استحقاقه إن كان مهمة',
            `status` ENUM('planned','done','cancelled') NOT NULL DEFAULT 'done',
            `completed_at` DATETIME NULL DEFAULT NULL,

            `assigned_to` INT UNSIGNED NULL,
            `created_by` INT UNSIGNED NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            KEY `idx_crm_activities_org` (`organization_id`, `status`, `due_at`),
            KEY `idx_crm_activities_customer` (`customer_id`, `occurred_at`),
            KEY `idx_crm_activities_lead` (`lead_id`),
            KEY `idx_crm_activities_opp` (`opportunity_id`),
            KEY `idx_crm_activities_assignee` (`assigned_to`, `status`, `due_at`),
            CONSTRAINT `fk_crm_activities_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_activities_customer`
                FOREIGN KEY (`customer_id`) REFERENCES `crm_customers` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_activities_lead`
                FOREIGN KEY (`lead_id`) REFERENCES `crm_leads` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_activities_opp`
                FOREIGN KEY (`opportunity_id`) REFERENCES `crm_opportunities` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_activities_assignee`
                FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_crm_activities_creator`
                FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('crm_activities');
        $this->drop('crm_opportunities');
        $this->drop('crm_leads');
        $this->drop('crm_contacts');
        $this->drop('crm_customers');
    }
};
