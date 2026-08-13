<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * إعدادات الأمان | Security configuration (§9).
 */

return [
    // --- كلمات المرور | Passwords ---
    'password' => [
        'min_length'        => (int) Env::get('PASSWORD_MIN_LENGTH', 10),
        'require_mixed_case' => true,
        'require_number'    => true,
        'require_symbol'    => false,
        'algorithm'         => PASSWORD_DEFAULT,
        // كلمات مرور ممنوعة — تشمل كلمات العرض التجريبي حتى لا تصل للإنتاج
        // Blocked passwords — includes the demo credentials so they can never
        // survive into a production deployment.
        'blocklist' => [
            'password', 'password1', '12345678', '123456789', '1234567890',
            'qwerty123', 'admin123', 'welcome123', 'letmein123',
            'Admin@123', 'Demo@1234', 'Test@1234', 'P@ssw0rd',
            'nilepreneurs', 'egypt2024', 'egypt2025',
        ],
    ],

    // --- تحديد المعدّل | Rate limiting ---
    'rate_limit' => [
        'enabled' => (bool) Env::get('RATE_LIMIT_ENABLED', true),

        // key => [محاولات | attempts, نافذة بالدقائق | window minutes]
        'rules' => [
            'login'             => ['attempts' => (int) Env::get('LOGIN_MAX_ATTEMPTS', 5), 'minutes' => (int) Env::get('LOGIN_DECAY_MINUTES', 15)],
            'register'          => ['attempts' => 5,  'minutes' => 60],
            'password_reset'    => ['attempts' => 5,  'minutes' => 60],
            'verify_resend'     => ['attempts' => 5,  'minutes' => 60],
            'contact'           => ['attempts' => 10, 'minutes' => 60],
            'enquiry'           => ['attempts' => 20, 'minutes' => 60],
            'application'       => ['attempts' => 20, 'minutes' => 60],
            'search'            => ['attempts' => 120, 'minutes' => 1],
        ],
    ],

    // --- ترويسات الأمان | Security headers ---
    'headers' => [
        'x_frame_options'        => 'DENY',
        'x_content_type_options' => 'nosniff',
        'referrer_policy'        => 'strict-origin-when-cross-origin',
        'permissions_policy'     => 'geolocation=(), microphone=(), camera=(), payment=()',
        'hsts_enabled'           => (bool) Env::get('HSTS_ENABLED', false),
        'hsts_max_age'           => 31536000,
    ],

    // --- سياسة أمن المحتوى | Content Security Policy ---
    // كل الأصول مستضافة محلياً، لذا يمكن منع 'unsafe-inline' بالكامل.
    // All assets are self-hosted, so 'unsafe-inline' can be forbidden outright.
    'csp' => [
        'enabled'  => (bool) Env::get('CSP_ENABLED', true),
        'report_only' => false,
        'directives' => [
            'default-src'     => "'self'",
            'script-src'      => "'self'",
            'style-src'       => "'self'",
            'img-src'         => "'self' data:",
            'font-src'        => "'self'",
            'connect-src'     => "'self'",
            'frame-ancestors' => "'none'",
            'form-action'     => "'self'",
            'base-uri'        => "'self'",
            'object-src'      => "'none'",
        ],
    ],

    // --- الوكيل العكسي | Reverse proxy ---
    // فعّلها فقط خلف وكيل موثوق، وإلا أمكن انتحال عنوان IP وتجاوز حدّ المعدّل.
    'trust_proxy' => (bool) Env::get('TRUST_PROXY', false),

    // --- التحقق من الحساب | Account verification ---
    'verification' => [
        'token_lifetime_minutes'  => 1440,   // 24 ساعة
        'reset_token_lifetime'    => 60,     // ساعة واحدة
        'require_email_verified'  => true,
    ],

    // --- سجل التدقيق | Audit log ---
    'audit' => [
        'enabled'          => true,
        'retain_days'      => 1095, // 3 سنوات
        'log_reads'        => false, // القراءات العادية لا تُسجّل، عدا تنزيل الوثائق والتصدير
    ],
];
