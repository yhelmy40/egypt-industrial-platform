<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Support\Csrf;
use Closure;

/**
 * التحقق من رمز CSRF | CSRF verification.
 *
 * يُطبَّق على كل طلب يغيّر الحالة. لا توجد استثناءات في هذه النسخة؛ أي نقطة
 * نهاية مستقبلية بدون جلسة (Webhook) ستحتاج توقيعاً خاصاً بها بدلاً من إعفاء.
 * Applied to every state-changing request. No exemptions exist here; a future
 * session-less endpoint (e.g. a webhook) must carry its own signature rather
 * than be excluded.
 */
final class VerifyCsrf implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isStateChanging()) {
            return $next($request);
        }

        $token = $request->input('_token') ?? $request->header('X-CSRF-Token');

        if (!Csrf::verify(is_string($token) ? $token : null)) {
            Logger::warning('CSRF verification failed', [
                'path'   => $request->path(),
                'method' => $request->method(),
                'ip'     => $request->ip(),
            ]);

            throw new HttpException(
                419,
                'انتهت صلاحية رمز الحماية أو أن الطلب غير صالح. يرجى تحديث الصفحة وإعادة المحاولة.',
            );
        }

        return $next($request);
    }
}
