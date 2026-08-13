<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Session;

/**
 * حماية ضد تزوير الطلبات | CSRF protection.
 *
 * الرمز يُولَّد مرة لكل جلسة ويُتحقق منه في وسيط مركزي على كل طلب يغيّر
 * الحالة (POST/PUT/PATCH/DELETE) — وليس داخل كل متحكّم على حدة.
 * The token is generated once per session and verified by a single middleware
 * on every state-changing request, not per controller.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /** مقارنة زمنية ثابتة | Constant-time comparison. */
    public static function verify(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $expected = Session::get(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    /**
     * تدوير الرمز | Rotate the token.
     * يُستدعى عند تسجيل الدخول والخروج لمنع تثبيت الرمز.
     */
    public static function rotate(): string
    {
        Session::forget(self::SESSION_KEY);

        return self::token();
    }
}
