<?php

declare(strict_types=1);

namespace App\Support;

/**
 * سياق المنشأة النشطة | Active tenant context (§7).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * القاعدة الحاكمة | The governing rule
 * ═══════════════════════════════════════════════════════════════════════════
 * لا يُقبل organization_id قادم من المتصفح إطلاقاً. تُملأ هذه الحاوية حصراً
 * من وسيط ResolveTenant بعد التحقق من وجود عضوية نشطة للمستخدم في المنشأة
 * داخل قاعدة البيانات — في كل طلب، وليس مرة واحدة عند تسجيل الدخول.
 *
 * An organization_id from the browser is never accepted. This container is
 * populated exclusively by the ResolveTenant middleware after verifying an
 * active membership row in the database — on every request, not once at login.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class TenantContext
{
    private static ?int $userId = null;

    private static ?int $organizationId = null;

    private static ?array $organization = null;

    private static ?array $membership = null;

    /** @var array<int,string> صلاحيات المستخدم الفعّالة في السياق الحالي */
    private static array $permissions = [];

    /** @var array<int,string> أدوار المنصة | Platform-scope role codes */
    private static array $platformRoles = [];

    private static bool $resolved = false;

    /**
     * تعيين السياق | Set the resolved context.
     * يُستدعى من ResolveTenant فقط.
     *
     * @param array<int,string> $permissions
     * @param array<int,string> $platformRoles
     */
    public static function set(
        ?int $userId,
        ?int $organizationId,
        ?array $organization,
        ?array $membership,
        array $permissions,
        array $platformRoles,
    ): void {
        self::$userId         = $userId;
        self::$organizationId = $organizationId;
        self::$organization   = $organization;
        self::$membership     = $membership;
        self::$permissions    = $permissions;
        self::$platformRoles  = $platformRoles;
        self::$resolved       = true;
    }

    public static function userId(): ?int
    {
        return self::$userId;
    }

    public static function organizationId(): ?int
    {
        return self::$organizationId;
    }

    public static function organization(): ?array
    {
        return self::$organization;
    }

    public static function membership(): ?array
    {
        return self::$membership;
    }

    public static function hasOrganization(): bool
    {
        return self::$organizationId !== null;
    }

    public static function isAuthenticated(): bool
    {
        return self::$userId !== null;
    }

    public static function isResolved(): bool
    {
        return self::$resolved;
    }

    /** @return array<int,string> */
    public static function permissions(): array
    {
        return self::$permissions;
    }

    public static function can(string $permission): bool
    {
        return in_array($permission, self::$permissions, true);
    }

    /** @param array<int,string> $permissions */
    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function hasPlatformRole(string $roleCode): bool
    {
        return in_array($roleCode, self::$platformRoles, true);
    }

    public static function isSuperAdmin(): bool
    {
        return self::hasPlatformRole('super_admin');
    }

    /** هل المستخدم من فريق المنصة؟ | Is this a platform staff member? */
    public static function isPlatformStaff(): bool
    {
        return self::hasPlatformRole('super_admin')
            || self::hasPlatformRole('ops_officer')
            || self::hasPlatformRole('content_editor');
    }

    /** @return array<int,string> */
    public static function platformRoles(): array
    {
        return self::$platformRoles;
    }

    /** حالة المنشأة النشطة | Active organization status. */
    public static function organizationStatus(): ?string
    {
        $status = self::$organization['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    public static function organizationIsVerified(): bool
    {
        return self::organizationStatus() === 'verified';
    }

    /** إعادة الضبط بين الطلبات وفي الاختبارات | Reset between requests/tests. */
    public static function reset(): void
    {
        self::$userId         = null;
        self::$organizationId = null;
        self::$organization   = null;
        self::$membership     = null;
        self::$permissions    = [];
        self::$platformRoles  = [];
        self::$resolved       = false;
    }
}
