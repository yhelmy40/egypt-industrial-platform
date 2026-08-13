<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Config;
use App\Core\Database;
use RuntimeException;

/**
 * أساس ملفات البذور | Base seeder.
 *
 * البذور مقسّمة إلى نوعين | Seeders come in two kinds:
 *  - مرجعية (reference): بيانات تشغيلية حقيقية لازمة لعمل النظام
 *    (المحافظات، القطاعات، الأدوار، الصلاحيات) — تعمل في كل البيئات.
 *  - تجريبية (demo): بيانات عرض توضيحية — تُمنع في بيئة الإنتاج، وتُوسم
 *    بـ «بيانات تجريبية» (§15).
 */
abstract class Seeder
{
    /** @var callable(string):void|null */
    protected $output;

    /** هل هذه بذرة عرض توضيحي؟ | Is this demo data? */
    public function isDemo(): bool
    {
        return false;
    }

    /** ترتيب التنفيذ | Execution order (lower runs first). */
    public function order(): int
    {
        return 100;
    }

    abstract public function run(): void;

    public function setOutput(?callable $output): void
    {
        $this->output = $output;
    }

    protected function info(string $message): void
    {
        if ($this->output !== null) {
            ($this->output)($message);
        }
    }

    /**
     * منع بذور العرض في الإنتاج | Refuse demo data in production.
     * متطلّب §15: كلمات المرور المتوقّعة يجب ألّا تصل للإنتاج إطلاقاً.
     */
    protected function guardProduction(): void
    {
        if (Config::get('app.env') === 'production') {
            throw new RuntimeException(
                'بذور البيانات التجريبية ممنوعة في بيئة الإنتاج.'
            );
        }
    }

    /**
     * إدراج أو تحديث حسب مفتاح فريد | Insert or update on a unique key.
     * يجعل تشغيل البذور مراراً آمناً (idempotent).
     */
    protected function upsert(string $table, array $data, array $uniqueKeys): int
    {
        $where  = [];
        $params = [];

        foreach ($uniqueKeys as $key) {
            $where[]  = "`{$key}` = ?";
            $params[] = $data[$key] ?? null;
        }

        $existing = Database::selectOne(
            "SELECT id FROM `{$table}` WHERE " . implode(' AND ', $where) . ' LIMIT 1',
            $params,
        );

        if ($existing !== null) {
            $id      = (int) $existing['id'];
            $updates = [];
            $values  = [];

            foreach ($data as $column => $value) {
                if (in_array($column, $uniqueKeys, true)) {
                    continue;
                }
                $updates[] = "`{$column}` = ?";
                $values[]  = $value;
            }

            if ($updates !== []) {
                $values[] = $id;
                Database::statement(
                    "UPDATE `{$table}` SET " . implode(', ', $updates) . ' WHERE id = ?',
                    $values,
                );
            }

            return $id;
        }

        $columns      = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        return Database::insert(
            "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES ({$placeholders})",
            array_values($data),
        );
    }
}
