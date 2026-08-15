<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Middleware\Pipeline;
use App\Services\AuditLogger;
use Throwable;

/**
 * نواة التطبيق | Application kernel.
 *
 * مسؤولة عن: تحميل البيئة والإعدادات، تسجيل الخدمات، تشغيل خط الوسائط،
 * وتحويل الاستثناءات إلى استجابات آمنة لا تكشف تفاصيل داخلية.
 * Responsible for bootstrapping, service registration, running the middleware
 * pipeline, and turning exceptions into safe responses that never leak
 * internal detail to the user.
 */
final class Application
{
    private static ?Application $instance = null;

    private Container $container;

    private ?Router $router = null;

    private bool $booted = false;

    public function __construct(
        private readonly string $basePath,
    ) {
        $this->container = Container::getInstance();
    }

    public static function boot(string $basePath, ?string $envFile = null): self
    {
        $app = new self($basePath);
        $app->bootstrap($envFile);
        self::$instance = $app;

        return $app;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public function bootstrap(?string $envFile = null): void
    {
        if ($this->booted) {
            return;
        }

        // 1) البيئة | Environment
        if (!Env::isLoaded()) {
            Env::load($envFile ?? $this->basePath . '/.env');
        }

        // 2) الإعدادات | Configuration
        Config::load($this->basePath . '/config');

        // 3) المنطقة الزمنية والترميز | Timezone and encoding
        date_default_timezone_set((string) Config::get('app.timezone', 'Africa/Cairo'));
        mb_internal_encoding('UTF-8');
        setlocale(LC_ALL, 'en_US.UTF-8');

        // 4) الترجمة | Translations
        Translator::configure(
            (string) Config::get('app.lang_path'),
            (string) Config::get('app.locale', 'ar'),
            (string) Config::get('app.fallback_locale', 'ar'),
        );

        // 5) معالجة الأخطاء | Error handling
        $this->registerErrorHandling();

        // 6) الخدمات | Service bindings
        $this->registerServices();

        $this->booted = true;
    }

    private function registerErrorHandling(): void
    {
        $debug = (bool) Config::get('app.debug', false);

        error_reporting(E_ALL);
        // لا تُعرض الأخطاء للمستخدم مطلقاً؛ تُسجَّل فقط.
        // Errors are never displayed to the user, only logged.
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        register_shutdown_function(static function () use ($debug): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::critical('Fatal error', [
                    'message' => $error['message'],
                    'file'    => $error['file'],
                    'line'    => $error['line'],
                ]);

                if (!headers_sent()) {
                    http_response_code(500);
                }

                if (!$debug && PHP_SAPI !== 'cli') {
                    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8">'
                        . '<title>خطأ في النظام</title>'
                        . '<p style="font-family:sans-serif;text-align:center;margin-top:3rem">'
                        . 'حدث خطأ غير متوقع. تم تسجيل المشكلة وسيتم مراجعتها.</p>';
                }
            }
        });
    }

    /**
     * تسجيل الخدمات | Register service bindings.
     * عامّة لأن الاختبارات تُعيد بناء الحاوية بين الاختبارات لعزلها.
     * Public because tests rebuild the container between cases for isolation.
     */
    public function registerServices(): void
    {
        // إعادة التقاط الحاوية الحالية: قد تكون أُعيد إنشاؤها (بين الاختبارات)،
        // فالتسجيل في مرجع قديم يترك الحاوية المستخدمة فعلياً بلا ارتباطات.
        // Re-acquire the current container: it may have been rebuilt (between
        // tests), and registering into a stale reference would leave the
        // container actually in use without bindings.
        $this->container = Container::getInstance();

        $this->container->singleton(View::class, fn () => new View((string) Config::get('app.view_path')));
        $this->container->singleton(AuditLogger::class, fn () => new AuditLogger());

        // مُحوِّلات قابلة للتبديل عبر الإعدادات (§12)
        // Config-selected adapters — no provider credentials in code.
        $this->container->singleton(
            \App\Contracts\MailerInterface::class,
            static fn () => match ((string) Env::get('MAIL_DRIVER', 'log')) {
                default => new \App\Adapters\Mail\LogMailer(),
            },
        );

        $this->container->singleton(
            \App\Contracts\SmsSenderInterface::class,
            static fn () => match ((string) Env::get('SMS_DRIVER', 'log')) {
                default => new \App\Adapters\Sms\LogSmsSender(),
            },
        );
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function setRouter(Router $router): void
    {
        $this->router = $router;
    }

    public function router(): Router
    {
        if ($this->router === null) {
            /** @var Router $router */
            $router       = require $this->basePath . '/routes/web.php';
            $this->router = $router;
        }

        return $this->router;
    }

    /**
     * معالجة طلب HTTP كاملاً | Handle a full HTTP request.
     */
    public function handle(Request $request): Response
    {
        try {
            $route = $this->router()->match($request);

            $middleware = array_merge(
                $this->globalMiddleware(),
                $route->getMiddleware(),
            );

            // الصلاحية المطلوبة تُمرَّر للوسيط عبر الطلب
            if ($route->getPermission() !== null) {
                Session::put('_route_permission', $route->getPermission());
            } else {
                Session::forget('_route_permission');
            }

            $pipeline = new Pipeline($this->container);

            return $pipeline
                ->through($middleware)
                ->run($request, function (Request $request) use ($route): Response {
                    return $this->callAction($route, $request);
                });
        } catch (Throwable $e) {
            return $this->renderException($e, $request);
        }
    }

    /** @return array<int,string> */
    private function globalMiddleware(): array
    {
        return [
            \App\Middleware\SecurityHeaders::class,
            \App\Middleware\StartSession::class,
            \App\Middleware\VerifyCsrf::class,
        ];
    }

    private function callAction(Route $route, Request $request): Response
    {
        [$class, $method] = $route->action;

        if (!class_exists($class)) {
            throw new HttpException(500, 'تعذّر تنفيذ الطلب.');
        }

        $controller = $this->container->make($class);

        if (!method_exists($controller, $method)) {
            throw new HttpException(500, 'تعذّر تنفيذ الطلب.');
        }

        $result = $controller->{$method}($request);

        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::noContent();
    }

    /**
     * تحويل الاستثناء إلى استجابة | Convert an exception to a response.
     *
     * الرسائل التفصيلية تظهر فقط في وضع التطوير؛ في الإنتاج تُعرض رسالة عربية
     * عامة وتُسجَّل التفاصيل في السجل.
     */
    public function renderException(Throwable $e, Request $request): Response
    {
        $isHttp = $e instanceof HttpException;
        $status = $isHttp ? $e->getStatusCode() : 500;

        if ($e instanceof AuthorizationException) {
            // محاولات الوصول غير المصرّح بها مؤشر أمني يُسجَّل دائماً
            try {
                $this->container->make(AuditLogger::class)->logAuthorizationDenied(
                    $request,
                    $e->permission(),
                    $e->resource(),
                );
            } catch (Throwable) {
                // لا نُفشل الاستجابة بسبب فشل التسجيل
            }
        }

        if (!$isHttp || $status >= 500) {
            Logger::error('Unhandled exception', [
                'type'    => $e::class,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'path'    => $request->path(),
                'trace'   => (bool) Config::get('app.debug', false)
                    ? array_slice(explode("\n", $e->getTraceAsString()), 0, 15)
                    : null,
            ]);
        }

        $message = $isHttp && $e->getMessage() !== ''
            ? $e->getMessage()
            : $this->defaultMessageFor($status);

        // صفحات الخطأ تُبنى خارج خط الوسائط (قد يقع الاستثناء قبل مطابقة
        // المسار)، لذا تُطبَّق ترويسات الأمان هنا صراحةً.
        // Error responses are built outside the pipeline, so headers are
        // applied explicitly here.
        $response = $request->expectsJson()
            ? Response::json([
                'error'   => true,
                'status'  => $status,
                'message' => $message,
            ], $status)
            : $this->renderErrorPage($status, $message, $e);

        return \App\Middleware\SecurityHeaders::apply($request, $response);
    }

    private function defaultMessageFor(int $status): string
    {
        return match ($status) {
            400     => 'الطلب غير صالح.',
            401     => 'يجب تسجيل الدخول للوصول إلى هذه الصفحة.',
            403     => 'ليس لديك صلاحية للوصول إلى هذه الصفحة.',
            404     => 'الصفحة المطلوبة غير موجودة.',
            405     => 'أسلوب الطلب غير مسموح به.',
            422     => 'يرجى مراجعة البيانات المُدخلة.',
            429     => 'عدد كبير من المحاولات. يرجى الانتظار قليلاً ثم إعادة المحاولة.',
            default => 'حدث خطأ غير متوقع. تم تسجيل المشكلة وسيتم مراجعتها.',
        };
    }

    private function renderErrorPage(int $status, string $message, Throwable $e): Response
    {
        try {
            /** @var View $view */
            $view = $this->container->make(View::class);
            $html = $view->render('errors/error', [
                'pageTitle' => 'خطأ ' . $status,
                'status'    => $status,
                'message'   => $message,
                'debug'     => (bool) Config::get('app.debug', false),
                'exception' => $e,
            ], 'public');

            return Response::html($html, $status);
        } catch (Throwable) {
            // احتياطي إذا فشل العرض نفسه | Fallback if rendering itself fails
            return Response::html(
                '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
                . '<title>خطأ ' . $status . '</title></head><body>'
                . '<div style="font-family:system-ui,sans-serif;text-align:center;margin-top:4rem">'
                . '<h1>' . $status . '</h1><p>' . e($message) . '</p>'
                . '<p><a href="' . url('/') . '">العودة إلى الصفحة الرئيسية</a></p>'
                . '</div></body></html>',
                $status,
            );
        }
    }
}
