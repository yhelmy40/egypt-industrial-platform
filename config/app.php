<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * إعدادات التطبيق العامة | Core application configuration.
 */

$root = dirname(__DIR__);

return [
    'name'     => Env::get('APP_NAME', 'منصة رواد النيل'),
    'name_en'  => 'NilePreneurs SME Platform',
    'env'      => Env::get('APP_ENV', 'production'),
    'debug'    => (bool) Env::get('APP_DEBUG', false),
    'url'      => rtrim((string) Env::get('APP_URL', 'http://localhost'), '/'),
    'version'  => '1.0.0-mvp',
    'key'      => Env::get('APP_KEY', ''),

    // المسار الفرعي عند التثبيت خارج جذر الموقع | Sub-directory install support
    'base_path' => rtrim((string) Env::get('APP_BASE_PATH', ''), '/'),

    'timezone'        => Env::get('APP_TIMEZONE', 'Africa/Cairo'),
    'locale'          => Env::get('APP_LOCALE', 'ar'),
    'fallback_locale' => Env::get('APP_FALLBACK_LOCALE', 'ar'),
    'currency'        => Env::get('APP_CURRENCY', 'EGP'),
    'country'         => 'EG',

    // المسارات المطلقة | Absolute paths
    'root_path'    => $root,
    'app_path'     => $root . '/app',
    'public_path'  => $root . '/public',
    'storage_path' => $root . '/storage',
    'view_path'    => $root . '/app/Views',
    'lang_path'    => $root . '/resources/lang',

    // الجهة المشغّلة للمنصة | Platform operator (public-facing content)
    'operator' => [
        'name_ar'  => 'مبادرة رواد النيل',
        'name_en'  => 'NilePreneurs Initiative',
        'tagline'  => 'مبادرة قومية لدعم ريادة الأعمال والمشروعات الصغيرة والمتوسطة في مصر',
        'email'    => Env::get('OPERATOR_EMAIL', 'info@nilepreneurs.example'),
        'phone'    => Env::get('OPERATOR_PHONE', ''),
        // شعار قابل للاستبدال حتى تُسلَّم الهوية الرسمية
        // Placeholder logo until official brand assets are supplied.
        'logo'     => 'img/logo-placeholder.svg',
    ],

    // وسم البيانات التجريبية | Demo-data label (§15)
    'demo_label' => 'بيانات تجريبية',
];
