-- =====================================================================
-- schema.sql
-- منصة مصر للبحث والتطوير الصناعي | Egypt Industrial R&D Platform
-- بنية قاعدة البيانات | Database schema (MySQL 8, utf8mb4)
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+02:00';
SET foreign_key_checks = 0;

-- إنشاء قاعدة البيانات | Create database
CREATE DATABASE IF NOT EXISTS `egypt_irdp`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
USE `egypt_irdp`;

-- ---------------------------------------------------------------------
-- جداول البحث | Lookup tables
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `knowledge_resources`;
DROP TABLE IF EXISTS `funding_opportunities`;
DROP TABLE IF EXISTS `rd_projects`;
DROP TABLE IF EXISTS `challenge_matches`;
DROP TABLE IF EXISTS `challenges`;
DROP TABLE IF EXISTS `researcher_profiles`;
DROP TABLE IF EXISTS `factories`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `governorates`;
DROP TABLE IF EXISTS `sectors`;

-- القطاعات الصناعية | Industrial sectors
CREATE TABLE `sectors` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name_ar` VARCHAR(120) NOT NULL,
  `name_en` VARCHAR(120) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- المحافظات | Governorates
CREATE TABLE `governorates` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name_ar` VARCHAR(120) NOT NULL,
  `name_en` VARCHAR(120) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- المستخدمون | Users
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(150) NOT NULL,
  `email`      VARCHAR(190) NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `role`       ENUM('admin','factory','researcher','expert','investor') NOT NULL DEFAULT 'factory',
  `phone`      VARCHAR(40)  NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- المصانع | Factories
-- ---------------------------------------------------------------------
CREATE TABLE `factories` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             INT UNSIGNED NULL,
  `name`                VARCHAR(190) NOT NULL,
  `sector_id`           INT UNSIGNED NULL,
  `governorate_id`      INT UNSIGNED NULL,
  `contact_person`      VARCHAR(150) NULL,
  `email`               VARCHAR(190) NULL,
  `phone`               VARCHAR(40)  NULL,
  `products`            TEXT NULL,
  `main_challenges`     TEXT NULL,
  `energy_usage_level`  ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  `production_capacity` VARCHAR(190) NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_factories_sector` (`sector_id`),
  KEY `idx_factories_gov` (`governorate_id`),
  KEY `idx_factories_user` (`user_id`),
  CONSTRAINT `fk_factories_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_factories_sector` FOREIGN KEY (`sector_id`) REFERENCES `sectors`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_factories_gov` FOREIGN KEY (`governorate_id`) REFERENCES `governorates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ملفات الباحثين والخبراء | Researcher / Expert profiles
-- ---------------------------------------------------------------------
CREATE TABLE `researcher_profiles` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`            INT UNSIGNED NULL,
  `profile_type`       ENUM('researcher','expert') NOT NULL DEFAULT 'researcher',
  `name`               VARCHAR(190) NOT NULL,
  `organization`       VARCHAR(190) NULL,
  `specialization`     VARCHAR(190) NOT NULL,
  `expertise_keywords` TEXT NULL,
  `previous_projects`  TEXT NULL,
  `email`              VARCHAR(190) NULL,
  `phone`              VARCHAR(40)  NULL,
  `governorate_id`     INT UNSIGNED NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rp_type` (`profile_type`),
  KEY `idx_rp_gov` (`governorate_id`),
  KEY `idx_rp_user` (`user_id`),
  CONSTRAINT `fk_rp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rp_gov` FOREIGN KEY (`governorate_id`) REFERENCES `governorates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- التحديات الصناعية | Industrial challenges
-- ---------------------------------------------------------------------
CREATE TABLE `challenges` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `factory_id`       INT UNSIGNED NULL,
  `created_by`       INT UNSIGNED NULL,
  `title`            VARCHAR(220) NOT NULL,
  `description`      TEXT NOT NULL,
  `sector_id`        INT UNSIGNED NULL,
  `priority`         ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  `expected_impact`  TEXT NULL,
  `needed_expertise` TEXT NULL,
  `status`           ENUM('pending','open','under_review','matched','in_progress','solved','rejected') NOT NULL DEFAULT 'pending',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ch_status` (`status`),
  KEY `idx_ch_sector` (`sector_id`),
  KEY `idx_ch_factory` (`factory_id`),
  CONSTRAINT `fk_ch_factory` FOREIGN KEY (`factory_id`) REFERENCES `factories`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ch_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ch_sector` FOREIGN KEY (`sector_id`) REFERENCES `sectors`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- مطابقات التحديات | Challenge ↔ researcher/expert matches
