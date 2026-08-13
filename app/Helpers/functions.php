<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Router;
use App\Core\Session;
use App\Core\Translator;
use App\Support\Csrf;

/**
 * دوال مساعدة عامة | Global helper functions.
 * تُحمَّل عبر composer autoload files.
 */

if (!function_exists('e')) {
    /**
     * هروب HTML | Escape for HTML output.
     *
     * دفاع أساسي ضد XSS: كل قيمة يُدخلها المستخدم تمرّ من هنا قبل العرض.
     * Primary XSS defence — every user-supplied value passes through this
     * before rendering.
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('e_attr')) {
    /** هروب لسياق الخاصيّة | Escape for an HTML attribute context. */
    function e_attr(mixed $value): string
    {
        return e($value);
    }
}

if (!function_exists('e_js')) {
    /** تمرير قيمة إلى JavaScript بأمان | Safely embed a value in JavaScript. */
    function e_js(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        return $json === false ? 'null' : $json;
    }
}

if (!function_exists('__')) {
    /** ترجمة | Translate a key. */
    function __(string $key, array $replace = []): string
    {
        return Translator::get($key, $replace);
    }
}

if (!function_exists('__e')) {
    /** ترجمة + هروب | Translate and escape (the common case in templates). */
    function __e(string $key, array $replace = []): string
    {
        return e(Translator::get($key, $replace));
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('url')) {
    /** بناء رابط مطلق داخل التطبيق | Build an application URL. */
    function url(string $path = '/'): string
    {
        $base = rtrim((string) Config::get('app.base_path', ''), '/');
        $path = '/' . ltrim($path, '/');

        return $base . ($path === '/' ? '/' : rtrim($path, '/'));
    }
}

if (!function_exists('route')) {
    /** رابط من اسم مسار | URL from a named route. */
    function route(string $name, array $params = []): string
    {
        $path = Router::pathFor($name, $params);

        return url($path ?? '/');
    }
}

if (!function_exists('asset')) {
    /** رابط ملف ثابت مع بصمة لإبطال التخزين المؤقت | Static asset URL with cache-busting. */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $full = (string) Config::get('app.public_path') . '/assets/' . $path;
        $mtime = is_file($full) ? (string) filemtime($full) : (string) Config::get('app.version', '1');

        return url('/assets/' . $path) . '?v=' . substr(md5($mtime), 0, 8);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    /** حقل CSRF جاهز للنماذج | Ready-made CSRF hidden field. */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
    }
}

if (!function_exists('method_field')) {
    /** محاكاة PUT/DELETE في نماذج HTML | Spoof PUT/DELETE in HTML forms. */
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
    }
}

if (!function_exists('old')) {
    /** استرجاع قيمة سابقة بعد فشل التحقق | Repopulate a field after a failed submit. */
    function old(string $key, mixed $default = ''): mixed
    {
        $old = Session::get('_old_input_current', []);

        return is_array($old) ? ($old[$key] ?? $default) : $default;
    }
}

if (!function_exists('error_for')) {
    /** رسالة خطأ لحقل | Validation error for a field. */
    function error_for(string $key): ?string
    {
        $errors = Session::get('_errors_current', []);

        return is_array($errors) ? ($errors[$key] ?? null) : null;
    }
}

if (!function_exists('has_error')) {
    function has_error(string $key): bool
    {
        return error_for($key) !== null;
    }
}

if (!function_exists('money')) {
    /**
     * تنسيق مبلغ مالي | Format a monetary amount.
     * العملة الافتراضية: الجنيه المصري | Default currency: EGP.
     */
    function money(float|int|string $amount, ?string $currency = null): string
    {
        $currency = $currency ?? (string) Config::get('app.currency', 'EGP');
        $symbol   = match ($currency) {
            'EGP'   => 'ج.م',
            'USD'   => '$',
            'EUR'   => '€',
            default => $currency,
        };

        return number_format((float) $amount, 2, '.', ',') . ' ' . $symbol;
    }
}

if (!function_exists('number_ar')) {
    /** تنسيق رقم | Format a number with thousands separators. */
    function number_ar(float|int|string $number, int $decimals = 0): string
    {
        return number_format((float) $number, $decimals, '.', ',');
    }
}

if (!function_exists('format_date')) {
    /** تاريخ ميلادي بصيغة واضحة | Format a date clearly. */
    function format_date(?string $datetime, bool $withTime = false): string
    {
        if ($datetime === null || $datetime === '' || str_starts_with($datetime, '0000')) {
            return '—';
        }

        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return '—';
        }

        $months = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
            5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
        ];

        $formatted = sprintf(
            '%d %s %d',
            (int) date('j', $timestamp),
            $months[(int) date('n', $timestamp)],
            (int) date('Y', $timestamp),
        );

        if ($withTime) {
            $formatted .= ' — ' . date('H:i', $timestamp);
        }

        return $formatted;
    }
}

if (!function_exists('time_ago')) {
    /** منذ كم من الوقت | Relative time in Arabic. */
    function time_ago(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '—';
        }

        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return '—';
        }

        $diff = time() - $timestamp;

        return match (true) {
            $diff < 60      => 'منذ لحظات',
            $diff < 3600    => 'منذ ' . (int) ($diff / 60) . ' دقيقة',
            $diff < 86400   => 'منذ ' . (int) ($diff / 3600) . ' ساعة',
            $diff < 2592000 => 'منذ ' . (int) ($diff / 86400) . ' يوم',
            default         => format_date($datetime),
        };
    }
}

if (!function_exists('str_excerpt')) {
    /** مقتطف نصي | Truncate text safely for multibyte Arabic. */
    function str_excerpt(?string $text, int $length = 150): string
    {
        $text = trim(strip_tags((string) $text));

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . '…';
    }
}

if (!function_exists('mask_email')) {
    /**
     * إخفاء جزئي للبريد | Partially mask an email address.
     * متطلّب §11: عدم كشف عناوين البريد الخاصة في المحادثات.
     */
    function mask_email(?string $email): string
    {
        if ($email === null || !str_contains($email, '@')) {
            return '—';
        }

        [$user, $domain] = explode('@', $email, 2);
        $visible         = mb_substr($user, 0, 2);

        return $visible . str_repeat('*', max(3, mb_strlen($user) - 2)) . '@' . $domain;
    }
}

if (!function_exists('mask_phone')) {
    /** إخفاء جزئي لرقم الهاتف | Partially mask a phone number. */
    function mask_phone(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '—';
        }

        $len = mb_strlen($phone);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4) . mb_substr($phone, -4);
    }
}
