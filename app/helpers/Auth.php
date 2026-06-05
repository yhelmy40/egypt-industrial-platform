<?php
/**
 * Auth.php
 * مساعد المصادقة | Authentication helper.
 * يدير جلسة المستخدم الحالي وأدواره.
 */
class Auth
{
    /** تسجيل دخول المستخدم في الجلسة | Log a user into the session */
    public static function login(array $user): void
    {
        // تجديد معرّف الجلسة لمنع تثبيت الجلسة | Prevent session fixation
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'    => (int) $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['user']);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        return $_SESSION['user']['id'] ?? null;
    }

    public static function role(): ?string
    {
        return $_SESSION['user']['role'] ?? null;
    }

    public static function is(string $role): bool
    {
        return self::role() === $role;
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** الاسم العربي للدور | Arabic label of a role */
    public static function roleLabel(?string $role = null): string
    {
        $role = $role ?? self::role();
        return [
            'admin'      => 'مدير الوزارة',
            'factory'    => 'مصنع',
            'researcher' => 'باحث / جامعة',
            'expert'     => 'خبير / استشاري',
            'investor'   => 'مستثمر / جهة تمويل',
        ][$role] ?? $role ?? '';
    }
}
