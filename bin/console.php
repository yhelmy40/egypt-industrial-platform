<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Application;
use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use PDO;
use Throwable;

/**
 * واجهة سطر الأوامر | Command-line interface.
 *
 * الأوامر | Commands:
 *   migrate                تنفيذ الترحيلات المعلّقة
 *   migrate:status         عرض حالة الترحيلات
 *   migrate:rollback       التراجع عن آخر دفعة
 *   migrate:fresh          إعادة بناء كاملة (ممنوعة في الإنتاج)
 *   db:create              إنشاء قاعدة البيانات إن لم تكن موجودة
 *   seed [--class=Name]    تشغيل بذور البيانات
 *   key:generate           توليد مفتاح التطبيق
 *   health                 فحص جاهزية النظام
 */
final class Console
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $options = $this->parseOptions(array_slice($argv, 2));

        // الأمر key:generate قد يعمل قبل وجود .env كاملة
        try {
            Application::boot($this->basePath);
        } catch (Throwable $e) {
            if ($command !== 'help') {
                $this->error($e->getMessage());

                return 1;
            }
        }

        try {
            return match ($command) {
                'migrate'          => $this->migrate(),
                'migrate:status'   => $this->migrateStatus(),
                'migrate:rollback' => $this->migrateRollback(),
                'migrate:fresh'    => $this->migrateFresh($options),
                'db:create'        => $this->dbCreate(),
                'seed'             => $this->seed($options),
                'key:generate'     => $this->keyGenerate(),
                'health'           => $this->health(),
                default            => $this->help(),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            if (Config::get('app.debug', false) === true) {
                $this->line($e->getTraceAsString());
            }

            return 1;
        }
    }

    // ---------------- الأوامر | Commands ----------------

    private function migrate(): int
    {
        $this->title('تنفيذ الترحيلات | Running migrations');

        $migrator = new Migrator($this->basePath . '/database/migrations');
        $done     = $migrator->migrate(fn (string $m) => $this->line('  ' . $m));

        $this->success('تم تنفيذ ' . count($done) . ' ترحيلاً.');

        return 0;
    }

    private function migrateStatus(): int
    {
        $this->title('حالة الترحيلات | Migration status');

        $migrator = new Migrator($this->basePath . '/database/migrations');
        $ran      = $migrator->ran();
        $pending  = $migrator->pending();

        foreach ($ran as $name) {
            $this->line("  \033[32m✓\033[0m {$name}");
        }

        foreach ($pending as $name) {
            $this->line("  \033[33m•\033[0m {$name} (معلّق)");
        }

        $this->line('');
        $this->line('  منفّذ: ' . count($ran) . '  |  معلّق: ' . count($pending));

        return 0;
    }

    private function migrateRollback(): int
    {
        $this->title('التراجع عن آخر دفعة | Rolling back last batch');

        $migrator = new Migrator($this->basePath . '/database/migrations');
        $done     = $migrator->rollback(fn (string $m) => $this->line('  ' . $m));

        $this->success('تم التراجع عن ' . count($done) . ' ترحيلاً.');

        return 0;
    }

    private function migrateFresh(array $options): int
    {
        if (Config::get('app.env') === 'production') {
            $this->error('ممنوع في بيئة الإنتاج.');

            return 1;
        }

        if (!isset($options['force'])) {
            $this->error('هذا الأمر يحذف كل الجداول. أعد التشغيل مع --force للتأكيد.');

            return 1;
        }

        $this->title('إعادة بناء قاعدة البيانات | Fresh migration');

        $migrator = new Migrator($this->basePath . '/database/migrations');
        $done     = $migrator->fresh(fn (string $m) => $this->line('  ' . $m));

        $this->success('تم تنفيذ ' . count($done) . ' ترحيلاً من الصفر.');

        return 0;
    }

    /** إنشاء قاعدة البيانات | Create the database if absent. */
    private function dbCreate(): int
    {
        $name      = (string) Config::get('database.database');
        $charset   = (string) Config::get('database.charset', 'utf8mb4');
        $collation = (string) Config::get('database.collation', 'utf8mb4_unicode_ci');

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

        // اسم القاعدة يأتي من الإعدادات لا من مدخلات المستخدم، ومع ذلك نتحقق
        // من صيغته قبل إدراجه في الجملة لأن أسماء الكيانات لا يمكن ربطها.
        // The name comes from config, not user input, but identifiers cannot be
        // bound so the format is validated before interpolation.
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            $this->error('اسم قاعدة البيانات غير صالح: ' . $name);

            return 1;
        }

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET {$charset} COLLATE {$collation}");
        $this->success("قاعدة البيانات جاهزة: {$name}");

        return 0;
    }

    private function seed(array $options): int
    {
        $this->title('تشغيل بذور البيانات | Seeding');

        $only = $options['class'] ?? null;

        $runner = new \Database\Seeders\SeederRunner(
            $this->basePath . '/database/seeders',
            fn (string $m) => $this->line('  ' . $m),
        );

        $count = $runner->run(is_string($only) ? $only : null);

        $this->success("تم تشغيل {$count} من ملفات البذور.");

        return 0;
    }

    private function keyGenerate(): int
    {
        $key     = bin2hex(random_bytes(32));
        $envPath = $this->basePath . '/.env';

        if (!is_file($envPath)) {
            $this->error('ملف .env غير موجود. انسخ .env.example أولاً.');

            return 1;
        }

        $contents = (string) file_get_contents($envPath);

        $contents = preg_match('/^APP_KEY=.*$/m', $contents) === 1
            ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents)
            : $contents . "\nAPP_KEY=" . $key . "\n";

        file_put_contents($envPath, $contents);
        chmod($envPath, 0600);

        $this->success('تم توليد مفتاح التطبيق وحفظه في .env');

        return 0;
    }

    /** فحص الجاهزية | Health check used by monitoring and deployment. */
    private function health(): int
    {
        $this->title('فحص جاهزية النظام | Health check');

        $ok = true;

        $checks = [
            'PHP >= 8.3'        => version_compare(PHP_VERSION, '8.3.0', '>='),
            'ext-pdo_mysql'     => extension_loaded('pdo_mysql'),
            'ext-mbstring'      => extension_loaded('mbstring'),
            'ext-fileinfo'      => extension_loaded('fileinfo'),
            'APP_KEY محدّد'      => (string) Config::get('app.key', '') !== '',
            'storage قابل للكتابة' => is_writable((string) Config::get('app.storage_path')),
            'uploads خارج جذر الويب' => !str_starts_with(
                (string) Config::get('uploads.root'),
                (string) Config::get('app.public_path'),
            ),
        ];

        try {
            Database::scalar('SELECT 1');
            $checks['اتصال قاعدة البيانات'] = true;
        } catch (Throwable) {
            $checks['اتصال قاعدة البيانات'] = false;
        }

        // ═══ فحوص الإنتاج | Production-only checks ═══
        // هذه هي بوابة ما قبل النشر: إعداد خاطئ واحد هنا يعني منصة تعمل
        // بجلسات غير مؤمّنة أو بأخطاء تكشف مسارات الخادم للزائر. الأمر يعيد
        // رمز خروج غير صفري فيوقف أي سكربت نشر يفحص النتيجة.
        if (Config::get('app.env') === 'production') {
            $checks['APP_DEBUG معطّل']            = Config::get('app.debug') === false;
            $checks['الكوكيز آمنة (HTTPS)']        = Config::get('session.secure') === true;
            $checks['HSTS مفعّل']                  = Config::get('security.headers.hsts_enabled') === true;
            $checks['مفتاح التطبيق ليس افتراضياً'] = !in_array(
                (string) Config::get('app.key', ''),
                ['', 'base64:CHANGE_ME', 'CHANGE_ME'],
                true,
            );

            // البذور التجريبية تمتنع عن العمل في الإنتاج، والفحص يؤكّد أنها
            // لم تُزرع في قاعدة إنتاج بالخطأ قبل ضبط البيئة.
            try {
                $demoUsers = (int) Database::scalar(
                    "SELECT COUNT(*) FROM users WHERE email LIKE '%@nilepreneurs.test'",
                );
                $checks['لا حسابات تجريبية'] = $demoUsers === 0;
            } catch (Throwable) {
                $checks['لا حسابات تجريبية'] = false;
            }

            try {
                $demoData = (int) Database::scalar(
                    'SELECT (SELECT COUNT(*) FROM articles WHERE is_demo = 1)
                          + (SELECT COUNT(*) FROM financing_products WHERE is_demo = 1)',
                );
                $checks['لا بيانات تجريبية منشورة'] = $demoData === 0;
            } catch (Throwable) {
                $checks['لا بيانات تجريبية منشورة'] = false;
            }
        }

        // ═══ فحوص تسري في كل البيئات | Checks that apply everywhere ═══
        try {
            $pending = (new Migrator($this->basePath . '/database/migrations'))->pending();
            $checks['لا ترحيلات معلّقة'] = $pending === [];
        } catch (Throwable) {
            $checks['لا ترحيلات معلّقة'] = false;
        }

        // الصفحات الثابتة شرط تشغيل لا بيانات عرض: روابطها في تذييل كل صفحة
        try {
            $pages = (int) Database::scalar(
                "SELECT COUNT(*) FROM static_pages WHERE slug IN ('about','terms','privacy')
                  AND status = 'published'",
            );
            $checks['الصفحات الثابتة منشورة'] = $pages === 3;
        } catch (Throwable) {
            $checks['الصفحات الثابتة منشورة'] = false;
        }

        $checks['ملف .env خارج جذر الويب'] = !file_exists(
            (string) Config::get('app.public_path') . '/.env',
        );

        foreach ($checks as $label => $passed) {
            $this->line(($passed ? "  \033[32m✓\033[0m " : "  \033[31m✗\033[0m ") . $label);
            $ok = $ok && $passed;
        }

        $this->line('');
        $ok ? $this->success('النظام جاهز.') : $this->error('يوجد فحوص فاشلة.');

        return $ok ? 0 : 1;
    }

    private function help(): int
    {
        $this->title('منصة رواد النيل — أدوات سطر الأوامر');
        $this->line('  php bin/console <command>');
        $this->line('');
        $this->line('  db:create           إنشاء قاعدة البيانات');
        $this->line('  migrate             تنفيذ الترحيلات المعلّقة');
        $this->line('  migrate:status      عرض حالة الترحيلات');
        $this->line('  migrate:rollback    التراجع عن آخر دفعة');
        $this->line('  migrate:fresh       إعادة بناء كاملة (--force)');
        $this->line('  seed [--class=X]    تشغيل بذور البيانات');
        $this->line('  key:generate        توليد مفتاح التطبيق');
        $this->line('  health              فحص جاهزية النظام');
        $this->line('');

        return 0;
    }

    // ---------------- إخراج | Output ----------------

    private function parseOptions(array $args): array
    {
        $options = [];

        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }

            $arg   = substr($arg, 2);
            $parts = explode('=', $arg, 2);

            $options[$parts[0]] = $parts[1] ?? true;
        }

        return $options;
    }

    private function title(string $text): void
    {
        echo "\n\033[1;36m" . $text . "\033[0m\n";
        echo str_repeat('─', 60) . "\n";
    }

    private function line(string $text): void
    {
        echo $text . "\n";
    }

    private function success(string $text): void
    {
        echo "\033[32m✓ " . $text . "\033[0m\n\n";
    }

    private function error(string $text): void
    {
        fwrite(STDERR, "\033[31m✗ " . $text . "\033[0m\n\n");
    }
}
