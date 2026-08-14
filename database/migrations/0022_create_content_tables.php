<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * جداول المحتوى | Content tables (§4.11).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ثلاثة أنواع محتوى تديرها المنصة، ويجمعها قرار واحد: **الكتابة ليست النشر.**
 *
 * محرّر المحتوى يكتب ويرسل، ولا ينشر. النشر قرار موثّق باسم متّخذه وتاريخه —
 * نفس القاعدة التي سرت على المنتجات التمويلية وباقات الخدمات في المرحلة
 * الرابعة، لأن السبب واحد: ما يظهر باسم المبادرة يُنسب إليها، فلا يخرج إلا
 * بمراجعة.
 *
 *  1. **المقالات** (`articles`) — مركز المعرفة: أدلة إرشادية للمشروعات.
 *  2. **الأسئلة الشائعة** (`faqs`) — إجابات قصيرة مقسَّمة بأقسام.
 *  3. **الصفحات الثابتة** (`static_pages`) — «من نحن» والشروط والخصوصية.
 *
 * الصفحات الثابتة **بيانات لا كود**: تعديل صياغة الشروط لا يحتاج نشر إصدار،
 * وهو شرط عملي لأن هذه الصياغات تحتاج مراجعة قانونية وتعديلاً متكرّراً.
 * ═══════════════════════════════════════════════════════════════════════════
 */
return new class extends Migration {
    public function up(): void
    {
        // --- المقالات | Knowledge-centre articles ---
        $this->create('articles', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

            `title_ar` VARCHAR(200) NOT NULL,
            `slug` VARCHAR(220) NOT NULL,
            `excerpt_ar` VARCHAR(500) NOT NULL COMMENT 'ملخّص يظهر في القوائم والمشاركة',
            `body_ar` MEDIUMTEXT NOT NULL,

            `category_id` SMALLINT UNSIGNED NULL COMMENT "categories.type = 'article'",
            `cover_media_id` BIGINT UNSIGNED NULL COMMENT 'صورة الغلاف — ملف لا BLOB',

            -- الكتابة ليست النشر | Authoring is not publishing
            `status` ENUM('draft','pending_review','published','rejected','archived')
                NOT NULL DEFAULT 'draft',
            `review_note_ar` VARCHAR(1000) NULL COMMENT 'سبب الرفض يصل للمحرّر',
            `published_by` INT UNSIGNED NULL,
            `published_at` DATETIME NULL DEFAULT NULL,

            `author_id` INT UNSIGNED NULL,
            `author_name_ar` VARCHAR(150) NULL COMMENT 'اسم يُعرض، قد يخالف اسم الحساب',

            `reading_minutes` TINYINT UNSIGNED NULL,
            `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
            `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

            -- وسم البيانات التجريبية يسري على المحتوى كذلك (§15)
            `is_demo` TINYINT(1) NOT NULL DEFAULT 0,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_articles_slug` (`slug`),
            KEY `idx_articles_browse` (`status`, `published_at`, `deleted_at`),
            KEY `idx_articles_category` (`category_id`, `status`),
            KEY `idx_articles_featured` (`is_featured`, `status`, `published_at`),
            FULLTEXT KEY `ft_articles` (`title_ar`, `excerpt_ar`, `body_ar`),
            CONSTRAINT `fk_articles_category`
                FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_articles_media`
                FOREIGN KEY (`cover_media_id`) REFERENCES `media` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_articles_author`
                FOREIGN KEY (`author_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_articles_publisher`
                FOREIGN KEY (`published_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- الأسئلة الشائعة | FAQs ---
        $this->create('faqs', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

            `section` ENUM(
                'general','account','marketplace','financing','services','bds','erp','privacy'
            ) NOT NULL DEFAULT 'general',
            `question_ar` VARCHAR(300) NOT NULL,
            `answer_ar` TEXT NOT NULL,

            `status` ENUM('draft','pending_review','published','rejected','archived')
                NOT NULL DEFAULT 'draft',
            `review_note_ar` VARCHAR(1000) NULL,
            `published_by` INT UNSIGNED NULL,
            `published_at` DATETIME NULL DEFAULT NULL,

            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `is_demo` TINYINT(1) NOT NULL DEFAULT 0,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            KEY `idx_faqs_browse` (`status`, `section`, `sort_order`),
            CONSTRAINT `fk_faqs_publisher`
                FOREIGN KEY (`published_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- الصفحات الثابتة | Platform static pages ---
        // `slug` مفتاح ثابت يعرفه الكود (`about`, `terms`, `privacy`)، فالصفحة
        // تُحرَّر ولا تُحذف: حذف صفحة الشروط يكسر رابطاً في تذييل كل صفحة.
        $this->create('static_pages', <<<SQL
            `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,

            `slug` VARCHAR(60) NOT NULL COMMENT 'مفتاح ثابت يعرفه الكود',
            `title_ar` VARCHAR(200) NOT NULL,
            `body_ar` MEDIUMTEXT NOT NULL,

            `status` ENUM('draft','pending_review','published') NOT NULL DEFAULT 'draft',
            `review_note_ar` VARCHAR(1000) NULL,
            `published_by` INT UNSIGNED NULL,
            `published_at` DATETIME NULL DEFAULT NULL,

            -- الصياغات القانونية تحتاج مراجعة قبل الإطلاق، والوسم يُظهر ذلك للزائر
            `needs_legal_review` TINYINT(1) NOT NULL DEFAULT 1,
            `updated_by` INT UNSIGNED NULL,

            {$this->timestamps()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_static_pages_slug` (`slug`),
            CONSTRAINT `fk_static_pages_publisher`
                FOREIGN KEY (`published_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_static_pages_editor`
                FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('static_pages');
        $this->drop('faqs');
        $this->drop('articles');
    }
};
