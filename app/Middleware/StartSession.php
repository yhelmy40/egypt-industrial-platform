<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Closure;

/**
 * بدء الجلسة وتحضير بيانات النماذج | Start session, prepare form state.
 *
 * ينقل المدخلات السابقة ورسائل الخطأ إلى مفاتيح "الطلب الحالي" حتى تتوفر
 * لدوال old() و error_for() في القوالب ثم تُمسح تلقائياً.
 * Moves flashed input/errors into "current request" keys so old() and
 * error_for() can read them in templates, after which they are cleared.
 */
final class StartSession implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        Session::start();

        Session::put('_old_input_current', Session::pullOldInput());
        Session::put('_errors_current', Session::pullErrors());

        return $next($request);
    }
}
