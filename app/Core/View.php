<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * محرّك العرض | Plain-PHP template renderer.
 *
 * لا يوجد محرّك قوالب خارجي؛ القوالب PHP عادية مع دالة الهروب e().
 * القوالب لا تحتوي منطق أعمال ولا استعلامات — فقط عرض.
 * No third-party template engine. Templates are plain PHP using e() for
 * escaping, and contain presentation only — no queries, no business logic.
 */
final class View
{
    /** @var array<string,mixed> بيانات مشتركة لكل القوالب */
    private static array $shared = [];

    /** @var array<string,string> أقسام مُخزَّنة (styles/scripts) */
    private array $sections = [];

    private ?string $currentSection = null;

    public function __construct(
        private readonly string $viewPath,
    ) {
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function shared(): array
    {
        return self::$shared;
    }

    public static function clearShared(): void
    {
        self::$shared = [];
    }

    /**
     * عرض قالب داخل تخطيط | Render a template inside a layout.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = $this->renderTemplate($template, $data);

        if ($layout === null) {
            return $content;
        }

        return $this->renderTemplate(
            'layouts/' . $layout,
            array_merge($data, [
                'content'  => $content,
                'sections' => $this->sections,
            ]),
        );
    }

    /** @param array<string,mixed> $data */
    public function renderTemplate(string $template, array $data = []): string
    {
        $file = $this->viewPath . '/' . str_replace('.', '/', $template) . '.php';

        // منع اجتياز المسارات في أسماء القوالب
        // Guard against path traversal in template names.
        $real = realpath($file);
        if ($real === false || !str_starts_with($real, realpath($this->viewPath) ?: $this->viewPath)) {
            throw new RuntimeException("القالب غير موجود: {$template}");
        }

        $data = array_merge(self::$shared, $data);

        // $view متاح داخل القوالب لاستدعاء partial()/section()
        $view = $this;

        extract($data, EXTR_SKIP);

        ob_start();

        try {
            require $real;
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /** تضمين جزء من القالب | Include a partial. */
    public function partial(string $template, array $data = []): string
    {
        return $this->renderTemplate($template, $data);
    }

    // ---------------- الأقسام | Sections ----------------

    public function startSection(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    public function endSection(): void
    {
        if ($this->currentSection === null) {
            return;
        }

        $this->sections[$this->currentSection] = ($this->sections[$this->currentSection] ?? '')
            . (string) ob_get_clean();

        $this->currentSection = null;
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }
}
