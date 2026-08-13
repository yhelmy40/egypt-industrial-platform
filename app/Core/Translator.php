<?php

declare(strict_types=1);

namespace App\Core;

/**
 * طبقة الترجمة | Translation layer.
 *
 * متطلّب §5: لا تُكتب النصوص العربية داخل منطق الأعمال، بل في ملفات
 * /resources/lang حتى يمكن إضافة الإنجليزية لاحقاً دون تعديل الكود.
 * Requirement: Arabic strings live in /resources/lang, not inside business
 * logic, so English can be added later without touching code.
 *
 * الاستخدام | Usage: __('auth.login_failed'), __('common.items', ['count' => 5])
 */
final class Translator
{
    /** @var array<string,array<string,mixed>> */
    private static array $loaded = [];

    private static string $locale = 'ar';

    private static string $fallback = 'ar';

    private static string $langPath = '';

    public static function configure(string $langPath, string $locale, string $fallback = 'ar'): void
    {
        self::$langPath = rtrim($langPath, '/');
        self::$locale   = $locale;
        self::$fallback = $fallback;
        self::$loaded   = [];
    }

    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** اتجاه الكتابة للغة الحالية | Text direction for the active locale. */
    public static function direction(): string
    {
        return in_array(self::$locale, ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr';
    }

    /**
     * ترجمة مفتاح | Translate a key.
     *
     * @param array<string,string|int> $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $value = self::lookup($key, self::$locale);

        if ($value === null && self::$fallback !== self::$locale) {
            $value = self::lookup($key, self::$fallback);
        }

        // إن لم توجد الترجمة نُعيد المفتاح نفسه ليظهر النقص بوضوح أثناء التطوير
        // Missing translations return the key so the gap is visible in dev.
        if ($value === null) {
            if (Config::get('app.env') === 'development') {
                Logger::debug('Missing translation', ['key' => $key, 'locale' => self::$locale]);
            }

            return $key;
        }

        foreach ($replace as $search => $replacement) {
            $value = str_replace(':' . $search, (string) $replacement, $value);
        }

        return $value;
    }

    private static function lookup(string $key, string $locale): ?string
    {
        $segments = explode('.', $key);
        $file     = array_shift($segments);

        if ($file === null || $segments === []) {
            return null;
        }

        self::loadFile($file, $locale);

        $value = self::$loaded[$locale][$file] ?? null;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
    }

    private static function loadFile(string $file, string $locale): void
    {
        if (isset(self::$loaded[$locale][$file])) {
            return;
        }

        // منع اجتياز المسارات عبر اسم الملف
        if (preg_match('/^[a-z_]+$/', $file) !== 1) {
            self::$loaded[$locale][$file] = [];

            return;
        }

        $path = self::$langPath . '/' . $locale . '/' . $file . '.php';

        self::$loaded[$locale][$file] = is_file($path) ? (array) require $path : [];
    }

    /** هل المفتاح موجود؟ | Does the key exist? */
    public static function has(string $key): bool
    {
        return self::lookup($key, self::$locale) !== null;
    }

    public static function reset(): void
    {
        self::$loaded = [];
    }
}
