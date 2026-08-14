<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * ترتيب ثنائي لأعمدة الأسرار | Binary collation for secret token columns (§9).
 *
 * كل هذه الأعمدة تُقارَن بمساواة مباشرة في جملة WHERE للبتّ في صلاحية سرّ:
 * رمز تتبّع طلب، رمز سلة زائر، بصمة رمز استعادة كلمة مرور أو دعوة أو تفعيل
 * بريد. كانت جميعها بترتيب `utf8mb4_unicode_ci` — أي مقارنة غير حسّاسة لحالة
 * الأحرف وتُطبِّق قواعد تكافؤ يونيكود.
 *
 * لماذا يُصحَّح هذا:
 *  1. **الصواب**: مقارنة السرّ يجب أن تكون بايتاً ببايت. الترتيب غير الحسّاس
 *     يجعل رمزاً بأحرف كبيرة يطابق رمزاً بأحرف صغيرة، فتوسّع مساحة القيم
 *     المقبولة دون أي داعٍ. القيم هنا سداسية عشرية فلا تفقد المنصة عشوائية
 *     فعلية اليوم، لكن الاعتماد على ذلك يعني أن أي تغيير في صيغة التوليد
 *     (Base64 مثلاً، وفيه A و a قيمتان مختلفتان) يتحوّل فوراً إلى ضعف حقيقي.
 *  2. **الأداء**: `ascii_bin` يقارن ويفهرس أسرع من ترتيب يونيكود متعدّد المستويات،
 *     وحجم الفهرس يقلّ إلى الربع (بايت واحد للحرف بدل أربعة).
 *
 * كلمة المرور خارج هذا التصحيح عمداً: بصمتها لا تُقارَن في SQL إطلاقاً بل عبر
 * password_verify في التطبيق.
 *
 * Every column here is compared by direct equality in a WHERE clause to decide
 * whether a secret is valid. Comparing secrets under a case-insensitive Unicode
 * collation is wrong in principle: it silently widens the accepted value space.
 * Today's values are hex so no entropy is actually lost, but relying on that
 * means any change of token format (Base64, where A ≠ a) becomes a real
 * weakness with no code change to notice. ascii_bin also indexes four times
 * smaller. password_hash is excluded deliberately: it is never SQL-compared.
 */
return new class extends Migration {
    /** @var array<int,array{0:string,1:string,2:string,3:string}> */
    private const COLUMNS = [
        ['orders', 'tracking_token', 'CHAR(48)', "NOT NULL COMMENT 'رمز متابعة الطلب للزائر'"],
        ['quotations', 'tracking_token', 'CHAR(48)', "NOT NULL COMMENT 'متابعة الطلب دون حساب'"],
        ['carts', 'guest_token', 'CHAR(64)', "NULL COMMENT 'رمز عشوائي لسلة الزائر'"],
        ['password_resets', 'token_hash', 'CHAR(64)', 'NOT NULL'],
        ['verification_tokens', 'token_hash', 'CHAR(64)', 'NOT NULL'],
        ['organization_invitations', 'token_hash', 'CHAR(64)', 'NOT NULL'],
    ];

    public function up(): void
    {
        $this->applyCollation('ascii COLLATE ascii_bin');
    }

    public function down(): void
    {
        $this->applyCollation('utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    private function applyCollation(string $charset): void
    {
        foreach (self::COLUMNS as [$table, $column, $type, $tail]) {
            $this->run(
                "ALTER TABLE `{$table}`
                 MODIFY COLUMN `{$column}` {$type} CHARACTER SET {$charset} {$tail}"
            );
        }
    }
};
