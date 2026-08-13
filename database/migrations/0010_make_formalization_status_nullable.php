<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * تصحيح الوضع القانوني الافتراضي | Fix the default formalization status.
 *
 * كان العمود `NOT NULL DEFAULT 'informal'`، ونتج عن ذلك خطآن:
 *
 *  1. **بيانات غير صحيحة**: كل منشأة جديدة تُسجَّل «غير رسمية» قبل أن تجيب عن
 *     السؤال أصلاً. شركة ذات مسؤولية محدودة تظهر في التقارير كمنشأة غير رسمية
 *     حتى تعدّل ملفها — وتقارير المبادرة عن نسب التقنين تُبنى على هذا الحقل.
 *  2. **مؤشر اكتمال مضلّل**: بند «الوضع القانوني» كان يُحتسب مكتملاً دائماً،
 *     فيرفع النسبة دون أن يقدّم صاحب المنشأة أي بيانات.
 *
 * القيمة NULL الآن تعني «لم يُحدَّد بعد»، وهي حالة مختلفة عن «غير رسمي» التي
 * هي إجابة صريحة.
 *
 * The column was NOT NULL DEFAULT 'informal', which both recorded every new
 * organization as informal before it answered, and made the completeness check
 * unfailable. NULL now means "not stated yet", which is distinct from the
 * explicit answer "informal".
 */
return new class extends Migration {
    public function up(): void
    {
        $this->run(
            "ALTER TABLE `sme_profiles`
             MODIFY COLUMN `formalization_status` ENUM(
                'informal','sole_proprietorship','partnership','llc',
                'joint_stock','cooperative','other'
             ) NULL DEFAULT NULL COMMENT 'NULL = لم يُحدَّد بعد'"
        );

        // الصفوف التي أُنشئت فارغة وأخذت القيمة الافتراضية دون إجابة المستخدم
        // تُعاد إلى «غير محدّد». الصفوف التي اختار أصحابها «غير رسمي» صراحةً لا
        // يمكن تمييزها هنا، لذا يقتصر التصحيح على الملفات التي لم تُحرَّر بعد.
        // Only profiles that were never edited are reset; an explicit "informal"
        // answer on an edited profile is left untouched.
        $this->run(
            "UPDATE `sme_profiles`
                SET `formalization_status` = NULL
              WHERE `formalization_status` = 'informal'
                AND `contact_person_name` IS NULL
                AND `company_size` IS NULL
                AND `establishment_date` IS NULL"
        );
    }

    public function down(): void
    {
        $this->run(
            "UPDATE `sme_profiles` SET `formalization_status` = 'informal'
              WHERE `formalization_status` IS NULL"
        );

        $this->run(
            "ALTER TABLE `sme_profiles`
             MODIFY COLUMN `formalization_status` ENUM(
                'informal','sole_proprietorship','partnership','llc',
                'joint_stock','cooperative','other'
             ) NOT NULL DEFAULT 'informal'"
        );
    }
};
