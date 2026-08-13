<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * قارئ متغيرات البيئة | Minimal .env reader.
 *
 * مكتوب يدوياً لتجنّب اعتماديات خارجية (انظر قرار المعمارية 4.1).
 * Hand-written to avoid a runtime dependency (see architecture decision 4.1).
 *
 * القيم تُحفظ داخلياً فقط ولا تُكتب في $_ENV/getenv() لمنع تسرّبها
 * إلى العمليات الفرعية أو مخرجات phpinfo().
 * Values are kept internal — never written to $_ENV/getenv() — so they cannot
 * leak into sub-processes or phpinfo() output.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($path)) {
            throw new RuntimeException(
                "ملف البيئة غير موجود: {$path} — انسخ .env.example إلى .env"
            );
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException("تعذّرت قراءة ملف البيئة: {$path}");
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key   = trim($parts[0]);
            $value = trim($parts[1]);

            // إزالة التعليقات اللاحقة خارج علامات الاقتباس
            // Strip trailing comments that sit outside quotes.
            if (!str_starts_with($value, '"') && !str_starts_with($value, "'")) {
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = substr($value, 0, $hash);
                }
                $value = trim($value);
            }

            $value = self::unquote($value);

            self::$values[$key] = $value;
        }

        self::$loaded = true;
    }

    private static function unquote(string $value): string
    {
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last  = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    /**
     * قراءة متغيّر مع تحويل تلقائي للأنواع | Read a variable with type coercion.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$values)) {
            return $default;
        }

        $value = self::$values[$key];

        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            ''                 => $default,
            default            => $value,
        };
    }

    /** قراءة متغيّر مطلوب | Read a required variable or fail loudly. */
    public static function require(string $key): mixed
    {
        $value = self::get($key);
        if ($value === null) {
            throw new RuntimeException("متغيّر البيئة المطلوب غير معرّف: {$key}");
        }

        return $value;
    }

    /** للاختبارات فقط | Testing helper: inject values without a file. */
    public static function fake(array $values): void
    {
        self::$values = $values + self::$values;
        self::$loaded = true;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}
