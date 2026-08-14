<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;
use RuntimeException;

/**
 * ترقيم المستندات | Sequential document numbering (§4.10).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * الفواتير والإيصالات وأوامر الشراء تُرقَّم **تسلسلياً داخل المنشأة** لا
 * بمعرّف عشوائي. السبب ليس شكلياً: صاحب المشروع يُسأل عن فاتورة برقمها،
 * ومراجع الحسابات يقرأ التسلسل ليعرف إن كانت هناك فاتورة محذوفة. رقم عشوائي
 * يجعل السؤالين بلا إجابة.
 *
 * التسلسل **داخل المنشأة والسنة**: `INV-26-0001`. لا تسلسل عالمي عبر المنصة،
 * فرقم فاتورة مشروع لا يكشف عدد فواتير مشروع آخر.
 *
 * التزامن محكوم بقيد `UNIQUE(organization_id, number)` في قاعدة البيانات، لا
 * بأمل ألّا يتزامن طلبان. عند التصادم يُعاد المحاولة برقم أعلى.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class DocumentNumber
{
    private const MAX_ATTEMPTS = 25;

    /**
     * الرقم التالي في تسلسل المنشأة | The next number in the organization's sequence.
     *
     * @param string $table  الجدول | the table
     * @param string $column عمود الرقم | the number column
     * @param string $prefix البادئة، مثل INV | the prefix
     */
    public static function next(
        string $table,
        string $column,
        int $organizationId,
        string $prefix,
        ?int $attempt = null,
    ): string {
        self::assertIdentifier($table);
        self::assertIdentifier($column);

        $year   = date('y');
        $stem   = $prefix . '-' . $year . '-';
        $offset = $attempt ?? 0;

        // أعلى تسلسل مستخدم هذا العام: يُقرأ من الرقم نفسه لا من عدّاد منفصل،
        // فلا يوجد عدّاد قد يفترق عن الواقع بعد استرجاع نسخة احتياطية.
        $highest = Database::scalar(
            "SELECT MAX(CAST(SUBSTRING(`{$column}`, ?) AS UNSIGNED))
               FROM `{$table}`
              WHERE organization_id = ? AND `{$column}` LIKE ?",
            [strlen($stem) + 1, $organizationId, $stem . '%'],
        );

        $next = (int) ($highest ?? 0) + 1 + $offset;

        return $stem . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * تنفيذ عملية إدراج مع إعادة المحاولة عند تصادم الرقم | Insert with collision retry.
     *
     * `$insert` يستقبل الرقم المقترح ويعيد المعرّف. إن رفضت قاعدة البيانات
     * الرقم لتكراره، يُحاوَل برقم أعلى. القيد الفريد هو الحكم لا هذه الدالة.
     *
     * @param  callable(string):int $insert
     */
    public static function withNumber(
        string $table,
        string $column,
        int $organizationId,
        string $prefix,
        callable $insert,
    ): array {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $number = self::next($table, $column, $organizationId, $prefix, $attempt);

            try {
                return ['id' => $insert($number), 'number' => $number];
            } catch (\PDOException $e) {
                if (!self::isDuplicateKey($e)) {
                    throw $e;
                }
                // تصادم: منشأة أصدرت مستندين في اللحظة نفسها. جرّب الرقم التالي.
            }
        }

        throw new RuntimeException(
            'تعذّر توليد رقم مستند فريد بعد عدة محاولات.',
        );
    }

    private static function isDuplicateKey(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    /** أسماء الجداول والأعمدة تأتي من الكود لا من الطلب، والتحقّق حاجز إضافي. */
    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $identifier) !== 1) {
            throw new RuntimeException('اسم جدول أو عمود غير صالح.');
        }
    }
}
