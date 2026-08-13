<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * خط معالجة الوسائط | Middleware pipeline.
 *
 * ينفّذ الوسائط بالترتيب ثم يستدعي المتحكّم في النهاية.
 * Runs middleware in order, with the controller as the innermost layer.
 */
final class Pipeline
{
    /** @var array<int,string> */
    private array $middleware = [];

    public function __construct(
        private readonly Container $container,
    ) {
    }

    /** @param array<int,string> $middleware */
    public function through(array $middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }

    /**
     * @param Closure(Request):Response $destination
     */
    public function run(Request $request, Closure $destination): Response
    {
        $next = array_reduce(
            array_reverse($this->middleware),
            function (Closure $carry, string $middlewareClass): Closure {
                return function (Request $request) use ($carry, $middlewareClass): Response {
                    /** @var MiddlewareInterface $middleware */
                    $middleware = $this->container->make($middlewareClass);

                    return $middleware->handle($request, $carry);
                };
            },
            $destination,
        );

        return $next($request);
    }
}
