<?php

declare(strict_types=1);

/**
 * تهيئة بيئة الاختبار | Test bootstrap.
 *
 * ينشئ قاعدة بيانات اختبار منفصلة وينفّذ عليها الترحيلات والبذور المرجعية.
 * قاعدة الإنتاج لا تُمسّ إطلاقاً: الاسم يأتي من DB_TEST_DATABASE، ويُرفض
 * التشغيل إذا تطابق مع اسم قاعدة التطوير/الإنتاج.
 * Creates a separate test database and migrates it. The production database is
 * never touched: the name comes from DB_TEST_DATABASE and the run is refused if
 * it matches the primary database name.
 */

use App\Core\Application;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;
use Database\Seeders\SeederRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);

// APP_ENV=testing يجعل config/database.php يختار قاعدة الاختبار
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

Env::load($basePath . '/.env');
Env::fake(['APP_ENV' => 'testing', 'APP_DEBUG' => 'true']);

Application::boot($basePath);

$testDatabase = (string) Config::get('database.test_database');
$mainDatabase = (string) Env::get('DB_DATABASE', 'nilepreneurs');

if ($testDatabase === '' || $testDatabase === $mainDatabase) {
    fwrite(STDERR, "\n✗ DB_TEST_DATABASE يجب أن يكون قاعدة منفصلة عن DB_DATABASE.\n");
    exit(1);
}

// إنشاء قاعدة الاختبار
$socket = (string) Config::get('database.socket', '');
$dsn    = $socket !== ''
    ? 'mysql:unix_socket=' . $socket
    : sprintf('mysql:host=%s;port=%s', Config::get('database.host'), Config::get('database.port'));

$pdo = new PDO(
    $dsn,
    (string) Config::get('database.username'),
    (string) Config::get('database.password', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

if (preg_match('/^[A-Za-z0-9_]+$/', $testDatabase) !== 1) {
    fwrite(STDERR, "\n✗ اسم قاعدة الاختبار غير صالح.\n");
    exit(1);
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$testDatabase}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// إعادة بناء المخطط من الصفر لكل تشغيل — يضمن أن الاختبارات تعمل على مخطط
// مطابق تماماً للترحيلات، لا على بقايا تشغيل سابق.
$migrator = new Migrator($basePath . '/database/migrations');
$migrator->fresh();

// البيانات المرجعية فقط — بذور العرض التوضيحي لا تُحمَّل، فكل اختبار يبني
// بياناته صراحةً حتى يبقى مستقلاً ومقروءاً.
$runner = new SeederRunner($basePath . '/database/seeders');
$runner->run('GovernoratesSeeder');
$runner->run('SectorsSeeder');
$runner->run('OrganizationTypesSeeder');
$runner->run('DocumentTypesSeeder');
$runner->run('CategoriesSeeder');
$runner->run('PaymentMethodsSeeder');
$runner->run('AssessmentQuestionsSeeder');
$runner->run('RolesAndPermissionsSeeder');
$runner->run('SystemSettingsSeeder');
// الصفحات الثابتة بذرة مرجعية لا تجريبية: روابطها في تذييل كل صفحة، فغيابها
// يكسر مساراً عاماً. تُزرع في الاختبارات كما تُزرع في الإنتاج تماماً.
$runner->run('StaticPagesSeeder');

Database::disconnect();

echo "\n✓ قاعدة بيانات الاختبار جاهزة: {$testDatabase}\n";
