<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Config;
use App\Core\Database;
use RuntimeException;

/**
 * مُشغّل البذور | Seeder runner.
 *
 * يكتشف ملفات البذور، يرتّبها، ويتخطّى بذور العرض التوضيحي في الإنتاج.
 * Discovers seeders, orders them, and skips demo seeders in production.
 */
final class SeederRunner
{
    /** @var callable(string):void|null */
    private $output;

    public function __construct(
        private readonly string $seedersPath,
        ?callable $output = null,
    ) {
        $this->output = $output;
    }

    public function run(?string $only = null): int
    {
        $seeders    = $this->discover();
        $isProduction = Config::get('app.env') === 'production';
        $count      = 0;

        foreach ($seeders as $seeder) {
            $name = (new \ReflectionClass($seeder))->getShortName();

            if ($only !== null && $name !== $only) {
                continue;
            }

            if ($seeder->isDemo() && $isProduction) {
                $this->say("⊘ {$name} — تم تخطّيها (بيانات تجريبية في بيئة إنتاج)");
                continue;
            }

            $seeder->setOutput($this->output);

            // كل بذرة داخل معاملة: إما تكتمل أو لا تترك أثراً جزئياً.
            Database::transaction(function () use ($seeder): void {
                $seeder->run();
            });

            $this->say("✓ {$name}");
            $count++;
        }

        if ($only !== null && $count === 0) {
            throw new RuntimeException("لم يُعثر على ملف البذور: {$only}");
        }

        return $count;
    }

    /** @return array<int,Seeder> */
    private function discover(): array
    {
        $seeders = [];

        foreach (glob($this->seedersPath . '/*Seeder.php') ?: [] as $file) {
            $class = __NAMESPACE__ . '\\' . basename($file, '.php');

            if (!class_exists($class)) {
                require_once $file;
            }

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(Seeder::class)) {
                continue;
            }

            /** @var Seeder $instance */
            $instance  = $reflection->newInstance();
            $seeders[] = $instance;
        }

        usort($seeders, static fn (Seeder $a, Seeder $b) => $a->order() <=> $b->order());

        return $seeders;
    }

    private function say(string $message): void
    {
        if ($this->output !== null) {
            ($this->output)($message);
        }
    }
}
