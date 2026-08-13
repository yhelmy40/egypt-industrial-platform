<?php

declare(strict_types=1);

namespace App\Core;

/**
 * ترحيل واحد | A single migration.
 *
 * كل ترحيل يوفّر up() و down() حتى يمكن التراجع أثناء التطوير وفي إجراء
 * الاسترجاع الموثّق في دليل النشر.
 * Every migration provides up() and down() so a rollback is possible during
 * development and in the documented deployment rollback procedure.
 */
abstract class Migration
{
    abstract public function up(): void;

    abstract public function down(): void;

    protected function run(string $sql): void
    {
        Database::connection()->exec($sql);
    }

    /** إنشاء جدول بالإعدادات الموحّدة | Create a table with standard options. */
    protected function create(string $table, string $definition): void
    {
        $collation = Config::get('database.collation', 'utf8mb4_unicode_ci');
        $charset   = Config::get('database.charset', 'utf8mb4');

        $this->run(
            "CREATE TABLE IF NOT EXISTS `{$table}` (\n{$definition}\n) "
            . "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC"
        );
    }

    protected function drop(string $table): void
    {
        $this->run("DROP TABLE IF EXISTS `{$table}`");
    }

    /**
     * أعمدة الطوابع الزمنية الموحّدة | Standard timestamp columns.
     * تُستخدم في كل جدول التزاماً بمتطلّب §8.
     */
    protected function timestamps(): string
    {
        return "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
    }

    /** حذف ناعم | Soft-delete column for records that must be retained. */
    protected function softDelete(): string
    {
        return "`deleted_at` DATETIME NULL DEFAULT NULL";
    }
}
