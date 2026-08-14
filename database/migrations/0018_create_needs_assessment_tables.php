<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * تقييم الاحتياجات والمطابقة | Needs assessment and matching (§4.7).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * المطابقة هنا **اقتراح استرشادي لا قرار**. تُرتِّب المنتجات والخدمات المعتمدة
 * حسب مدى مطابقتها لما صرّح به المشروع، وتشرح سبب كل اقتراح بنص مقروء. لا
 * تُنتج تقييماً ائتمانياً ولا موافقة ولا رفضاً — كلاهما ممنوع صراحةً (§14).
 *
 * لذلك يُخزَّن سبب المطابقة (`reasons_ar`) مع كل اقتراح: اقتراح بلا تفسير
 * صندوق أسود، وصاحب المشروع من حقه أن يعرف لماذا ظهر له هذا المنتج تحديداً.
 *
 * Matching is advisory, never a decision. Each suggestion stores the reasons
 * that produced it, because an unexplained suggestion is a black box and the
 * owner is entitled to know why this product appeared for them.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * الأسئلة بيانات لا كود: `assessment_questions` تديرها المنصة، فتعديل الاستبيان
 * لا يحتاج نشر إصدار.
 */
return new class extends Migration {
    public function up(): void
    {
        // --- أسئلة التقييم | Assessment questions (platform-managed) ---
        $this->create('assessment_questions', <<<SQL
            `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(50) NOT NULL,
            `section` VARCHAR(40) NOT NULL
                COMMENT 'finance|market|operations|digital|compliance|skills',
            `question_ar` VARCHAR(500) NOT NULL,
            `help_ar` VARCHAR(500) NULL,
            `answer_type` ENUM('scale','boolean','choice','number','text')
                NOT NULL DEFAULT 'scale',
            -- خيارات سؤال الاختيار: نص مفصول بأسطر، يُقرأ عبر خريطة معروفة
            `options_ar` VARCHAR(1000) NULL,
            `is_required` TINYINT(1) NOT NULL DEFAULT 1,
            -- وزن السؤال في احتساب درجة القسم
            `weight` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_assessment_questions_code` (`code`),
            KEY `idx_assessment_questions_section` (`section`, `is_active`, `sort_order`)
        SQL);

        // --- تقييم المنشأة | An organization's assessment ---
        $this->create('needs_assessments', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL,

            `status` ENUM('draft','completed') NOT NULL DEFAULT 'draft',
            `completed_at` DATETIME NULL DEFAULT NULL,
            `completed_by` INT UNSIGNED NULL,

            -- درجات الأقسام من 0 إلى 100 | Section scores
            `score_finance` TINYINT UNSIGNED NULL,
            `score_market` TINYINT UNSIGNED NULL,
            `score_operations` TINYINT UNSIGNED NULL,
            `score_digital` TINYINT UNSIGNED NULL,
            `score_compliance` TINYINT UNSIGNED NULL,
            `score_skills` TINYINT UNSIGNED NULL,
            `score_overall` TINYINT UNSIGNED NULL,

            -- أضعف قسمين، محسوبان وقت الإكمال | The two weakest sections
            `priority_sections` VARCHAR(120) NULL,

            {$this->timestamps()},
            {$this->softDelete()},

            PRIMARY KEY (`id`),
            KEY `idx_needs_assessments_org` (`organization_id`, `status`, `created_at`),
            CONSTRAINT `fk_needs_assessments_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_needs_assessments_user`
                FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);

        // --- الإجابات | Answers ---
        $this->create('assessment_answers', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `assessment_id` INT UNSIGNED NOT NULL,
            `organization_id` INT UNSIGNED NOT NULL COMMENT 'مكرّر عمداً للتقييد بالمنشأة',
            `question_id` SMALLINT UNSIGNED NOT NULL,
            `answer_value` VARCHAR(500) NULL,
            `answer_score` TINYINT UNSIGNED NULL COMMENT 'القيمة بعد التطبيع 0-100',
            {$this->timestamps()},
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_assessment_answers` (`assessment_id`, `question_id`),
            KEY `idx_assessment_answers_org` (`organization_id`),
            CONSTRAINT `fk_assessment_answers_assessment`
                FOREIGN KEY (`assessment_id`) REFERENCES `needs_assessments` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_assessment_answers_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_assessment_answers_question`
                FOREIGN KEY (`question_id`) REFERENCES `assessment_questions` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        SQL);

        // --- اقتراحات المطابقة | Match suggestions ---
        // تُحفظ لأنها قابلة للتدقيق: يجب أن نستطيع لاحقاً الإجابة عن «لماذا
        // اقتُرح هذا المنتج على هذا المشروع في ذلك التاريخ؟».
        $this->create('match_suggestions', <<<SQL
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` INT UNSIGNED NOT NULL COMMENT 'المشروع المستهدف',
            `assessment_id` INT UNSIGNED NULL COMMENT 'التقييم الذي وُلِّد عنه، إن وُجد',

            `target_type` ENUM('financing_product','service_offering') NOT NULL,
            `target_id` INT UNSIGNED NOT NULL,

            `score` TINYINT UNSIGNED NOT NULL COMMENT 'درجة المطابقة 0-100',
            `reasons_ar` VARCHAR(1000) NOT NULL COMMENT 'تفسير الاقتراح، سطر لكل سبب',

            `status` ENUM('suggested','viewed','acted','dismissed')
                NOT NULL DEFAULT 'suggested',
            `dismissed_reason_ar` VARCHAR(300) NULL,

            {$this->timestamps()},

            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_match_suggestions` (`organization_id`, `target_type`, `target_id`),
            KEY `idx_match_suggestions_org` (`organization_id`, `status`, `score`),
            KEY `idx_match_suggestions_target` (`target_type`, `target_id`),
            CONSTRAINT `fk_match_suggestions_org`
                FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_match_suggestions_assessment`
                FOREIGN KEY (`assessment_id`) REFERENCES `needs_assessments` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        SQL);
    }

    public function down(): void
    {
        $this->drop('match_suggestions');
        $this->drop('assessment_answers');
        $this->drop('needs_assessments');
        $this->drop('assessment_questions');
    }
};