-- ---------------------------------------------------------------------
CREATE TABLE `challenge_matches` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `challenge_id`     INT UNSIGNED NOT NULL,
  `profile_id`       INT UNSIGNED NOT NULL,
  `match_score`      INT NOT NULL DEFAULT 0,
  `matched_keywords` TEXT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cm_challenge` (`challenge_id`),
  KEY `idx_cm_profile` (`profile_id`),
  CONSTRAINT `fk_cm_challenge` FOREIGN KEY (`challenge_id`) REFERENCES `challenges`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_profile` FOREIGN KEY (`profile_id`) REFERENCES `researcher_profiles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- مشاريع البحث والتطوير | R&D projects
-- ---------------------------------------------------------------------
CREATE TABLE `rd_projects` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `challenge_id`          INT UNSIGNED NULL,
  `factory_id`            INT UNSIGNED NULL,
  `researcher_profile_id` INT UNSIGNED NULL,
  `title`                 VARCHAR(220) NOT NULL,
  `start_date`            DATE NULL,
  `end_date`              DATE NULL,
  `budget_estimate`       DECIMAL(15,2) NULL,
  `status`                ENUM('planned','active','completed','cancelled') NOT NULL DEFAULT 'planned',
  `expected_outcome`      TEXT NULL,
  `actual_outcome`        TEXT NULL,
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pr_status` (`status`),
  KEY `idx_pr_challenge` (`challenge_id`),
  KEY `idx_pr_factory` (`factory_id`),
  KEY `idx_pr_profile` (`researcher_profile_id`),
  CONSTRAINT `fk_pr_challenge` FOREIGN KEY (`challenge_id`) REFERENCES `challenges`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pr_factory` FOREIGN KEY (`factory_id`) REFERENCES `factories`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pr_profile` FOREIGN KEY (`researcher_profile_id`) REFERENCES `researcher_profiles`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- فرص التمويل | Funding opportunities
-- ---------------------------------------------------------------------
CREATE TABLE `funding_opportunities` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`              INT UNSIGNED NULL,
  `program_name`         VARCHAR(220) NOT NULL,
  `funding_entity`       VARCHAR(190) NOT NULL,
  `eligible_sectors`     VARCHAR(255) NULL,   -- معرّفات قطاعات مفصولة بفواصل | CSV of sector ids
  `max_funding_amount`   DECIMAL(15,2) NULL,
  `application_deadline` DATE NULL,
  `description`          TEXT NULL,
  `contact_details`      TEXT NULL,
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fo_user` (`user_id`),
  CONSTRAINT `fk_fo_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- مكتبة المعرفة | Knowledge hub resources
-- ---------------------------------------------------------------------
CREATE TABLE `knowledge_resources` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(220) NOT NULL,
  `category`    ENUM('research','patent','case_study','regulation','funding','guide') NOT NULL DEFAULT 'research',
  `sector_id`   INT UNSIGNED NULL,
  `description` TEXT NULL,
  `file_path`   VARCHAR(255) NULL,
  `link`        VARCHAR(255) NULL,
  `uploaded_by` INT UNSIGNED NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kr_category` (`category`),
  KEY `idx_kr_sector` (`sector_id`),
  CONSTRAINT `fk_kr_sector` FOREIGN KEY (`sector_id`) REFERENCES `sectors`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_kr_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- الإشعارات | Notifications
-- ---------------------------------------------------------------------
CREATE TABLE `notifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `title`      VARCHAR(190) NOT NULL,
  `message`    TEXT NULL,
  `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_nt_user` (`user_id`),
  KEY `idx_nt_read` (`is_read`),
  CONSTRAINT `fk_nt_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;

-- نهاية المخطط | End of schema
