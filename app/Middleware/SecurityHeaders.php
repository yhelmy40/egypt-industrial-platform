<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * ترويسات الأمان | Security headers (§9).
 *
 * تُضاف بعد تنفيذ المتحكّم حتى تشمل كل الاستجابات.
 * Applied after the controller runs so every response is covered.
 *
 * ملاحظة مهمة: صفحات الخطأ (404/403/500) تُبنى خارج خط الوسائط، لأن الاستثناء
 * قد يقع قبل مطابقة المسار أصلاً. لذلك استُخرج تطبيق الترويسات إلى دالة ساكنة
 * يستدعيها كلٌّ من هذا الوسيط ومُعالج الاستثناءات — فلا تخرج استجابة واحدة
 * بلا ترويسات أمان.
 * Important: error pages are built outside the pipeline, since an exception can
 * occur before the route is even matched. Header application is therefore
 * extracted into a static method called by both this middleware and the
 * exception handler, so no response can ever ship without them.
 */
final class SecurityHeaders implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($request, $next($request));
    }

    public static function apply(Request $request, Response $response): Response
    {
        $headers = Config::get('security.headers', []);

        $response
            ->withHeader('X-Frame-Options', (string) ($headers['x_frame_options'] ?? 'DENY'))
            ->withHeader('X-Content-Type-Options', (string) ($headers['x_content_type_options'] ?? 'nosniff'))
            ->withHeader('Referrer-Policy', (string) ($headers['referrer_policy'] ?? 'strict-origin-when-cross-origin'))
            ->withHeader('Permissions-Policy', (string) ($headers['permissions_policy'] ?? ''))
            ->withHeader('X-Permitted-Cross-Domain-Policies', 'none');

        // HSTS يُفعَّل فقط مع HTTPS — إرساله على HTTP يقفل الموقع دون فائدة
        // HSTS only over HTTPS; sending it on plain HTTP just locks users out.
        if (($headers['hsts_enabled'] ?? false) === true && $request->isSecure()) {
            $response->withHeader(
                'Strict-Transport-Security',
                'max-age=' . (int) ($headers['hsts_max_age'] ?? 31536000) . '; includeSubDomains',
            );
        }

        if (Config::get('security.csp.enabled', true) === true) {
            $response->withHeader(self::cspHeaderName(), self::buildCsp());
        }

        // منع تخزين الصفحات المصادَق عليها | Do not cache authenticated pages
        if (!str_starts_with($request->path(), '/assets')) {
            $response->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        }

        return $response;
    }

    private static function cspHeaderName(): string
    {
        return Config::get('security.csp.report_only', false) === true
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    private static function buildCsp(): string
    {
        $parts = [];

        foreach (Config::get('security.csp.directives', []) as $directive => $value) {
            $parts[] = $directive . ' ' . $value;
        }

        return implode('; ', $parts);
    }
}
