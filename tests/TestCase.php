<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Application;
use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\PermissionResolver;
use App\Support\TenantContext;
use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * الصنف الأساسي للاختبارات | Base test case.
 *
 * كل اختبار يعمل داخل معاملة يُتراجع عنها في النهاية، فتبقى الاختبارات معزولة
 * عن بعضها ولا يعتمد أحدها على ترتيب التنفيذ.
 * Each test runs inside a transaction that is rolled back afterwards, so tests
 * stay isolated and order-independent.
 */
abstract class TestCase extends BaseTestCase
{
    protected static ?Application $app = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$app ??= Application::instance() ?? Application::boot(dirname(__DIR__));

        // إعادة تسجيل الخدمات بعد تصفير الحاوية في الاختبار السابق، حتى يبدأ كل
        // اختبار بحاوية نظيفة لكنها مكتملة الارتباطات.
        // Re-register services after the previous test reset the container, so
        // each test starts with a clean but fully-bound container.
        self::$app->registerServices();

        Session::enableTestMode();
        Session::clear();
        TenantContext::reset();

        // يجب المرور عبر Database لا عبر PDO مباشرة، حتى يعرف عدّاد التداخل
        // بوجود معاملة خارجية وتستخدم الخدمات نقاط الحفظ بدلاً من فتح معاملة ثانية.
        // Must go through Database, not raw PDO, so the nesting counter knows an
        // outer transaction exists and services use savepoints instead of
        // attempting a second transaction.
        Database::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (Database::inTransaction()) {
            Database::rollBack();
        }

        if (Database::connection()->inTransaction()) {
            Database::connection()->rollBack();
        }

        Session::disableTestMode();
        TenantContext::reset();
        Container::reset();

