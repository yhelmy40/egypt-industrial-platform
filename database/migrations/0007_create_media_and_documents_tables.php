<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * الوسائط ووثائق المنشآت | Media registry and organization documents (§4.2, §8).
 *
 * قرار التصميم | Design decision:
 * جدول `media` هو السجل الفيزيائي الوحيد لكل ملف مرفوع في المنصة (مسار، نوع،
 * حجم، بصمة، مالك، مستوى ظهور). الجداول الرابطة — `organization_documents`
 * هنا و`listing_images` و`application_documents` لاحقاً — تشير إلى `media_id`
 * بدل تكرار منطق الرفع والتحقق والتفويض في كل وحدة.
 * One physical file registry (`media`) with thin link tables per domain, so
 * upload validation, storage and authorization live in exactly one place.
 *
 * الملفات نفسها تُخزَّن خارج جذر الويب في storage/uploads ولا تُقدَّم إلا عبر
 * متحكّم يتحقق من الملكية ويسجّل التنزيل (§9).
 */
return new class extends Migration {
    public function up(): void
    {
        // --- سجل الملفات | Physical file registry ---
        $this->create('media', <<<SQL
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            -- المالك | Owner (null = ملف تابع للمنصة لا لمنشأة)
            `organization_id` INT UNSIGNED NULL,
            `uploaded_by` INT UNSIGNED NULL,

            -- التخزين | Storage
            `disk_path` VARCHAR(255) NOT NULL COMMENT 'مسار نسبي داخل storage/uploads',
            `original_name` VARCHAR(255) NOT NULL COMMENT 'اسم الملف كما رفعه المستخدم (للعرض فقط)',
            `extension` VARCHAR(10) NOT NULL,
            `mime_type` VARCHAR(100) NOT NULL COMMENT 'النوع الحقيقي من finfo لا من المتصفح',
            `size_bytes` INT UNSIGNED NOT NULL,
            `checksum` CHAR(64) NOT NULL COMMENT 'SHA-256 لكشف التكرار والتلف',

            -- مستوى الظهور | Visibility
            -- private: لا يُقدَّم إلا بعد فحص الملكية والصلاحية
            -- public:  يُعرض على الصفحة العامة للمنشأة (شعار، صورة منتج)
            `visibility` ENUM('private','public') NOT NULL DEFAULT 'private',
            `collection` VARCHAR(40) NOT NULL DEFAULT 'documents'
                COMMENT 'logos|covers|documents|listings|applications|cases|content',

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            KEY `idx_media_org` (`organization_id`, `collection`, `deleted_at`),
            KEY `idx_media_uploader` (`uploaded_by`),
            KEY `idx_media_checksum` (`checksum`),
            CONSTRAINT `fk_media_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_media_uploader`
                FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- أنواع الوثائق | Document types (admin-managed reference data) ---
        // تُدير الإدارة هذه القائمة، وهي التي تحدّد قائمة المستندات المطلوبة
        // لكل نوع منشأة، فلا تُكتب المتطلبات داخل الكود.
        $this->create('document_types', <<<SQL
            `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(50) NOT NULL,
            `name_ar` VARCHAR(150) NOT NULL,
            `description_ar` VARCHAR(500) NULL,
            -- نوع المنشأة الذي ينطبق عليه، أو 'all' للجميع
            `applies_to` VARCHAR(30) NOT NULL DEFAULT 'all',
            `is_required` TINYINT(1) NOT NULL DEFAULT 0,
            `requires_expiry` TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'مستندات لها تاريخ انتهاء مثل السجل التجاري',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_document_types_code` (`code`),
            KEY `idx_document_types_applies` (`applies_to`, `is_active`, `sort_order`)
        SQL);

        // --- وثائق المنشأة | Organization documents ---
        $this->create('organization_documents', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,
            `document_type_id` SMALLINT UNSIGNED NOT NULL,
            `media_id` BIGINT UNSIGNED NOT NULL,

            -- حالة المراجعة | Review state
            `status` ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
            `reviewed_by` INT UNSIGNED NULL,
            `reviewed_at` DATETIME NULL DEFAULT NULL,
            `review_note` VARCHAR(1000) NULL COMMENT 'سبب الرفض أو ملاحظة للمنشأة',

            -- بيانات المستند | Document metadata
            `document_number` VARCHAR(100) NULL,
            `issue_date` DATE NULL,
            `expiry_date` DATE NULL,

            `uploaded_by` INT UNSIGNED NULL,
            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            KEY `idx_org_documents_org` (`organization_id`, `status`, `deleted_at`),
            KEY `idx_org_documents_type` (`document_type_id`),
            KEY `idx_org_documents_media` (`media_id`),
            KEY `idx_org_documents_expiry` (`expiry_date`),
            CONSTRAINT `fk_org_documents_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_org_documents_type`
                FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT `fk_org_documents_media`
                FOREIGN KEY (`media_id`) REFERENCES `media` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_org_documents_reviewer`
                FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_org_documents_uploader`
                FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // ربط شعار المنشأة بسجل الوسائط | Link the organization logo to media
        $this->run(
            'ALTER TABLE `organizations`
             ADD COLUMN `logo_media_id` BIGINT UNSIGNED NULL AFTER `logo_path`,
             ADD CONSTRAINT `fk_organizations_logo_media`
                FOREIGN KEY (`logo_media_id`) REFERENCES `media` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        $this->run('ALTER TABLE `organizations` DROP FOREIGN KEY `fk_organizations_logo_media`');
        $this->run('ALTER TABLE `organizations` DROP COLUMN `logo_media_id`');
        $this->drop('organization_documents');
        $this->drop('document_types');
        $this->drop('media');
    }
};
