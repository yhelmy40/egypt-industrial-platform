<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع العضويات | Organization membership repository.
 *
 * هذا المستودع هو حارس البوابة للعزل: كل قرار «هل يحق لهذا المستخدم العمل
 * باسم هذه المنشأة؟» يمرّ من هنا ويستند إلى صف عضوية نشط في قاعدة البيانات.
 * This repository is the isolation gatekeeper: every "may this user act for
 * this organization?" decision resolves here against an active membership row.
 */
final class MembershipRepository extends BaseRepository
{
    protected string $table = 'organization_members';

    // العضوية نفسها ليست مقيّدة بالمستأجر لأنها أداة تحديد المستأجر.
    // Memberships are not tenant-scoped: they are what determines the tenant.
    protected bool $tenantScoped = false;

    /**
     * التحقق من عضوية نشطة | Verify an active membership.
     *
     * يُستدعى في كل طلب من ResolveTenant. يتحقق أيضاً من أن المنشأة نفسها
     * غير محذوفة، فالعضوية في منشأة محذوفة لا تمنح أي حق.
     */
    public function findActiveMembership(int $userId, int $organizationId): ?array
    {
        return Database::selectOne(
            "SELECT m.*, r.code AS role_code, r.name_ar AS role_name, r.scope AS role_scope
               FROM organization_members m
               JOIN roles r ON r.id = m.role_id
               JOIN organizations o ON o.id = m.organization_id
              WHERE m.user_id = ?
                AND m.organization_id = ?
                AND m.status = 'active'
                AND o.deleted_at IS NULL
              LIMIT 1",
            [$userId, $organizationId],
        );
    }

    /**
     * كل منشآت المستخدم | All organizations the user may act for.
     *
     * @return array<int,array<string,mixed>>
     */
    public function organizationsForUser(int $userId): array
    {
        return Database::select(
            "SELECT o.id, o.legal_name, o.trading_name, o.slug, o.status, o.logo_path,
                    o.completion_score, o.organization_type_id,
                    t.code AS type_code, t.name_ar AS type_name,
                    m.id AS member_id, m.role_id, m.status AS member_status,
                    r.code AS role_code, r.name_ar AS role_name
               FROM organization_members m
               JOIN organizations o ON o.id = m.organization_id
               JOIN organization_types t ON t.id = o.organization_type_id
               JOIN roles r ON r.id = m.role_id
              WHERE m.user_id = ?
                AND m.status = 'active'
                AND o.deleted_at IS NULL
              ORDER BY o.legal_name ASC",
            [$userId],
        );
    }

