<?php

declare(strict_types=1);

namespace App\Core;

/**
 * مستودع الإعدادات | Configuration repository.
 *
 * يحمّل كل ملفات /config ويتيح الوصول بصيغة النقطة: config('app.name').
 * Loads every file in /config and exposes dot-notation access.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    public static function load(string $configPath): void
    {
        if (self::$loaded) {
            return;
        }

        foreach (glob($configPath . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            /** @psalm-suppress UnresolvableInclude */
            self::$items[$key] = require $file;
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value    = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** ضبط قيمة في الذاكرة (للاختبارات) | Set an in-memory value (tests). */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref      = &self::$items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                break;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
    }

    public static function all(): array
    {
        return self::$items;
    }

    public static function reset(): void
    {
        self::$items = [];
        self::$loaded = false;
    }
}
