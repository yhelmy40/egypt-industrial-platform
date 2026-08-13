<?php
/**
 * Csrf.php
 * مساعد رمز الحماية ضد تزوير الطلبات | CSRF token helper.
 */
class Csrf
{
    /** الحصول على الرمز الحالي أو توليد رمز جديد | Get or generate token */
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /** التحقق من صحة الرمز | Verify a submitted token */
    public static function verify(?string $token): bool
    {
        return !empty($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    /** حقل مخفي جاهز للنماذج | Ready-to-use hidden form field */
    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . self::token() . '">';
    }
}
