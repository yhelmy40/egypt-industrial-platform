<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

/**
 * موجّه الطلبات | Explicit-table router.
 *
 * لا يُشتقّ اسم المتحكّم أو الدالة من عنوان URL إطلاقاً؛ كل مسار مُعرّف
 * صراحةً مع وسائطه وصلاحيته. هذا يمنع استدعاء دوال غير مقصودة.
 * Controller and method names are NEVER derived from the URL; every route is
 * declared explicitly with its middleware and permission. This removes the
 * class of bug where any public method becomes a reachable endpoint.
 */
final class Router
{
    /** @var array<int,Route> */
    private array $routes = [];

    /** @var array<string,string> */
    private static array $names = [];

    /** @var array<int,string> وسائط تُطبّق على المجموعة الحالية */
    private array $groupMiddleware = [];

    private string $groupPrefix = '';

    public function get(string $path, array $action): Route
    {
        return $this->add('GET', $path, $action);
    }

    public function post(string $path, array $action): Route
    {
        return $this->add('POST', $path, $action);
    }

    public function put(string $path, array $action): Route
    {
        return $this->add('PUT', $path, $action);
    }

    public function patch(string $path, array $action): Route
    {
        return $this->add('PATCH', $path, $action);
    }

    public function delete(string $path, array $action): Route
    {
        return $this->add('DELETE', $path, $action);
    }

    private function add(string $method, string $path, array $action): Route
    {
        $full  = $this->groupPrefix . $path;
        $full  = $full === '' ? '/' : rtrim($full, '/');
        $full  = $full === '' ? '/' : $full;
        $route = new Route($method, $full, $action);

        if ($this->groupMiddleware !== []) {
            $route->middleware(...$this->groupMiddleware);
        }

        $this->routes[] = $route;

        return $route;
    }

    /**
     * مجموعة مسارات بوسائط مشتركة | Route group with shared prefix/middleware.
     *
     * @param callable(Router):void $callback
     */
    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $previousPrefix     = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix    .= $prefix;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix     = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /**
     * مطابقة الطلب بمسار | Resolve a request to a route.
     *
     * @throws HttpException 404 عند عدم وجود المسار، 405 عند اختلاف الطريقة
     */
    public function match(Request $request): Route
    {
        $pathExists = false;

        foreach ($this->routes as $route) {
            $params = $route->matches($request->method(), $request->path());
            if ($params !== null) {
                $request->setRouteParams($params);

                return $route;
            }

            if ($route->matchesPath($request->path())) {
                $pathExists = true;
            }
        }

        if ($pathExists) {
            throw new HttpException(405, 'أسلوب الطلب غير مسموح به لهذا المسار.');
        }

        throw new HttpException(404, 'الصفحة المطلوبة غير موجودة.');
    }

    /** @return array<int,Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    public static function registerName(string $name, string $path): void
    {
        self::$names[$name] = $path;
    }

    /** توليد رابط من اسم مسار | Build a URL from a route name. */
    public static function pathFor(string $name, array $params = []): ?string
    {
        $path = self::$names[$name] ?? null;
        if ($path === null) {
            return null;
        }

        foreach ($params as $key => $value) {
            $path = preg_replace(
                '#\{' . preg_quote((string) $key, '#') . '(:[^}]+)?\}#',
                rawurlencode((string) $value),
                $path,
            ) ?? $path;
        }

        return $path;
    }

    public static function resetNames(): void
    {
        self::$names = [];
    }
}
