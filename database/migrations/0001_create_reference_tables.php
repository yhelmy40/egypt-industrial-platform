<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * البيانات المرجعية | Reference data tables.
 *
 * محافظات مصر، المدن، القطاعات والقطاعات الفرعية، وأنواع المنشآت.
 * هذه الجداول تُدار من لوحة الإدارة (§3.1) ويُمنع حذف صفوفها إذا كانت
 * مرتبطة بسجلات أعمال (RESTRICT).
 * Egyptian governorates, cities, sectors/sub-sectors, organization types.
 * Managed by the platform admin; deletion is RESTRICTed while business
 * records still reference them.
 */
return new class extends Migration {
    public function up(): void
    {
        // --- المحافظات | Governorates (27) ---
        $this->create('governorates', <<<'SQL'
            `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(10) NOT NULL,
            `name_ar` VARCHAR(120) NOT NULL,
            `name_en` VARCHAR(120) NULL,
            `region` VARCHAR(60) NULL COMMENT 'الإقليم الجغرافي',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_governorates_code` (`code`),
            KEY `idx_governorates_active` (`is_active`, `sort_order`)
        SQL);

        // --- المدن | Cities ---
        $this->create('cities', <<<'SQL'
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `governorate_id` SMALLINT UNSIGNED NOT NULL,
            `name_ar` VARCHAR(120) NOT NULL,
            `name_en` VARCHAR(120) NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `idx_cities_governorate` (`governorate_id`, `is_active`),
            CONSTRAINT `fk_cities_governorate`
                FOREIGN KEY (`governorate_id`) REFERENCES `governorates` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE
        SQL);

        // --- القطاعات | Business sectors ---
        $this->create('sectors', <<<'SQL'
            `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(20) NOT NULL,
            `name_ar` VARCHAR(150) NOT NULL,
            `name_en` VARCHAR(150) NULL,
            `description_ar` VARCHAR(500) NULL,
            `icon` VARCHAR(60) NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_sectors_code` (`code`),
            KEY `idx_sectors_active` (`is_active`, `sort_order`)
        SQL);

        // --- القطاعات الفرعية | Sub-sectors ---
        $this->create('sub_sectors', <<<'SQL'
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `sector_id` SMALLINT UNSIGNED NOT NULL,
            `name_ar` VARCHAR(150) NOT NULL,
            `name_en` VARCHAR(150) NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_sub_sectors_sector` (`sector_id`, `is_active`),
            CONSTRAINT `fk_sub_sectors_sector`
                FOREIGN KEY (`sector_id`) REFERENCES `sectors` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE
        SQL);

        // --- أنواع المنشآت | Organization types ---
        // النوع يحدّد أي ملف تعريف (SME / provider / bank / NGO / BDS) يُنشأ
        // ويقيّد القوائم في لوحة الإدارة.
        $this->create('organization_types', <<<'SQL'
            `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(30) NOT NULL COMMENT 'sme|bank|ngo|service_provider|bds_center|government',
            `name_ar` VARCHAR(120) NOT NULL,
            `name_en` VARCHAR(120) NULL,
            `description_ar` VARCHAR(500) NULL,
            `requires_verification` TINYINT(1) NOT NULL DEFAULT 1,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_organization_types_code` (`code`)
        SQL);
    }

    public function down(): void
    {
        $this->drop('sub_sectors');
        $this->drop('sectors');
        $this->drop('cities');
        $this->drop('governorates');
        $this->drop('organization_types');
    }
};
