<?php

declare(strict_types=1);

namespace App\Core;

/**
 * إدارة الجلسة | Secure session management.
 *
 * يطبّق متطلبات §9: كوكيز HttpOnly/Secure/SameSite، تجديد المعرّف بعد تسجيل
 * الدخول، مهلة خمول، وعمر أقصى مطلق للجلسة.
 * Implements the session requirements: hardened cookie flags, ID regeneration
 * after login, idle timeout, and an absolute maximum session age.
 */
final class Session
{
    private static bool $started = false;

    /** للاختبارات: مصفوفة بدل $_SESSION | Test mode uses an array store. */
    private static bool $testMode = false;

    private static array $testStore = [];

    public static function start(): void
    {
        if (self::$started || self::$testMode) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;

            return;
        }

        session_name((string) Config::get('session.name', 'NP_SESSION'));

        session_set_cookie_params([
            'lifetime' => 0, // كوكي جلسة | session cookie
            'path'     => Config::get('app.base_path', '') ?: '/',
            'domain'   => '',
            'secure'   => (bool) Config::get('session.secure', false),
            'httponly' => true,
            'samesite' => (string) Config::get('session.same_site', 'Lax'),
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_start();
        self::$started = true;

        self::enforceLifetimes();
    }

    /**
     * فرض مهلة الخمول والعمر الأقصى | Enforce idle timeout and absolute lifetime.
     */
    private static function enforceLifetimes(): void
    {
        $now      = time();
        $idle     = (int) Config::get('session.lifetime', 120) * 60;
        $absolute = (int) Config::get('session.absolute_lifetime', 720) * 60;

        $lastActivity = self::get('_last_activity');
        $createdAt    = self::get('_created_at');

        if (is_int($lastActivity) && ($now - $lastActivity) > $idle) {
            self::invalidate();
            self::flash('warning', 'انتهت مدة الجلسة بسبب عدم النشاط. يرجى تسجيل الدخول مجدداً.');

            return;
        }

        if (is_int($createdAt) && ($now - $createdAt) > $absolute) {
            self::invalidate();
            self::flash('warning', 'انتهت صلاحية الجلسة. يرجى تسجيل الدخول مجدداً.');

            return;
        }

        self::put('_last_activity', $now);
        if ($createdAt === null) {
            self::put('_created_at', $now);
        }
    }

    private static function &store(): array
    {
        if (self::$testMode) {
            return self::$testStore;
        }

        if (!self::$started) {
            self::start();
        }

        return $_SESSION;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $store = &self::store();

        return $store[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $store        = &self::store();
        $store[$key]  = $value;
    }

    public static function has(string $key): bool
    {
        $store = &self::store();

        return isset($store[$key]);
    }

    public static function forget(string $key): void
    {
        $store = &self::store();
        unset($store[$key]);
    }

    public static function all(): array
    {
        $store = &self::store();

        return $store;
    }

    /**
     * تجديد معرّف الجلسة | Regenerate the session ID.
     * يُستدعى بعد تسجيل الدخول وبعد أي تغيّر في مستوى الصلاحية.
     */
    public static function regenerate(bool $deleteOld = true): void
    {
        if (self::$testMode) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOld);
            self::put('_created_at', time());
        }
    }

    /** إبطال الجلسة بالكامل | Destroy the session and its data. */
    public static function invalidate(): void
    {
        if (self::$testMode) {
            self::$testStore = [];

            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name() ?: 'NP_SESSION', '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        self::$started = false;
    }

    // ---------------- رسائل مؤقتة | Flash messages ----------------

    public static function flash(string $type, string $message): void
    {
        $store              = &self::store();
        $store['_flash'][]  = ['type' => $type, 'message' => $message];
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function pullFlash(): array
    {
        $store    = &self::store();
        $messages = $store['_flash'] ?? [];
        unset($store['_flash']);

        return is_array($messages) ? $messages : [];
    }

    /** حفظ المدخلات لإعادة تعبئة النموذج بعد خطأ | Keep old input for redisplay. */
    public static function flashInput(array $input): void
    {
        // لا تُحفظ كلمات المرور مطلقاً | Passwords are never retained.
        foreach (array_keys($input) as $key) {
            if (str_contains(strtolower((string) $key), 'password')) {
                unset($input[$key]);
            }
        }

        self::put('_old_input', $input);
    }

    public static function pullOldInput(): array
    {
        $old = self::get('_old_input', []);
        self::forget('_old_input');

        return is_array($old) ? $old : [];
    }

    public static function flashErrors(array $errors): void
    {
        self::put('_errors', $errors);
    }

    public static function pullErrors(): array
    {
        $errors = self::get('_errors', []);
        self::forget('_errors');

        return is_array($errors) ? $errors : [];
    }

    // ---------------- وضع الاختبار | Test mode ----------------

    public static function enableTestMode(): void
    {
        self::$testMode  = true;
        self::$testStore = [];
    }

    public static function disableTestMode(): void
    {
        self::$testMode  = false;
        self::$testStore = [];
    }

    public static function clear(): void
    {
        if (self::$testMode) {
            self::$testStore = [];

            return;
        }

        $_SESSION = [];
    }
}
