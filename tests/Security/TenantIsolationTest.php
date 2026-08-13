<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\BaseRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Support\TenantContext;
use RuntimeException;
use Tests\TestCase;

/**
 * إثبات العزل بين المنشآت | Proof of tenant isolation (§17).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * هذه أهم مجموعة اختبارات في المشروع. متطلّب §17 صريح:
 * «تضمين اختبارات صريحة تثبت أن منشأة لا تستطيع قراءة أو تعديل سجلات منشأة أخرى».
 *
 * The most important suite in the project. It proves that one organization can
 * neither read nor modify another's private records, and that forgetting to
 * scope a query fails loudly instead of leaking silently.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class TenantIsolationTest extends TestCase
{
    private int $orgA;
    private int $orgB;
    private int $userA;
    private int $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = $this->createUser(['name' => 'صاحب المنشأة أ']);
        $this->userB = $this->createUser(['name' => 'صاحب المنشأة ب']);

        $this->orgA = $this->createOrganization(['legal_name' => 'المنشأة أ', 'owner_user_id' => $this->userA]);
        $this->orgB = $this->createOrganization(['legal_name' => 'المنشأة ب', 'owner_user_id' => $this->userB]);

        $this->addMember($this->orgA, $this->userA, 'sme_owner');
        $this->addMember($this->orgB, $this->userB, 'sme_owner');
    }

    // ───────────────────────── سياق المستأجر | Tenant context ─────────────────────────

    public function test_user_cannot_activate_an_organization_they_do_not_belong_to(): void
    {
        // المستخدم "أ" يحاول العمل باسم المنشأة "ب"
        $this->actingAs($this->userA, $this->orgB);

        // actingAs يحاكي ResolveTenant: بلا عضوية ⇒ لا سياق منشأة إطلاقاً
        $this->assertNull(
            TenantContext::organizationId(),
            'لا يجوز أن يصبح المستخدم فاعلاً باسم منشأة ليس عضواً فيها.',
        );
        $this->assertFalse(TenantContext::hasOrganization());
    }

    public function test_membership_is_revalidated_and_suspended_member_loses_context(): void
    {
        $this->actingAs($this->userA, $this->orgA);
        $this->assertSame($this->orgA, TenantContext::organizationId());

        // إيقاف العضوية بعد بدء الجلسة
        Database::statement(
            "UPDATE organization_members SET status = 'suspended'
              WHERE organization_id = ? AND user_id = ?",
            [$this->orgA, $this->userA],
        );

        // الطلب التالي يعيد التحقق ⇒ يسقط السياق
        $this->actingAs($this->userA, $this->orgA);

        $this->assertNull(
            TenantContext::organizationId(),
            'إيقاف العضوية يجب أن يُسقط سياق المنشأة في الطلب التالي فوراً.',
        );
    }

    public function test_membership_lookup_rejects_cross_organization_pairs(): void
    {
        $memberships = new MembershipRepository();

        $this->assertNotNull($memberships->findActiveMembership($this->userA, $this->orgA));
        $this->assertNull(
            $memberships->findActiveMembership($this->userA, $this->orgB),
            'لا يجوز إيجاد عضوية للمستخدم أ في المنشأة ب.',
        );
        $this->assertNull($memberships->findActiveMembership($this->userB, $this->orgA));
    }

    public function test_membership_in_deleted_organization_grants_nothing(): void
    {
        Database::statement('UPDATE organizations SET deleted_at = NOW() WHERE id = ?', [$this->orgA]);

        $this->assertNull(
            (new MembershipRepository())->findActiveMembership($this->userA, $this->orgA),
            'العضوية في منشأة محذوفة لا تمنح أي حق.',
        );
    }

    // ───────────────────── قراءة السجلات | Cross-tenant reads ─────────────────────

    public function test_scoped_repository_cannot_read_another_organizations_record(): void
    {
        $repository = new TestScopedRepository();

        $recordA = $repository->scopedToTenant($this->orgA)->create(['title' => 'سجل خاص بالمنشأة أ']);
        $recordB = $repository->scopedToTenant($this->orgB)->create(['title' => 'سجل خاص بالمنشأة ب']);

        // كل منشأة ترى سجلها
        $this->assertNotNull($repository->scopedToTenant($this->orgA)->find($recordA));
        $this->assertNotNull($repository->scopedToTenant($this->orgB)->find($recordB));

        // ولا ترى سجل الأخرى
        $this->assertNull(
            $repository->scopedToTenant($this->orgA)->find($recordB),
            'المنشأة أ يجب ألّا ترى سجل المنشأة ب.',
        );
        $this->assertNull(
            $repository->scopedToTenant($this->orgB)->find($recordA),
            'المنشأة ب يجب ألّا ترى سجل المنشأة أ.',
        );
    }

    public function test_find_or_fail_returns_not_found_rather_than_forbidden_for_other_tenants(): void
    {
        $repository = new TestScopedRepository();
        $recordB    = $repository->scopedToTenant($this->orgB)->create(['title' => 'سجل ب']);

        // 404 لا 403: عدم كشف وجود سجلات المنشآت الأخرى يمنع استنتاج معلومات عنها
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $repository->scopedToTenant($this->orgA)->findOrFail($recordB);
    }

    public function test_listing_and_counting_never_include_other_tenants_rows(): void
    {
        $repository = new TestScopedRepository();

        $repository->scopedToTenant($this->orgA)->create(['title' => 'أ-1']);
        $repository->scopedToTenant($this->orgA)->create(['title' => 'أ-2']);
        $repository->scopedToTenant($this->orgB)->create(['title' => 'ب-1']);
        $repository->scopedToTenant($this->orgB)->create(['title' => 'ب-2']);
        $repository->scopedToTenant($this->orgB)->create(['title' => 'ب-3']);

        $this->assertSame(2, $repository->scopedToTenant($this->orgA)->count());
        $this->assertSame(3, $repository->scopedToTenant($this->orgB)->count());

        $pageA = $repository->scopedToTenant($this->orgA)->paginate(1, 50);
        $this->assertCount(2, $pageA['data']);

        foreach ($pageA['data'] as $row) {
            $this->assertSame(
                $this->orgA,
                (int) $row['organization_id'],
                'ظهر صف من منشأة أخرى في نتائج القائمة.',
            );
        }
    }

    // ───────────────────── الكتابة | Cross-tenant writes ─────────────────────

    public function test_update_across_tenants_affects_no_rows(): void
    {
        $repository = new TestScopedRepository();
        $recordB    = $repository->scopedToTenant($this->orgB)->create(['title' => 'العنوان الأصلي']);

        $affected = $repository->scopedToTenant($this->orgA)->update($recordB, ['title' => 'محاولة تعديل']);

        $this->assertSame(0, $affected, 'التعديل عبر المنشآت يجب ألّا يمسّ أي صف.');

        $row = $repository->scopedToTenant($this->orgB)->find($recordB);
        $this->assertSame('العنوان الأصلي', $row['title'], 'تغيّر محتوى سجل المنشأة ب رغم رفض العملية.');
    }

    public function test_delete_across_tenants_affects_no_rows(): void
    {
        $repository = new TestScopedRepository();
        $recordB    = $repository->scopedToTenant($this->orgB)->create(['title' => 'سجل ب']);

        $affected = $repository->scopedToTenant($this->orgA)->delete($recordB);

        $this->assertSame(0, $affected);
        $this->assertNotNull($repository->scopedToTenant($this->orgB)->find($recordB));
    }

    public function test_ownership_column_cannot_be_forged_on_create(): void
    {
        $repository = new TestScopedRepository();

        // محاولة زرع سجل داخل المنشأة "ب" من سياق المنشأة "أ"
        $recordId = $repository->scopedToTenant($this->orgA)->create([
            'title'           => 'محاولة زرع',
            'organization_id' => $this->orgB,
        ]);

        $owner = (int) Database::scalar('SELECT organization_id FROM test_scoped_records WHERE id = ?', [$recordId]);

        $this->assertSame(
            $this->orgA,
            $owner,
            'قيمة organization_id المُمرَّرة يجب أن تُتجاهَل ويُفرَض نطاق المستودع.',
        );
    }

    public function test_ownership_cannot_be_reassigned_through_update(): void
    {
        $repository = new TestScopedRepository();
        $recordA    = $repository->scopedToTenant($this->orgA)->create(['title' => 'سجل أ']);

        $repository->scopedToTenant($this->orgA)->update($recordA, [
            'title'           => 'عنوان جديد',
            'organization_id' => $this->orgB,
        ]);

        $owner = (int) Database::scalar('SELECT organization_id FROM test_scoped_records WHERE id = ?', [$recordA]);

        $this->assertSame($this->orgA, $owner, 'لا يجوز نقل ملكية السجل إلى منشأة أخرى عبر التحديث.');
    }

    // ───────────────── الفشل الصاخب | Fail-closed behaviour ─────────────────

    public function test_unscoped_query_on_a_tenant_table_throws_instead_of_leaking(): void
    {
        $repository = new TestScopedRepository();

        // نسيان تحديد النطاق يجب أن يُفشل العملية بوضوح لا أن يُرجع كل الصفوف
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/استعلام غير مُقيَّد/u');

        $repository->find(1);
    }

    public function test_unscoped_create_on_a_tenant_table_throws(): void
    {
        $repository = new TestScopedRepository();

        $this->expectException(RuntimeException::class);

        $repository->create(['title' => 'بلا نطاق']);
    }

    public function test_global_scope_requires_an_explicit_reason(): void
    {
        $repository = new TestScopedRepository();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/سبباً صريحاً/u');

        $repository->globalScope('   ');
    }

    public function test_global_scope_is_available_for_platform_administration(): void
    {
        $repository = new TestScopedRepository();

        $repository->scopedToTenant($this->orgA)->create(['title' => 'أ']);
        $repository->scopedToTenant($this->orgB)->create(['title' => 'ب']);

        // التجاوز الموثّق يرى كل الصفوف — هذا هو المسار الإداري المشروع
        $this->assertSame(2, $repository->globalScope('مراجعة إدارية في الاختبار')->count());
    }

    public function test_scoping_without_context_or_argument_throws(): void
    {
        TenantContext::reset();

        $repository = new TestScopedRepository();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/تعذّر تحديد المنشأة النشطة/u');

        $repository->scopedToTenant();
    }

    public function test_scope_uses_active_tenant_when_no_argument_is_given(): void
    {
        $this->actingAs($this->userA, $this->orgA);

        $repository = new TestScopedRepository();
        $recordA    = $repository->scopedToTenant()->create(['title' => 'من السياق النشط']);

        $owner = (int) Database::scalar('SELECT organization_id FROM test_scoped_records WHERE id = ?', [$recordA]);

        $this->assertSame($this->orgA, $owner);
    }

    // ───────────────── لوائح المستخدم | User-facing listings ─────────────────

    public function test_user_only_sees_organizations_they_belong_to(): void
    {
        $organizations = (new MembershipRepository())->organizationsForUser($this->userA);
        $ids           = array_map(static fn (array $row): int => (int) $row['id'], $organizations);

        $this->assertContains($this->orgA, $ids);
        $this->assertNotContains($this->orgB, $ids, 'ظهرت منشأة لا ينتمي إليها المستخدم في قائمته.');
    }

    public function test_a_user_may_hold_different_roles_in_different_organizations(): void
    {
        // المستخدم "أ" مالك في "أ" وموظف في "ب" — الحالة التي يفرضها §7
        $this->addMember($this->orgB, $this->userA, 'sme_employee');

        $this->actingAs($this->userA, $this->orgA);
        $this->assertTrue(
            TenantContext::can('marketplace.listing.create'),
            'المالك يجب أن يملك صلاحية إضافة المنتجات في منشأته.',
        );

        $this->actingAs($this->userA, $this->orgB);
        $this->assertFalse(
            TenantContext::can('marketplace.listing.create'),
            'الموظف يجب ألّا يرث صلاحيات المالك من منشأة أخرى.',
        );
        $this->assertTrue(TenantContext::can('marketplace.listing.view'));
    }

    public function test_organization_repository_scopes_by_slug_and_soft_delete(): void
    {
        $repository = new OrganizationRepository();

        $slug = (string) Database::scalar('SELECT slug FROM organizations WHERE id = ?', [$this->orgA]);
        $this->assertNotNull($repository->findBySlug($slug));

        Database::statement('UPDATE organizations SET deleted_at = NOW() WHERE id = ?', [$this->orgA]);

        $this->assertNull(
            $repository->findBySlug($slug),
            'المنشأة المحذوفة ناعماً يجب ألّا تظهر في الصفحات العامة.',
        );
    }
}

/**
 * مستودع اختباري على جدول مؤقت | Test-only repository over a scratch table.
 *
 * يختبر سلوك BaseRepository نفسه بدل الاعتماد على جدول أعمال بعينه، فيبقى
 * الاختبار صالحاً مهما تغيّرت وحدات المراحل التالية.
 * Exercises BaseRepository itself rather than any particular business table, so
 * the guarantee keeps holding as later modules are added.
 */
final class TestScopedRepository extends BaseRepository
{
    protected string $table = 'test_scoped_records';

    protected bool $tenantScoped = true;

    protected bool $softDeletes = false;

    protected array $sortable = ['id', 'title'];

    public function __construct()
    {
        Database::connection()->exec(
            'CREATE TEMPORARY TABLE IF NOT EXISTS test_scoped_records (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                organization_id INT UNSIGNED NOT NULL,
                title VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_org (organization_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
