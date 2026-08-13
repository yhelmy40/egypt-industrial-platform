<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * إعدادات الجلسة | Session configuration.
 */

return [
    'name'               => Env::get('SESSION_NAME', 'NP_SESSION'),
    'lifetime'           => (int) Env::get('SESSION_LIFETIME', 120),           // مهلة الخمول (دقائق)
    'absolute_lifetime'  => (int) Env::get('SESSION_ABSOLUTE_LIFETIME', 720),  // العمر الأقصى (دقائق)
    'secure'             => (bool) Env::get('SESSION_SECURE', false),          // true إلزامياً مع HTTPS
    'same_site'          => Env::get('SESSION_SAMESITE', 'Lax'),
    'http_only'          => true,
];
