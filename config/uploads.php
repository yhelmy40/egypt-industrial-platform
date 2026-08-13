<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * إعدادات رفع الملفات | File upload configuration (§9).
 *
 * الملفات تُخزَّن خارج جذر الويب في /storage/uploads ولا تُقدَّم إلا عبر
 * متحكّم يتحقق من الملكية ويسجّل التنزيل.
 * Files live outside the web root and are only served through a controller
 * that verifies ownership and logs the download.
 */

return [
    'root'          => dirname(__DIR__) . '/storage/uploads',
    'max_size'      => (int) Env::get('UPLOAD_MAX_SIZE', 5 * 1024 * 1024),
    'doc_max_size'  => (int) Env::get('UPLOAD_DOC_MAX_SIZE', 10 * 1024 * 1024),

    /**
     * قوائم السماح | Allow-lists.
     * التحقق يتم بالامتداد ونوع MIME الحقيقي (finfo) معاً — لا يكفي أحدهما.
     * Validation checks both the extension and the real MIME type (finfo);
     * neither alone is sufficient.
     */
    'types' => [
        'image' => [
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
            'max_size'   => 5 * 1024 * 1024,
            // إعادة ترميز الصور لإزالة أي حمولة مدسوسة
            // Images are re-encoded to strip any embedded payload.
            're_encode'  => true,
        ],
        'document' => [
            'extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
            'mimes'      => ['application/pdf', 'image/jpeg', 'image/png'],
            'max_size'   => 10 * 1024 * 1024,
            're_encode'  => false,
        ],
        'spreadsheet' => [
            'extensions' => ['xlsx', 'csv'],
            'mimes'      => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/csv',
                'text/plain',
            ],
            'max_size'   => 5 * 1024 * 1024,
            're_encode'  => false,
        ],
    ],

    // امتدادات ممنوعة دائماً حتى لو مرّت الفحوص الأخرى
    // Always-forbidden extensions, regardless of other checks.
    'forbidden_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'exe', 'sh', 'bash', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py', 'rb',
        'js', 'jsp', 'asp', 'aspx', 'htaccess', 'htm', 'html', 'svg',
    ],

    // مجلدات منطقية | Logical sub-directories
    'directories' => [
        'logos'       => 'organizations/logos',
        'covers'      => 'organizations/covers',
        'documents'   => 'organizations/documents',
        'listings'    => 'listings',
        'applications' => 'applications',
        'cases'       => 'bds-cases',
        'content'     => 'content',
        'messages'    => 'messages',
    ],
];
