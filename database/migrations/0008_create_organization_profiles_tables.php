<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * ملفات المنشآت التفصيلية | Per-type organization profiles (§4.2).
 *
 * كل نوع منشأة له جدول ملف 1:1 مع `organizations`، بدل حشو الجدول الأساسي
 * بأعمدة تخصّ نوعاً واحداً وتبقى فارغة لبقية الأنواع.
 * Each organization type gets a 1:1 profile table instead of padding the base
 * table with columns that only apply to one type.
 *
 * ⚠ خصوصية (§10): الحقول هنا **لا تظهر على الصفحة العامة**. أرقام السجل
 * التجاري والبطاقة الضريبية وبيانات الملكية الديموغرافية والاحتياجات التمويلية
 * بيانات مراجعة داخلية يراها فريق التوثيق وصاحب المنشأة فقط.
 * Privacy: nothing in these tables is rendered on the public profile. Registry
 * numbers, demographics and financing needs are review-only data.
 *
 * ⚠ لا يُجمع الرقم القومي ولا أرقام الحسابات البنكية في هذه النسخة.
 */
return new class extends Migration {
    public function up(): void
    {
        // ============ ملف المشروع الصغير/المتوسط | SME profile ============
        $this->create('sme_profiles', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,

            -- الوضع القانوني | Formalization (§4.2)
            `formalization_status` ENUM(
                'informal','sole_proprietorship','partnership','llc',
                'joint_stock','cooperative','other'
            ) NOT NULL DEFAULT 'informal',

            -- التسجيل التجاري والضريبي — اختياري لأن كثيراً من المشروعات غير رسمية
            -- Optional: many Egyptian SMEs are informal at the point of registration.
            `commercial_register_no` VARCHAR(50) NULL,
            `commercial_register_date` DATE NULL,
            `tax_registration_no` VARCHAR(50) NULL,
            `industrial_register_no` VARCHAR(50) NULL,

            -- بيانات النشاط | Business facts
            `establishment_date` DATE NULL,
            `company_size` ENUM('micro','small','medium') NULL
                COMMENT 'متناهي الصغر | صغير | متوسط',
            `employees_count` SMALLINT UNSIGNED NULL,
            `female_employees_count` SMALLINT UNSIGNED NULL,
            `annual_revenue_range` ENUM(
                'under_250k','250k_1m','1m_5m','5m_20m','20m_50m','over_50m','prefer_not_say'
            ) NULL COMMENT 'نطاق الإيراد السنوي بالجنيه المصري',

            -- التصدير | Export status
            `is_exporting` TINYINT(1) NOT NULL DEFAULT 0,
            `export_countries` VARCHAR(500) NULL,
            `export_readiness` ENUM('not_interested','interested','preparing','exporting') NULL,

            -- الاحتياجات | Needs (تغذّي محرك المطابقة في المرحلة الرابعة)
            `financing_needs` VARCHAR(1000) NULL COMMENT 'قائمة مفصولة بفواصل',
            `financing_amount_needed` DECIMAL(14,2) NULL,
            `bds_needs` VARCHAR(1000) NULL COMMENT 'الاحتياجات غير المالية',

            -- بيانات الملكية الديموغرافية | Ownership demographics
            -- تُجمع لأغراض التقارير التنموية للمبادرة، وتُتاح فيها خيارات
            -- «أفضّل عدم الإفصاح» ولا تظهر علناً.
            `owner_gender` ENUM('male','female','prefer_not_say') NULL,
            `owner_age_group` ENUM('under_25','25_35','36_45','46_60','over_60','prefer_not_say') NULL,
            `is_youth_led` TINYINT(1) NULL,
            `is_women_led` TINYINT(1) NULL,

            -- روابط التواصل | Social links (public)
            `facebook_url` VARCHAR(255) NULL,
            `instagram_url` VARCHAR(255) NULL,
            `linkedin_url` VARCHAR(255) NULL,
            `whatsapp_number` VARCHAR(30) NULL,

            -- بيانات مسؤول التواصل | Contact person
            `contact_person_name` VARCHAR(150) NULL,
            `contact_person_role` VARCHAR(100) NULL,
            `contact_person_phone` VARCHAR(30) NULL,
            `contact_person_email` VARCHAR(190) NULL,

            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_sme_profiles_org` (`organization_id`),
            KEY `idx_sme_profiles_size` (`company_size`),
            KEY `idx_sme_profiles_formalization` (`formalization_status`),
            CONSTRAINT `fk_sme_profiles_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        // ============ ملف مقدّم الخدمة | Provider profile ============
        // يشمل مقدّمي الخدمات والبنوك والمنظمات الأهلية والجهات الحكومية.
        $this->create('provider_profiles', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,

            `provider_kind` VARCHAR(30) NOT NULL DEFAULT 'service_provider'
                COMMENT 'service_provider|bank|ngo|government',

            -- التخصص والخبرة | Specialisation
            `specializations` VARCHAR(1000) NULL COMMENT 'قائمة مفصولة بفواصل',
            `years_experience` SMALLINT UNSIGNED NULL,
            `team_size` SMALLINT UNSIGNED NULL,
            `languages` VARCHAR(200) NULL,

            -- التغطية الجغرافية | Coverage
            `serves_all_governorates` TINYINT(1) NOT NULL DEFAULT 0,
            `service_governorates` VARCHAR(500) NULL COMMENT 'معرّفات المحافظات مفصولة بفواصل',
            `serves_remotely` TINYINT(1) NOT NULL DEFAULT 0,

            -- الترخيص | Licensing (مراجعة داخلية فقط)
            `license_number` VARCHAR(100) NULL,
            `license_authority` VARCHAR(150) NULL,
            `license_expiry` DATE NULL,
            `certifications` VARCHAR(1000) NULL,

            -- بيانات مسؤول التواصل | Contact person
            `contact_person_name` VARCHAR(150) NULL,
            `contact_person_role` VARCHAR(100) NULL,
            `contact_person_phone` VARCHAR(30) NULL,
            `contact_person_email` VARCHAR(190) NULL,

            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_provider_profiles_org` (`organization_id`),
            KEY `idx_provider_profiles_kind` (`provider_kind`),
            CONSTRAINT `fk_provider_profiles_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        // ============ مركز تطوير الأعمال | BDS centre ============
        $this->create('bds_centers', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,

            `center_code` VARCHAR(30) NULL COMMENT 'الرمز التعريفي للمركز',
            `host_entity` VARCHAR(200) NULL COMMENT 'الجهة المستضيفة للمركز',

            -- التغطية والطاقة | Coverage and capacity
            `serves_all_governorates` TINYINT(1) NOT NULL DEFAULT 0,
            `coverage_governorates` VARCHAR(500) NULL,
            `services_offered` VARCHAR(2000) NULL COMMENT 'فئات الخدمات غير المالية',
            `monthly_capacity` SMALLINT UNSIGNED NULL COMMENT 'عدد الحالات شهرياً',
            `specialists_count` SMALLINT UNSIGNED NULL,

            -- التشغيل | Operations
            `working_hours` VARCHAR(255) NULL,
            `appointment_required` TINYINT(1) NOT NULL DEFAULT 1,
            `manager_name` VARCHAR(150) NULL,
            `contact_phone` VARCHAR(30) NULL,
            `contact_email` VARCHAR(190) NULL,

            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_bds_centers_org` (`organization_id`),
            KEY `idx_bds_centers_code` (`center_code`),
            CONSTRAINT `fk_bds_centers_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('bds_centers');
        $this->drop('provider_profiles');
        $this->drop('sme_profiles');
    }
};
