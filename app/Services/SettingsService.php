<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * إعدادات النظام | System settings service.
 *
 * تُقرأ الإعدادات من قاعدة البيانات مرة واحدة لكل طلب وتُخزَّن في الذاكرة،
 * لأنها تُقرأ كثيراً وتتغيّر نادراً.
 * Settings are read once per request and memoised — read often, changed rarely.
 */
final class SettingsService
{
    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        self::load();

        $row = self::$cache[$group . '.' . $key] ?? null;

        if ($row === null) {
            return $default;
        }

        return self::cast($row['value'], (string) $row['value_type']);
    }

    public static function bool(string $group, string $key, bool $default = false): bool
    {
        $value = self::get($group, $key, $default);

        return (bool) $value;
    }

    public static function int(string $group, string $key, int $default = 0): int
    {
        $value = self::get($group, $key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function decimal(string $group, string $key, float $default = 0.0): float
    {
        $value = self::get($group, $key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    /** @return array<string,array<int,array<string,mixed>>> مجمّعة حسب المجموعة */
    public static function grouped(): array
    {
        self::load();

        $grouped = [];
        foreach (self::$cache ?? [] as $row) {
            $grouped[(string) $row['group_key']][] = $row;
        }

        return $grouped;
    }

    public static function set(string $group, string $key, mixed $value, ?int $updatedBy = null): void
    {
        $stored = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        Database::statement(
            'UPDATE system_settings SET value = ?, updated_by = ?
              WHERE group_key = ? AND setting_key = ?',
            [$stored, $updatedBy, $group, $key],
        );

        self::$cache = null;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        foreach (Database::select('SELECT * FROM system_settings') as $row) {
            self::$cache[$row['group_key'] . '.' . $row['setting_key']] = $row;
        }
    }

    private static function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => (int) $value,
            'decimal' => (float) $value,
            'boolean' => in_array((string) $value, ['1', 'true', 'on', 'yes'], true),
            'json'    => json_decode((string) $value, true) ?? [],
            default   => (string) $value,
        };
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
