<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Closure;

/**
 * منع الوصول لصفحات الضيوف بعد الدخول | Guest-only routes.
 * يمنع فتح صفحات التسجيل والدخول لمستخدم مسجّل بالفعل.
 */
final class RedirectIfAuthenticated implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Session::has('user_id')) {
            return Response::redirect(url('/app'));
        }

        return $next($request);
    }
}
