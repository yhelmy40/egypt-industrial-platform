<?php

declare(strict_types=1);

namespace App\Core;

/**
 * تعريف مسار واحد | A single route definition.
 *
 * الصلاحية المطلوبة جزء من تعريف المسار وليست شرطاً متناثراً في المتحكّمات،
 * حتى يمكن تدقيق مصفوفة الصلاحيات كاملة من ملف واحد.
 * The required permission is part of the route definition rather than an
 * `if` scattered inside controllers, so the whole permission surface is
 * auditable from one file.
 */
final class Route
{
    private ?string $permission = null;

    private array $middleware = [];

    private ?string $name = null;

    private string $regex;

    /** @var array<int,string> */
    private array $paramNames = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $action,
    ) {
        $this->compile();
    }

    private function compile(): void
    {
        $pattern = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(:([^}]+))?\}#',
            function (array $m): string {
                $this->paramNames[] = $m[1];
                $constraint         = $m[3] ?? '[^/]+';

                return '(' . $constraint . ')';
            },
            $this->path,
        );

        $this->regex = '#^' . $pattern . '$#u';
    }

    public function matches(string $method, string $path): ?array
    {
        if ($this->method !== $method) {
            return null;
        }

        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        array_shift($matches);

        return array_combine($this->paramNames, $matches) ?: [];
    }

    /** هل يطابق المسار بغضّ النظر عن الطريقة؟ | Path match ignoring the verb. */
    public function matchesPath(string $path): bool
    {
        return preg_match($this->regex, $path) === 1;
    }

    public function permission(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    public function middleware(string ...$middleware): self
    {
        foreach ($middleware as $item) {
            if (!in_array($item, $this->middleware, true)) {
                $this->middleware[] = $item;
            }
        }

        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        Router::registerName($name, $this->path);

        return $this;
    }

    public function getPermission(): ?string
    {
        return $this->permission;
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getName(): ?string
    {
        return $this->name;
    }
}
