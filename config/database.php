<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * إعدادات قاعدة البيانات | Database configuration.
 *
 * الهدف الإنتاجي: MySQL 8. بيئة التطوير قد تستخدم MariaDB 10.11 المتوافقة،
 * لذا يلتزم مخطط الجداول بالمجموعة المشتركة بين النظامين.
 * Production target is MySQL 8. Development may run the compatible MariaDB
 * 10.11, so the schema stays within the common subset of both.
 */

$isTesting = Env::get('APP_ENV') === 'testing';

return [
    'host'      => Env::get('DB_HOST', '127.0.0.1'),
    'port'      => (string) Env::get('DB_PORT', '3306'),
    'socket'    => Env::get('DB_SOCKET', ''),
    'database'  => $isTesting
        ? Env::get('DB_TEST_DATABASE', 'nilepreneurs_test')
        : Env::get('DB_DATABASE', 'nilepreneurs'),
    'username'  => Env::get('DB_USERNAME', 'root'),
    'password'  => Env::get('DB_PASSWORD', ''),
    'charset'   => Env::get('DB_CHARSET', 'utf8mb4'),
    'collation' => Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),

    // قاعدة الاختبارات — تُفرَّغ في كل تشغيل | Test DB, truncated each run
    'test_database' => Env::get('DB_TEST_DATABASE', 'nilepreneurs_test'),

    'migrations_table' => 'migrations',
];
