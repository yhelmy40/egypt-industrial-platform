<?php

declare(strict_types=1);

namespace App\Core;

/**
 * استجابة HTTP | HTTP response value object.
 *
 * تُجمَّع الاستجابة بالكامل قبل الإرسال، مما يسمح للوسائط (Middleware)
 * بإضافة ترويسات الأمان بعد تنفيذ المتحكّم.
 * The response is assembled before it is sent so middleware can attach
 * security headers after the controller has run.
 */
final class Response
{
    private array $headers = [];

    private array $cookies = [];

    private function __construct(
        private string $body = '',
        private int $status = 200,
    ) {
    }

    public static function make(string $body = '', int $status = 200): self
    {
        return new self($body, $status);
    }

    public static function html(string $body, int $status = 200): self
    {
        return (new self($body, $status))
            ->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public static function json(array $data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (new self($encoded === false ? '{}' : $encoded, $status))
            ->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return (new self('', $status))->withHeader('Location', $to);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    /** تنزيل ملف من القرص | Stream a file download (authorization happens upstream). */
    public static function download(string $absolutePath, string $downloadName, string $mime): self
    {
        $contents = @file_get_contents($absolutePath);

        return (new self($contents === false ? '' : $contents, $contents === false ? 404 : 200))
            ->withHeader('Content-Type', $mime)
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . rawurlencode($downloadName) . '"'
            )
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function withCookie(
        string $name,
        string $value,
        int $expires = 0,
        bool $httpOnly = true,
        ?bool $secure = null,
        string $sameSite = 'Lax',
    ): self {
        $this->cookies[$name] = [
            'value'    => $value,
            'expires'  => $expires,
            'httponly' => $httpOnly,
            'secure'   => $secure ?? (bool) Config::get('session.secure', false),
            'samesite' => $sameSite,
            'path'     => Config::get('app.base_path', '') ?: '/',
        ];

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function isRedirect(): bool
    {
        return isset($this->headers['Location']);
    }

    /** إرسال الاستجابة إلى المتصفح | Emit the response. */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }

            foreach ($this->cookies as $name => $options) {
                setcookie($name, $options['value'], [
                    'expires'  => $options['expires'],
                    'path'     => $options['path'],
                    'secure'   => $options['secure'],
                    'httponly' => $options['httponly'],
                    'samesite' => $options['samesite'],
                ]);
            }
        }

        echo $this->body;
    }
}
