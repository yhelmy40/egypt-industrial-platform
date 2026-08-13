<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

/**
 * حاوية الخدمات | Minimal service container.
 *
 * تُستخدم لربط الواجهات (Contracts) بالمنفّذات (Adapters) عبر الإعدادات،
 * مما يسمح بتبديل مزوّدي الدفع والرسائل لاحقاً دون تعديل منطق الأعمال.
 * Binds contracts to adapters through configuration so payment/notification
 * providers can be swapped later without touching business logic.
 */
final class Container
{
    private static ?Container $instance = null;

    /** @var array<string,Closure> */
    private array $bindings = [];

    /** @var array<string,mixed> */
    private array $instances = [];

    public static function getInstance(): Container
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function bind(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
        unset($this->instances[$abstract]);
    }

    /** ربط خدمة وحيدة تُنشأ مرة واحدة | Bind a shared (singleton) service. */
    public function singleton(string $abstract, Closure $factory): void
    {
        $this->bind($abstract, function () use ($abstract, $factory) {
            return $this->instances[$abstract] ??= $factory($this);
        });
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->bindings[$abstract]  = fn () => $instance;
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    public function make(string $abstract): mixed
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            return ($this->bindings[$abstract])($this);
        }

        // محاولة إنشاء تلقائي للأصناف بدون اعتماديات
        // Auto-resolve concrete classes that need no constructor arguments.
        if (class_exists($abstract)) {
            $reflection  = new \ReflectionClass($abstract);
            $constructor = $reflection->getConstructor();
            if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                return new $abstract();
            }
        }

        throw new RuntimeException("لا يمكن حلّ الخدمة: {$abstract}");
    }
}
