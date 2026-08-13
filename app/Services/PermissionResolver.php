<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * حسّاب الصلاحيات الفعّالة | Effective permission resolver (§6).
 *
 * الصلاحيات الفعّالة =
 *      صلاحيات أدوار المنصة
 *    ∪ صلاحيات دور العضوية في المنشأة النشطة
 *    + تجاوزات العضو من نوع grant
 *    − تجاوزات العضو من نوع deny            ← المنع يغلب المنح دائماً
 *
 * قاعدة «المنع يغلب المنح» متعمَّدة: عندما يمنع صاحب المشروع صلاحية عن موظف،
 * يجب ألّا يعيدها له دورٌ آخر بشكل غير متوقّع.
 * Deny always wins: when an SME owner revokes a permission from an employee,
 * no other role should silently hand it back.
 */
final class PermissionResolver
{
    /**
     * صلاحيات أدوار المنصة | Platform-scope permissions for a user.
     *
     * @return array<int,string>
     */
    public function platformPermissions(int $userId): array
    {
        $rows = Database::select(
            "SELECT DISTINCT p.code
               FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id AND r.scope = 'platform' AND r.is_active = 1
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE ur.user_id = ?",
            [$userId],
        );

        return array_column($rows, 'code');
    }

    /**
     * أدوار المنصة | Platform role codes for a user.
     *
     * @return array<int,string>
     */
    public function platformRoles(int $userId): array
    {
        $rows = Database::select(
            "SELECT r.code
               FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id AND r.scope = 'platform' AND r.is_active = 1
              WHERE ur.user_id = ?",
            [$userId],
        );

        return array_column($rows, 'code');
    }

    /**
     * صلاحيات العضوية في منشأة | Permissions from an organization membership.
     *
     * @return array<int,string>
     */
    public function membershipPermissions(int $memberId, int $roleId): array
    {
        $rolePermissions = array_column(
            Database::select(
                'SELECT p.code
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE rp.role_id = ?',
                [$roleId],
            ),
            'code',
        );

        $overrides = Database::select(
            'SELECT p.code, mp.effect
               FROM organization_member_permissions mp
               JOIN permissions p ON p.id = mp.permission_id
              WHERE mp.member_id = ?',
            [$memberId],
        );

        $granted = [];
        $denied  = [];

        foreach ($overrides as $override) {
            if ($override['effect'] === 'grant') {
                $granted[] = (string) $override['code'];
            } else {
                $denied[] = (string) $override['code'];
            }
        }

        $effective = array_unique(array_merge($rolePermissions, $granted));

        // المنع يغلب المنح | Deny wins.
        return array_values(array_diff($effective, $denied));
    }

    /**
     * دمج الصلاحيات الفعّالة للطلب الحالي | Merge into the request's effective set.
     *
     * @param  array<int,string> $platform
     * @param  array<int,string> $membership
     * @return array<int,string>
     */
    public function merge(array $platform, array $membership): array
    {
        return array_values(array_unique(array_merge($platform, $membership)));
    }

    /**
     * كل الصلاحيات القابلة للإسناد من صاحب المشروع (§3.5).
     *
     * @return array<int,array<string,mixed>>
     */
    public function ownerAssignablePermissions(): array
    {
        return Database::select(
            'SELECT id, code, module, name_ar
               FROM permissions
              WHERE assignable_by_owner = 1
              ORDER BY module ASC, code ASC',
        );
    }

    /**
     * كتالوج الصلاحيات مجمّعاً حسب الوحدة | Permission catalogue grouped by module.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function catalogueByModule(): array
    {
        $rows = Database::select(
            'SELECT id, code, module, name_ar, is_sensitive, assignable_by_owner
               FROM permissions
              ORDER BY module ASC, code ASC',
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['module']][] = $row;
        }

        return $grouped;
    }

    /** @return array<int,array<string,mixed>> */
    public function roles(?string $scope = null): array
    {
        $sql      = 'SELECT * FROM roles WHERE is_active = 1';
        $bindings = [];

        if ($scope !== null) {
            $sql       .= ' AND scope = ?';
            $bindings[] = $scope;
        }

        return Database::select($sql . ' ORDER BY sort_order ASC', $bindings);
    }

    public function findRoleByCode(string $code): ?array
    {
        return Database::selectOne('SELECT * FROM roles WHERE code = ? LIMIT 1', [$code]);
    }

    /** @return array<int,string> صلاحيات دور بعينه */
    public function permissionsForRole(int $roleId): array
    {
        return array_column(
            Database::select(
                'SELECT p.code FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE rp.role_id = ?',
                [$roleId],
            ),
            'code',
        );
    }

    /**
     * إسناد دور منصة لمستخدم | Assign a platform role.
     */
    public function assignPlatformRole(int $userId, int $roleId, ?int $assignedBy = null): void
    {
        Database::statement(
            'INSERT IGNORE INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)',
            [$userId, $roleId, $assignedBy],
        );
    }

    public function removePlatformRole(int $userId, int $roleId): void
    {
        Database::statement(
            'DELETE FROM user_roles WHERE user_id = ? AND role_id = ?',
            [$userId, $roleId],
        );
    }

    /**
     * استبدال صلاحيات دور | Replace a role's permission set (admin matrix editor).
     *
     * @param array<int,int> $permissionIds
     */
    public function setRolePermissions(int $roleId, array $permissionIds, ?int $grantedBy = null): void
    {
        Database::transaction(static function () use ($roleId, $permissionIds, $grantedBy): void {
            Database::statement('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);

            foreach (array_unique($permissionIds) as $permissionId) {
                Database::statement(
                    'INSERT INTO role_permissions (role_id, permission_id, granted_by) VALUES (?, ?, ?)',
                    [$roleId, (int) $permissionId, $grantedBy],
                );
            }
        });
    }
}
