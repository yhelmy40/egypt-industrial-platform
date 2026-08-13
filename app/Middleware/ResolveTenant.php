<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Services\PermissionResolver;
use App\Support\TenantContext;
use Closure;

/**
 * تحديد المنشأة النشطة | Resolve the active tenant (§7).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * هذا هو الموضع الوحيد الذي يُحدَّد فيه organization_id للطلب.
 * This is the ONLY place a request's organization_id is decided.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * الخطوات | Steps:
 *  1. قراءة معرّف المنشأة من الجلسة (لا من الطلب).
 *  2. إعادة التحقق من وجود عضوية نشطة في قاعدة البيانات — في كل طلب، لأن
 *     العضوية قد تُلغى أو تُوقَف بعد بدء الجلسة.
 *  3. حساب الصلاحيات الفعّالة ووضعها في TenantContext.
 *
 * إذا لم تُثبَت العضوية، يُمسح السياق ويُعامَل المستخدم كمن لا منشأة له —
 * ولا يُفترض أي وصول.
 */
final class ResolveTenant implements MiddlewareInterface
{
    public function __construct(
        private readonly MembershipRepository $memberships = new MembershipRepository(),
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly PermissionResolver $permissions = new PermissionResolver(),
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::reset();

        $userId = Session::get('user_id');

        if (!is_int($userId)) {
            // زائر: لا مستخدم ولا منشأة ولا صلاحيات.
            TenantContext::set(null, null, null, null, [], []);

            return $next($request);
        }

        $platformRoles       = $this->permissions->platformRoles($userId);
        $platformPermissions = $this->permissions->platformPermissions($userId);

        $organizationId = Session::get('active_organization_id');
        $organizationId = is_int($organizationId) ? $organizationId : null;

        // لم تُختَر منشأة بعد → جرّب المنشأة الافتراضية للمستخدم
        if ($organizationId === null) {
            $organizationId = $this->memberships->defaultOrganizationId($userId);

            if ($organizationId !== null) {
                Session::put('active_organization_id', $organizationId);
            }
        }

        if ($organizationId === null) {
            TenantContext::set($userId, null, null, null, $platformPermissions, $platformRoles);

            return $next($request);
        }

        // ── إعادة التحقق الإلزامية من العضوية في كل طلب ──
        $membership = $this->memberships->findActiveMembership($userId, $organizationId);

        if ($membership === null) {
            // العضوية أُلغيت أو أُوقفت بعد بدء الجلسة → إسقاط السياق فوراً.
            Session::forget('active_organization_id');

            TenantContext::set($userId, null, null, null, $platformPermissions, $platformRoles);

            return $next($request);
        }

        $organization = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            Session::forget('active_organization_id');
            TenantContext::set($userId, null, null, null, $platformPermissions, $platformRoles);

            return $next($request);
        }

        $membershipPermissions = $this->permissions->membershipPermissions(
            (int) $membership['id'],
            (int) $membership['role_id'],
        );

        TenantContext::set(
            $userId,
            $organizationId,
            $organization,
            $membership,
            $this->permissions->merge($platformPermissions, $membershipPermissions),
            $platformRoles,
        );

        return $next($request);
    }
}
