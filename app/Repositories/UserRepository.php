<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع المستخدمين | User repository.
 *
 * جدول `users` ليس مملوكاً لمنشأة (المستخدم قد ينتمي لعدة منشآت أو لا شيء)،
 * لذا لا يُقيَّد بالمستأجر. العزل يقع على العضويات لا على المستخدم نفسه.
 * `users` is not a tenant table — a user may belong to several organizations
 * or none — so it is not tenant-scoped. Isolation applies to memberships.
 */
final class UserRepository extends BaseRepository
{
    protected string $table = 'users';

    protected bool $tenantScoped = false;

    protected bool $softDeletes = true;

    protected array $sortable = ['id', 'name', 'email', 'status', 'created_at', 'last_login_at'];

    public function findByEmail(string $email): ?array
    {
        return Database::selectOne(
            'SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1',
            [mb_strtolower(trim($email))],
        );
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql      = 'SELECT 1 FROM users WHERE email = ?';
        $bindings = [mb_strtolower(trim($email))];

        if ($exceptId !== null) {
            $sql       .= ' AND id != ?';
            $bindings[] = $exceptId;
        }

        return Database::scalar($sql . ' LIMIT 1', $bindings) !== null;
    }

    /** إنشاء مستخدم | Create a user (password must already be hashed). */
    public function createUser(array $data): int
    {
        $data['email'] = mb_strtolower(trim((string) $data['email']));

        return $this->create($data);
    }

    public function markEmailVerified(int $userId): void
    {
        Database::statement(
            "UPDATE users
             SET email_verified_at = NOW(),
                 status = CASE WHEN status = 'pending' THEN 'active' ELSE status END
             WHERE id = ?",
            [$userId],
        );
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        Database::statement(
            'UPDATE users
             SET password_hash = ?, password_changed_at = NOW(),
                 must_change_password = 0, failed_login_count = 0, locked_until = NULL
             WHERE id = ?',
            [$passwordHash, $userId],
        );
    }

    public function recordSuccessfulLogin(int $userId, string $ip): void
    {
        $packed = @inet_pton($ip);

        Database::statement(
            'UPDATE users
             SET last_login_at = NOW(), last_login_ip = ?,
                 failed_login_count = 0, locked_until = NULL
             WHERE id = ?',
            [$packed === false ? null : $packed, $userId],
        );
    }

    /**
     * تسجيل محاولة فاشلة وقفل الحساب عند تجاوز الحد.
     * القفل على مستوى الحساب يكمّل تحديد المعدّل على مستوى IP: الأول يحمي من
     * تخمين كلمة مرور حساب بعينه، والثاني من الرش عبر حسابات كثيرة.
     */
    public function recordFailedLogin(int $userId, int $maxAttempts, int $lockMinutes): void
    {
        Database::statement(
            'UPDATE users
             SET failed_login_count = failed_login_count + 1,
                 locked_until = CASE
                     WHEN failed_login_count + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? MINUTE)
                     ELSE locked_until
                 END
             WHERE id = ?',
            [$maxAttempts, $lockMinutes, $userId],
        );
    }

    public function isLocked(array $user): bool
    {
        $lockedUntil = $user['locked_until'] ?? null;

        return is_string($lockedUntil) && strtotime($lockedUntil) > time();
    }

    public function setLastOrganization(int $userId, ?int $organizationId): void
    {
        Database::statement(
            'UPDATE users SET last_organization_id = ? WHERE id = ?',
            [$organizationId, $userId],
        );
    }

    public function suspend(int $userId, string $reason): void
    {
        Database::statement(
            "UPDATE users SET status = 'suspended', suspended_at = NOW(), suspended_reason = ? WHERE id = ?",
            [mb_substr($reason, 0, 500), $userId],
        );
    }

    public function reactivate(int $userId): void
    {
        Database::statement(
            "UPDATE users
             SET status = CASE WHEN email_verified_at IS NULL THEN 'pending' ELSE 'active' END,
                 suspended_at = NULL, suspended_reason = NULL
             WHERE id = ?",
            [$userId],
        );
    }

    /**
     * بحث إداري في المستخدمين | Admin user search.
     *
     * @return array{data:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function search(string $term, ?string $status, int $page, int $perPage = 20): array
    {
        $where    = ['u.deleted_at IS NULL'];
        $bindings = [];

        if (trim($term) !== '') {
            $where[]    = '(u.name LIKE ? OR u.email LIKE ?)';
            $like       = '%' . trim($term) . '%';
            $bindings[] = $like;
            $bindings[] = $like;
        }

        if ($status !== null && $status !== '') {
            $where[]    = 'u.status = ?';
            $bindings[] = $status;
        }

        $whereSql = implode(' AND ', $where);
        $total    = (int) Database::scalar("SELECT COUNT(*) FROM users u WHERE {$whereSql}", $bindings);

        $perPage = min(100, max(1, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            "SELECT u.*,
                    (SELECT GROUP_CONCAT(r.name_ar SEPARATOR '، ')
                       FROM user_roles ur
                       JOIN roles r ON r.id = ur.role_id
                      WHERE ur.user_id = u.id) AS platform_roles,
                    (SELECT COUNT(*) FROM organization_members om
                      WHERE om.user_id = u.id AND om.status = 'active') AS organization_count
               FROM users u
              WHERE {$whereSql}
              ORDER BY u.created_at DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return [
            'data'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
