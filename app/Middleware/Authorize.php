<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Support\TenantContext;
use Closure;

/**
 * التحقق من الصلاحية المطلوبة للمسار | Enforce the route's permission (§6).
 *
 * الصلاحية معرّفة في جدول المسارات، فيتم فرضها هنا مركزياً بدل تكرار الفحص
 * داخل كل متحكّم. هذا هو المستوى الأول من الدفاع؛ المستوى الثاني هو السياسات
 * (Policies) التي تفحص ملكية السجل بعينه.
 * The permission is declared in the route table and enforced here centrally.
 * This is defence layer one; policies checking per-record ownership are layer
 * two.
 */
final class Authorize implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $permission = Session::get('_route_permission');

        if (!is_string($permission) || $permission === '') {
            return $next($request);
        }

        if (!TenantContext::can($permission)) {
            throw new AuthorizationException(
                'ليس لديك صلاحية للقيام بهذا الإجراء.',
                $permission,
                $request->path(),
            );
        }

        return $next($request);
    }
}