        parent::tearDown();
    }

    // ---------------- مصانع البيانات | Data factories ----------------

    /**
     * إنشاء مستخدم | Create a user.
     *
     * @param array<string,mixed> $attributes
     */
    protected function createUser(array $attributes = []): int
    {
        static $counter = 0;
        $counter++;

        $data = array_merge([
            'name'              => 'مستخدم اختبار ' . $counter,
            'email'             => 'user' . $counter . '_' . bin2hex(random_bytes(4)) . '@test.local',
            'password_hash'     => password_hash('TestPass!2026', PASSWORD_DEFAULT),
            'status'            => 'active',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ], $attributes);

        $columns = array_keys($data);

        return Database::insert(
            'INSERT INTO users (`' . implode('`, `', $columns) . '`) VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')',
            array_values($data),
        );
    }

    /**
     * إنشاء منشأة | Create an organization.
     *
     * @param array<string,mixed> $attributes
     */
    protected function createOrganization(array $attributes = []): int
    {
        static $counter = 0;
        $counter++;

        $typeCode = $attributes['type_code'] ?? 'sme';
        unset($attributes['type_code']);

        $typeId = (int) Database::scalar(
            'SELECT id FROM organization_types WHERE code = ? LIMIT 1',
            [$typeCode],
        );

        $data = array_merge([
            'organization_type_id' => $typeId,
            'legal_name'           => 'منشأة اختبار ' . $counter,
            'trading_name'         => 'منشأة ' . $counter,
            'slug'                 => 'test-org-' . $counter . '-' . bin2hex(random_bytes(4)),
            'status'               => 'verified',
        ], $attributes);

        $columns = array_keys($data);

        return Database::insert(
            'INSERT INTO organizations (`' . implode('`, `', $columns) . '`) VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')',
            array_values($data),
        );
    }

    /**
     * إنشاء صنف في السوق | Create a marketplace listing.
     *
     * @param array<string,mixed> $attributes
     */
    protected function createListing(int $organizationId, array $attributes = []): int
    {
        static $counter = 0;
        $counter++;

        $data = array_merge([
            'organization_id'    => $organizationId,
            'listing_type'       => 'product',
            'name_ar'            => 'صنف اختبار ' . $counter,
            'slug'               => 'test-listing-' . $counter . '-' . bin2hex(random_bytes(4)),
            'short_description'  => 'وصف مختصر لصنف الاختبار.',
            'pricing_mode'       => 'fixed',
            'price'              => 100.00,
            'currency_code'      => 'EGP',
            'vat_rate'           => 14.00,
            'unit_of_measure'    => 'قطعة',
            'available_quantity' => 50,
            'track_inventory'    => 1,
            'min_order_quantity' => 1,
            'status'             => 'published',
            'published_at'       => date('Y-m-d H:i:s'),
        ], $attributes);

        $columns = array_keys($data);

        return Database::insert(
            'INSERT INTO listings (`' . implode('`, `', $columns) . '`) VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')',
            array_values($data),
        );
    }

    /** ربط مستخدم بمنشأة بدور | Attach a user to an organization with a role. */
    protected function addMember(
        int $organizationId,
        int $userId,
        string $roleCode = 'sme_owner',
        string $status = 'active',
    ): int {
        $roleId = (int) Database::scalar('SELECT id FROM roles WHERE code = ? LIMIT 1', [$roleCode]);

        return Database::insert(
            'INSERT INTO organization_members
                (organization_id, user_id, role_id, status, joined_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$organizationId, $userId, $roleId, $status],
        );
    }

    protected function assignPlatformRole(int $userId, string $roleCode): void
    {
        $roleId = (int) Database::scalar('SELECT id FROM roles WHERE code = ? LIMIT 1', [$roleCode]);

        Database::statement(
            'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)',
            [$userId, $roleId],
        );
    }

    // ---------------- محاكاة الجلسة | Session simulation ----------------

    /**
     * تسجيل دخول مستخدم في سياق الاختبار | Sign a user in for the test.
     *
     * يعيد بناء سياق المستأجر بنفس منطق ResolveTenant حتى تختبر الاختبارات
     * السلوك الحقيقي لا محاكاة مبسّطة.
     * Rebuilds the tenant context using the same logic as ResolveTenant, so the
     * tests exercise real behaviour rather than a simplified stand-in.
     */
    protected function actingAs(int $userId, ?int $organizationId = null): void
    {
        Session::put('user_id', $userId);

        $resolver       = new PermissionResolver();
        $platformRoles  = $resolver->platformRoles($userId);
        $platformPerms  = $resolver->platformPermissions($userId);

        if ($organizationId === null) {
            TenantContext::set($userId, null, null, null, $platformPerms, $platformRoles);

            return;
        }

        $membership = Database::selectOne(
            "SELECT m.*, r.code AS role_code
               FROM organization_members m
               JOIN roles r ON r.id = m.role_id
               JOIN organizations o ON o.id = m.organization_id
              WHERE m.user_id = ? AND m.organization_id = ?
                AND m.status = 'active' AND o.deleted_at IS NULL
              LIMIT 1",
            [$userId, $organizationId],
        );

        if ($membership === null) {
            // لا عضوية ⇒ لا سياق منشأة، تماماً كما يفعل الوسيط.
            TenantContext::set($userId, null, null, null, $platformPerms, $platformRoles);

            return;
        }

        Session::put('active_organization_id', $organizationId);

        $organization = Database::selectOne(
            'SELECT * FROM organizations WHERE id = ? LIMIT 1',
            [$organizationId],
        );

        $membershipPerms = $resolver->membershipPermissions(
            (int) $membership['id'],
            (int) $membership['role_id'],
        );

        TenantContext::set(
            $userId,
            $organizationId,
            $organization,
            $membership,
            $resolver->merge($platformPerms, $membershipPerms),
            $platformRoles,
        );
    }

    // ---------------- مساعدات HTTP | HTTP helpers ----------------

    protected function request(string $method, string $path, array $body = [], array $query = []): Request
    {
        return Request::create($method, $path, $body, $query);
    }

    /** تنفيذ طلب عبر النواة كاملة | Dispatch through the full kernel. */
    protected function handle(Request $request): Response
    {
        return self::$app->handle($request);
    }

    // ---------------- تأكيدات مساعدة | Custom assertions ----------------

    protected function assertDatabaseHas(string $table, array $conditions): void
    {
        $where    = [];
        $bindings = [];

        foreach ($conditions as $column => $value) {
            $where[]    = "`{$column}` = ?";
            $bindings[] = $value;
        }

        $exists = Database::scalar(
            "SELECT 1 FROM `{$table}` WHERE " . implode(' AND ', $where) . ' LIMIT 1',
            $bindings,
        );

        $this->assertNotNull(
            $exists,
            "توقّعنا وجود سجل في {$table} بالشروط: " . json_encode($conditions, JSON_UNESCAPED_UNICODE),
        );
    }

    protected function assertDatabaseMissing(string $table, array $conditions): void
    {
        $where    = [];
        $bindings = [];

        foreach ($conditions as $column => $value) {
            $where[]    = "`{$column}` = ?";
            $bindings[] = $value;
        }

        $exists = Database::scalar(
            "SELECT 1 FROM `{$table}` WHERE " . implode(' AND ', $where) . ' LIMIT 1',
            $bindings,
        );

        $this->assertNull(
            $exists,
            "توقّعنا عدم وجود سجل في {$table} بالشروط: " . json_encode($conditions, JSON_UNESCAPED_UNICODE),
        );
    }

    protected function countRows(string $table, string $where = '1=1', array $bindings = []): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE {$where}", $bindings);
    }
}
