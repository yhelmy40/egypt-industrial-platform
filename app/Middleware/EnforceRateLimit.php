<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;
use Closure;

/**
 * تحديد المعدّل على مستوى المسار | Route-level rate limiting (§9).
 *
 * يُستخدم للمسارات العامة الحسّاسة (التواصل، الاستفسارات، البحث، الطلبات).
 * تسجيل الدخول له حدّه الخاص داخل AuthService لأنه يحتاج توقيعاً يشمل البريد.
 * Used for sensitive public endpoints. Login has its own limiter inside
 * AuthService because its signature includes the submitted email.
 */
final class EnforceRateLimit implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter = new RateLimiter(),
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // الحاوية لا تمرّر وسائط، لذا يُشتقّ اسم القاعدة من المسار.
        $bucket    = $this->bucketFor($request);
        $signature = $request->ip();

        if ($this->limiter->tooManyAttempts($bucket, $signature)) {
            $wait = $this->limiter->availableIn($bucket, $signature);

            throw new HttpException(
                429,
                'عدد كبير من المحاولات. ' . $this->limiter->waitMessage($wait),
            );
        }

        if ($request->isStateChanging()) {
            $this->limiter->hit($bucket, $signature);
        }

        return $next($request);
    }

    private function bucketFor(Request $request): string
    {
        $path = $request->path();

        return match (true) {
            str_contains($path, '/register')       => 'register',
            str_contains($path, '/forgot-password'),
            str_contains($path, '/reset-password') => 'password_reset',
            str_contains($path, '/resend')         => 'verify_resend',
            str_contains($path, '/contact')        => 'contact',
            str_contains($path, '/enquiry')        => 'enquiry',
            str_contains($path, '/application')    => 'application',
            str_contains($path, '/search')         => 'search',
            default                                => 'contact',
        };
    }
}
