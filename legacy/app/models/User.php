<?php
/** نموذج المستخدمين | User model */
class User extends Model
{
    protected string $table = 'users';

    public function findByEmail(string $email): ?array
    {
        return $this->queryOne("SELECT * FROM users WHERE email = ? LIMIT 1", [$email]);
    }

    /** التحقق من بيانات الدخول | Verify login credentials */
    public function attempt(string $email, string $password): ?array
    {
        $user = $this->findByEmail($email);
        if ($user && (int) $user['is_active'] === 1 && password_verify($password, $user['password'])) {
            return $user;
        }
        return null;
    }

    /** إنشاء مستخدم جديد | Create a user (hashes password) */
    public function createUser(string $name, string $email, string $password, string $role, string $phone = ''): int
    {
        return $this->insert([
            'name'       => $name,
            'email'      => $email,
            'password'   => password_hash($password, PASSWORD_DEFAULT),
            'role'       => $role,
            'phone'      => $phone,
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function countByRole(string $role): int
    {
        return $this->count('role = ?', [$role]);
    }

    public function allByRole(string $role): array
    {
        return $this->query("SELECT * FROM users WHERE role = ? ORDER BY name ASC", [$role]);
    }
}
