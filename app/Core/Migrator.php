<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * مُشغّل الترحيلات | Migration runner.
 *
 * ترحيلات مرقّمة تُنفَّذ بالترتيب وتُسجَّل في جدول migrations مع رقم الدفعة،
 * حتى يمكن التراجع عن آخر دفعة كاملة.
 * Numbered migrations run in order and are recorded with a batch number so the
 * last batch can be rolled back as a unit.
 */
final class Migrator
{
    public function __construct(
        private readonly string $migrationsPath,
    ) {
    }

    private function table(): string
    {
        return (string) Config::get('database.migrations_table', 'migrations');
    }

    public function ensureRepository(): void
    {
        $table = $this->table();

        Database::connection()->exec(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration` VARCHAR(191) NOT NULL,
                `batch` INT UNSIGNED NOT NULL,
                `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_migrations_migration` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return array<int,string> */
    public function pending(): array
    {
        $this->ensureRepository();

        $ran   = $this->ran();
        $files = $this->files();

        return array_values(array_filter($files, static fn (string $f) => !in_array($f, $ran, true)));
    }

    /** @return array<int,string> */
    public function ran(): array
    {
        $table = $this->table();
        $rows  = Database::select("SELECT migration FROM `{$table}` ORDER BY id ASC");

        return array_column($rows, 'migration');
    }

    /** @return array<int,string> أسماء الملفات بدون امتداد، مرتّبة */
    private function files(): array
    {
        $files = glob($this->migrationsPath . '/*.php') ?: [];
        $names = array_map(static fn (string $p) => basename($p, '.php'), $files);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * تنفيذ الترحيلات المعلّقة | Run pending migrations.
     *
     * @param  callable(string):void|null $output
     * @return array<int,string> الترحيلات المنفّذة
     */
    public function migrate(?callable $output = null): array
    {
        $this->ensureRepository();

        $pending = $this->pending();
        if ($pending === []) {
            $output && $output('لا توجد ترحيلات معلّقة. | Nothing to migrate.');

            return [];
        }

        $batch = $this->nextBatch();
        $done  = [];

        foreach ($pending as $name) {
            $migration = $this->resolve($name);

            // كل ترحيل داخل معاملة مستقلة — لكن تعديلات DDL في MySQL
            // تُنفَّذ ضمنياً بشكل نهائي، لذا نوقف التنفيذ فوراً عند أول فشل.
            // Each migration runs on its own; DDL is implicitly committed in
            // MySQL, so we stop at the first failure rather than continuing.
            try {
                $migration->up();
            } catch (\Throwable $e) {
                $output && $output("✗ فشل الترحيل {$name}: " . $e->getMessage());

                throw $e;
            }

            $this->record($name, $batch);
            $done[] = $name;
            $output && $output("✓ {$name}");
        }

        return $done;
    }

    /**
     * التراجع عن آخر دفعة | Roll back the most recent batch.
     *
     * @param  callable(string):void|null $output
     * @return array<int,string>
     */
    public function rollback(?callable $output = null): array
    {
        $this->ensureRepository();

        $table = $this->table();
        $batch = (int) (Database::scalar("SELECT MAX(batch) FROM `{$table}`") ?? 0);

        if ($batch === 0) {
            $output && $output('لا توجد ترحيلات للتراجع عنها. | Nothing to roll back.');

            return [];
        }

        $rows = Database::select(
            "SELECT migration FROM `{$table}` WHERE batch = ? ORDER BY id DESC",
            [$batch],
        );

        $done = [];

        foreach (array_column($rows, 'migration') as $name) {
            $this->resolve($name)->down();
            Database::statement("DELETE FROM `{$table}` WHERE migration = ?", [$name]);
            $done[] = $name;
            $output && $output("↩ {$name}");
        }

        return $done;
    }

    /**
     * إعادة بناء كاملة | Drop everything and migrate from scratch.
     * ممنوعة في الإنتاج | Refused in production.
     */
    public function fresh(?callable $output = null): array
    {
        if (Config::get('app.env') === 'production') {
            throw new RuntimeException('إعادة بناء قاعدة البيانات ممنوعة في بيئة الإنتاج.');
        }

        $pdo = Database::connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $pdo->query('SHOW TABLES')?->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $output && $output('تم حذف ' . count($tables) . ' جدولاً. | Dropped ' . count($tables) . ' tables.');

        return $this->migrate($output);
    }

    private function nextBatch(): int
    {
        $table = $this->table();

        return (int) (Database::scalar("SELECT MAX(batch) FROM `{$table}`") ?? 0) + 1;
    }

    private function record(string $name, int $batch): void
    {
        $table = $this->table();
        Database::statement(
            "INSERT INTO `{$table}` (migration, batch) VALUES (?, ?)",
            [$name, $batch],
        );
    }

    private function resolve(string $name): Migration
    {
        $path = $this->migrationsPath . '/' . $name . '.php';

        if (!is_file($path)) {
            throw new RuntimeException("ملف الترحيل غير موجود: {$name}");
        }

        /** @var Migration|mixed $migration */
        $migration = require $path;

        if (!$migration instanceof Migration) {
            throw new RuntimeException("ملف الترحيل {$name} يجب أن يُعيد كائن Migration.");
        }

        return $migration;
    }
}