    /** أول منشأة متاحة للمستخدم | The user's default organization. */
    public function defaultOrganizationId(int $userId): ?int
    {
        $row = Database::selectOne(
            "SELECT m.organization_id
               FROM organization_members m
               JOIN organizations o ON o.id = m.organization_id
              WHERE m.user_id = ? AND m.status = 'active' AND o.deleted_at IS NULL
              ORDER BY (o.status = 'verified') DESC, m.created_at ASC
              LIMIT 1",
            [$userId],
        );

        return $row === null ? null : (int) $row['organization_id'];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function membersOf(int $organizationId): array
    {
        return Database::select(
            "SELECT m.*, u.name, u.email, u.status AS user_status, u.last_login_at,
                    r.code AS role_code, r.name_ar AS role_name
               FROM organization_members m
               JOIN users u ON u.id = m.user_id
               JOIN roles r ON r.id = m.role_id
              WHERE m.organization_id = ?
                AND m.status != 'removed'
                AND u.deleted_at IS NULL
              ORDER BY m.is_primary_contact DESC, m.created_at ASC",
            [$organizationId],
        );
    }

    public function addMember(
        int $organizationId,
        int $userId,
        int $roleId,
        string $status = 'active',
        ?int $invitedBy = null,
        bool $isPrimaryContact = false,
        ?string $jobTitle = null,
    ): int {
        return Database::insert(
            'INSERT INTO organization_members
                (organization_id, user_id, role_id, job_title, status,
                 is_primary_contact, invited_by, joined_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $organizationId,
                $userId,
                $roleId,
                $jobTitle,
                $status,
                $isPrimaryContact ? 1 : 0,
                $invitedBy,
                $status === 'active' ? date('Y-m-d H:i:s') : null,
            ],
        );
    }

    public function updateMemberRole(int $memberId, int $organizationId, int $roleId): int
    {
        // المنشأة في الشرط تمنع تعديل عضو في منشأة أخرى عبر تخمين المعرّف.
        return Database::affectingStatement(
            'UPDATE organization_members SET role_id = ? WHERE id = ? AND organization_id = ?',
            [$roleId, $memberId, $organizationId],
        );
    }

    public function removeMember(int $memberId, int $organizationId): int
    {
        return Database::affectingStatement(
            "UPDATE organization_members
                SET status = 'removed', removed_at = NOW()
              WHERE id = ? AND organization_id = ? AND is_primary_contact = 0",
            [$memberId, $organizationId],
        );
    }

    public function suspendMember(int $memberId, int $organizationId): int
    {
        return Database::affectingStatement(
            "UPDATE organization_members SET status = 'suspended' WHERE id = ? AND organization_id = ?",
            [$memberId, $organizationId],
        );
    }

    /**
     * تجاوزات الصلاحيات للعضو | Per-member permission overrides.
     *
     * @return array{grant:array<int,string>,deny:array<int,string>}
     */
    public function memberPermissionOverrides(int $memberId): array
    {
        $rows = Database::select(
            'SELECT p.code, mp.effect
               FROM organization_member_permissions mp
               JOIN permissions p ON p.id = mp.permission_id
              WHERE mp.member_id = ?',
            [$memberId],
        );

        $result = ['grant' => [], 'deny' => []];

        foreach ($rows as $row) {
            $result[$row['effect']][] = (string) $row['code'];
        }

        return $result;
    }

    /**
     * ضبط تجاوزات صلاحيات عضو | Replace a member's permission overrides.
     *
     * @param array<int,string> $grant
     * @param array<int,string> $deny
     */
    public function setMemberPermissions(int $memberId, array $grant, array $deny, ?int $grantedBy): void
    {
        Database::transaction(static function () use ($memberId, $grant, $deny, $grantedBy): void {
            Database::statement(
                'DELETE FROM organization_member_permissions WHERE member_id = ?',
                [$memberId],
            );

            foreach (['grant' => $grant, 'deny' => $deny] as $effect => $codes) {
                foreach ($codes as $code) {
                    $permissionId = Database::scalar(
                        // فقط الصلاحيات التي يجوز للمالك إسنادها (§3.5)
                        'SELECT id FROM permissions WHERE code = ? AND assignable_by_owner = 1',
                        [$code],
                    );

                    if ($permissionId === null) {
                        continue;
                    }

                    Database::statement(
                        'INSERT INTO organization_member_permissions
                            (member_id, permission_id, effect, granted_by)
                         VALUES (?, ?, ?, ?)',
                        [$memberId, (int) $permissionId, $effect, $grantedBy],
                    );
                }
            }
        });
    }

    /**
     * تجاوزات صلاحيات كل أعضاء المنشأة | Permission overrides for every member.
     *
     * تُقرأ دفعةً واحدة لتغذية شاشة الفريق دون استعلام لكل عضو.
     *
     * @return array<int,array{grant:array<int,string>,deny:array<int,string>}>
     */
    public function permissionOverridesForOrganization(int $organizationId): array
    {
        $rows = Database::select(
            'SELECT mp.member_id, mp.effect, p.code
               FROM organization_member_permissions mp
               JOIN organization_members m ON m.id = mp.member_id
               JOIN permissions p ON p.id = mp.permission_id
              WHERE m.organization_id = ?',
            [$organizationId],
        );

        $overrides = [];
        foreach ($rows as $row) {
            $memberId = (int) $row['member_id'];
            $overrides[$memberId] ??= ['grant' => [], 'deny' => []];
            $overrides[$memberId][(string) $row['effect']][] = (string) $row['code'];
        }

        return $overrides;
    }

    public function countActiveMembers(int $organizationId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM organization_members
              WHERE organization_id = ? AND status = 'active'",
            [$organizationId],
        );
    }
}
