<?php

declare(strict_types=1);

namespace App\Core;

/**
 * غلاف طلب HTTP | HTTP request wrapper.
 *
 * يمنع الوصول المباشر إلى المتغيرات العامة داخل منطق الأعمال.
 * Prevents business logic from touching superglobals directly.
 */
final class Request
{
    /** @var array<string,string> معاملات المسار | route parameters */
    private array $routeParams = [];

    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $files,
        private readonly array $cookies,
        private readonly array $server,
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // دعم _method للنماذج التي لا تدعم PUT/DELETE
        // Support _method override for HTML forms.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return new self(
            $method,
            self::resolvePath(),
            $_GET,
            $_POST,
            $_FILES,
            $_COOKIE,
            $_SERVER,
        );
    }

    /** إنشاء طلب اصطناعي للاختبارات | Build a synthetic request for tests. */
    public static function create(
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        array $files = [],
        array $server = [],
    ): self {
        return new self(
            strtoupper($method),
            '/' . trim($path, '/'),
            $query,
            $body,
            $files,
            [],
            $server + ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'PHPUnit'],
        );
    }

    private static function resolvePath(): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        // إزالة المجلد الفرعي عند التثبيت تحت مسار غير جذري
        // Strip the sub-directory when installed outside the document root.
        $base = Config::get('app.base_path', '');
        if (is_string($base) && $base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . trim(rawurldecode($path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** هل الطلب يغيّر الحالة؟ | Does this request change state? */
    public function isStateChanging(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public function isAjax(): bool
    {
        return strtolower((string) ($this->server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    public function expectsJson(): bool
    {
        return $this->isAjax()
            || str_starts_with($this->path, '/api/')
            || str_contains((string) ($this->server['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /**
     * قراءة مدخل من الجسم ثم الاستعلام | Read an input from body, then query.
     * القيم النصية تُشذّب دائماً | String values are always trimmed.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key);

        return $value !== null && $value !== '' && $value !== [];
    }

    /** @return array<string,mixed> */
    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if ($this->has($key)) {
                $out[$key] = $this->input($key);
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_map(
            static fn ($v) => is_string($v) ? trim($v) : $v,
            $this->query + $this->body,
        );
    }

    /** @return array<int,string> قائمة قيم متعددة | multi-value input */
    public function array(string $key): array
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    public function integer(string $key, ?int $default = null): ?int
    {
        $value = $this->input($key);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    public function boolean(string $key): bool
    {
        return in_array($this->input($key), ['1', 1, true, 'true', 'on', 'yes'], true);
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    public function files(): array
    {
        return $this->files;
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        $value = $this->cookies[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        $value      = $this->server[$normalized] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function ip(): string
    {
        // ملاحظة: لا نثق في X-Forwarded-For إلا خلف وكيل موثوق مُعدّ صراحةً.
        // Note: X-Forwarded-For is only trusted behind an explicitly configured proxy.
        if (Config::get('security.trust_proxy', false) === true) {
            $forwarded = $this->server['HTTP_X_FORWARDED_FOR'] ?? '';
            if (is_string($forwarded) && $forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        $ip = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';

        return is_string($ip) ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on'
            || (int) ($this->server['SERVER_PORT'] ?? 80) === 443;
    }

    public function fullUrl(): string
    {
        return rtrim((string) Config::get('app.url', ''), '/') . $this->path;
    }

    // ---------------- معاملات المسار | Route parameters ----------------

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function routeInt(string $key): ?int
    {
        $value = $this->routeParams[$key] ?? null;

        return $value !== null && ctype_digit((string) $value) ? (int) $value : null;
    }

    public function routeParams(): array
    {
        return $this->routeParams;
    }
}
