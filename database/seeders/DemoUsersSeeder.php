<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;

/**
 * حسابات العرض التوضيحي | Demonstration accounts (§15).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠ بيانات تجريبية — لا تعمل في بيئة الإنتاج
 * ═══════════════════════════════════════════════════════════════════════════
 * حمايتان متراكبتان تمنعان وصول هذه الحسابات إلى الإنتاج:
 *  1. isDemo() = true، و SeederRunner يتخطّى بذور العرض في الإنتاج.
 *  2. must_change_password = 1 على كل حساب، وكلمات المرور المستخدمة مُدرجة في
 *     قائمة المنع في config/security.php فلا يمكن إعادة تعيينها كما هي.
 *
 * Two overlapping guards keep these accounts out of production: the demo-seeder
 * skip, and a forced password change whose demo passwords are themselves on the
 * blocklist, so they cannot be re-set to the same value.
 */
final class DemoUsersSeeder extends Seeder
{
    public function isDemo(): bool
    {
        return true;
    }

    public function order(): int
    {
        return 50;
    }

    public function run(): void
    {
        $this->guardProduction();

        foreach ($this->accounts() as $account) {
            $userId = $this->upsert('users', [
                'email'                => $account['email'],
                'name'                 => $account['name'],
                'phone'                => $account['phone'],
                'password_hash'        => password_hash($account['password'], PASSWORD_DEFAULT),
                'status'               => 'active',
                // مفعّل مسبقاً حتى يمكن تسجيل الدخول للعرض مباشرة
                'email_verified_at'    => date('Y-m-d H:i:s'),
                'must_change_password' => 1,
                'locale'               => 'ar',
                'timezone'             => 'Africa/Cairo',
            ], ['email']);

            if ($account['platform_role'] !== null) {
                $role = Database::selectOne(
                    "SELECT id FROM roles WHERE code = ? AND scope = 'platform' LIMIT 1",
                    [$account['platform_role']],
                );

                if ($role !== null) {
                    Database::statement(
                        'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)',
                        [$userId, (int) $role['id']],
                    );
                }
            }
        }

        $this->info(count($this->accounts()) . ' حساب عرض توضيحي (يجب تغيير كلمة المرور عند أول دخول).');
    }

    /**
     * @return array<int,array{email:string,name:string,phone:?string,password:string,platform_role:?string}>
     */
    private function accounts(): array
    {
        return [
            [
                'email'         => 'admin@nilepreneurs.test',
                'name'          => 'مدير المنصة (تجريبي)',
                'phone'         => '01000000001',
                'password'      => 'DemoAdmin!2026',
                'platform_role' => 'super_admin',
            ],
            [
                'email'         => 'ops@nilepreneurs.test',
                'name'          => 'مسؤول التشغيل (تجريبي)',
                'phone'         => '01000000002',
                'password'      => 'DemoOps!2026',
                'platform_role' => 'ops_officer',
            ],
            [
                'email'         => 'editor@nilepreneurs.test',
                'name'          => 'محرّر المحتوى (تجريبي)',
                'phone'         => '01000000003',
                'password'      => 'DemoEditor!2026',
                'platform_role' => 'content_editor',
            ],
            [
                'email'         => 'sme@nilepreneurs.test',
                'name'          => 'صاحب مشروع (تجريبي)',
                'phone'         => '01000000010',
                'password'      => 'DemoSme!2026',
                'platform_role' => 'marketplace_customer',
            ],
            [
                'email'         => 'employee@nilepreneurs.test',
                'name'          => 'موظف بالمشروع (تجريبي)',
                'phone'         => '01000000011',
                'password'      => 'DemoEmp!2026',
                'platform_role' => 'marketplace_customer',
            ],
            [
                'email'         => 'bank@nilepreneurs.test',
                'name'          => 'مسؤول بنك (تجريبي)',
                'phone'         => '01000000020',
                'password'      => 'DemoBank!2026',
                'platform_role' => 'marketplace_customer',
            ],
            [
                'email'         => 'provider@nilepreneurs.test',
                'name'          => 'مقدّم خدمة (تجريبي)',
                'phone'         => '01000000030',
                'password'      => 'DemoProv!2026',
                'platform_role' => 'marketplace_customer',
            ],
            [
                'email'         => 'ngo@nilepreneurs.test',
                'name'          => 'منظمة أهلية (تجريبي)',
                'phone'         => '01000000040',
                'password'      => 'DemoNgo!2026',
                'platform_role' => 'marketplace_customer',
            ],
            [
                'email'         => 'bds@nilepreneurs.test',
                'name'          => 'أخصائي تطوير أعمال (تجريبي)',
                'phone'         => '01000000050',
                'password'      => 'DemoBds!2026',
                'platform_role' => 'marketplace_customer',
            ],
            [
                'email'         => 'customer@nilepreneurs.test',
                'name'          => 'عميل السوق (تجريبي)',
                'phone'         => '01000000060',
                'password'      => 'DemoCust!2026',
                'platform_role' => 'marketplace_customer',
            ],
        ];
    }
}
