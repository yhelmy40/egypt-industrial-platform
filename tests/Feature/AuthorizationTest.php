<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Repositories\MembershipRepository;
use App\Services\PermissionResolver;
use App\Support\TenantContext;
use Tests\TestCase;

/**
 * اختبارات الأدوار والصلاحيات | Role and permission tests (§17).
 */
final class AuthorizationTest extends TestCase
{
    private PermissionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PermissionResolver();
    }

    // ───────────────────── أدوار المنصة | Platform roles ─────────────────────

    public function test_super_admin_holds_every_permission(): void
    {
        $userId = $this->createUser();
        $this->assignPlatformRole($userId, 'super_admin');

        $granted = $this->resolver->platformPermissions($userId);
        $total   = (int) Database::scalar('SELECT COUNT(*) FROM permissions');

        $this->assertCount($total, $granted, 'مدير المنصة يجب أن يملك كل الصلاحيات المعرّفة.');
    }

    public function test_operations_officer_can_verify_but_cannot_edit_the_permission_matrix(): void
    {
        $userId = $this->createUser();
        $this->assignPlatformRole($userId, 'ops_officer');
        $this->actingAs($userId);

        $this->assertTrue(TenantContext::can('org.account.verify'), 'مسؤول التشغيل يراجع طلبات التوثيق.');
        $this->assertTrue(TenantContext::can('marketplace.listing.moderate'));

        // الفصل بين المراجعة التشغيلية وتغيير قواعد الصلاحيات نفسها
        $this->assertFalse(TenantContext::can('platform.role.manage'));
        $this->assertFalse(TenantContext::can('platform.settings.update'));
        $this->assertFalse(TenantContext::can('platform.reference.manage'));
    }

    public function test_marketplace_customer_has_only_browsing_permissions(): void
    {
        $userId = $this->createUser();
        $this->assignPlatformRole($userId, 'marketplace_customer');
        $this->actingAs($userId);

        $this->assertTrue(TenantContext::can('marketplace.listing.view'));

        $this->assertFalse(TenantContext::can('marketplace.listing.create'));
        $this->assertFalse(TenantContext::can('org.account.verify'));
        $this->assertFalse(TenantContext::can('finance.application.decide'));
        $this->assertFalse(TenantContext::can('erp.invoice.manage'));
    }

    public function test_a_user_without_roles_has_no_permissions(): void
    {
        $userId = $this->createUser();
        $this->actingAs($userId);

        $this->assertSame([], TenantContext::permissions());
        $this->assertFalse(TenantContext::can('marketplace.listing.view'));
    }

    // ─────────────────── أدوار المنشآت | Organization roles ───────────────────

    public function test_sme_owner_can_manage_their_organization(): void
    {
        $userId = $this->createUser();
        $orgId  = $this->createOrganization();
        $this->addMember($orgId, $userId, 'sme_owner');
        $this->actingAs($userId, $orgId);

        foreach ([
            'org.profile.update', 'org.member.manage', 'org.page.manage',
            'marketplace.listing.create', 'finance.application.submit',
            'crm.contact.manage', 'erp.invoice.manage',
        ] as $permission) {
            $this->assertTrue(TenantContext::can($permission), "صاحب المشروع يجب أن يملك: {$permission}");
        }
    }

    public function test_sme_owner_cannot_perform_platform_administration(): void
    {
        $userId = $this->createUser();
        $orgId  = $this->createOrganization();
        $this->addMember($orgId, $userId, 'sme_owner');
        $this->actingAs($userId, $orgId);

        foreach ([
            'org.account.verify', 'platform.role.manage', 'platform.audit.view',
            'marketplace.listing.moderate', 'finance.application.decide',
            'org.account.view_any', 'marketplace.order.view_any',
        ] as $permission) {
            $this->assertFalse(TenantContext::can($permission), "صاحب المشروع يجب ألّا يملك: {$permission}");
        }
    }

    public function test_sme_employee_starts_with_a_minimal_permission_set(): void
    {
        $userId = $this->createUser();
        $orgId  = $this->createOrganization();
        $this->addMember($orgId, $userId, 'sme_employee');
        $this->actingAs($userId, $orgId);

        $this->assertTrue(TenantContext::can('marketplace.order.view'));

        // لا يرث صلاحيات المالك تلقائياً
        $this->assertFalse(TenantContext::can('org.member.manage'));
        $this->assertFalse(TenantContext::can('marketplace.listing.create'));
        $this->assertFalse(TenantContext::can('erp.invoice.manage'));
    }

    public function test_only_the_provider_may_record_a_financing_decision(): void
    {
        // متطلّب §4.5: المنصة لا تُظهر طلباً كموافَق عليه إلا بتسجيل المزوّد للقرار
        $bankUser = $this->createUser();
        $bankOrg  = $this->createOrganization(['type_code' => 'bank']);
        $this->addMember($bankOrg, $bankUser, 'bank_officer');
        $this->actingAs($bankUser, $bankOrg);

        $this->assertTrue(
            TenantContext::can('finance.application.decide'),
            'مسؤول الائتمان لدى المزوّد هو صاحب قرار الموافقة.',
        );

        // مسؤول تشغيل المنصة يفرز ولا يقرّر
        $opsUser = $this->createUser();
        $this->assignPlatformRole($opsUser, 'ops_officer');
        $this->actingAs($opsUser);

        $this->assertTrue(TenantContext::can('finance.application.screen'));
        $this->assertFalse(
            TenantContext::can('finance.application.decide'),
            'المنصة يجب ألّا تملك صلاحية اتخاذ قرار التمويل.',
        );
    }

    public function test_bds_internal_notes_are_denied_to_every_sme_role(): void
    {
        // متطلّب §4.10: الملاحظات الداخلية للأخصائي محجوبة عن صاحب المشروع
        $smeUser = $this->createUser();
        $smeOrg  = $this->createOrganization();
        $this->addMember($smeOrg, $smeUser, 'sme_owner');
        $this->actingAs($smeUser, $smeOrg);

        $this->assertFalse(
            TenantContext::can('bds.note.internal'),
            'صاحب المشروع يجب ألّا يملك صلاحية الاطلاع على الملاحظات الداخلية.',
        );

        $specialist = $this->createUser();
        $bdsOrg     = $this->createOrganization(['type_code' => 'bds_center']);
        $this->addMember($bdsOrg, $specialist, 'bds_specialist');
        $this->actingAs($specialist, $bdsOrg);

        $this->assertTrue(TenantContext::can('bds.note.internal'));
        $this->assertTrue(TenantContext::can('bds.note.shared'));
    }

    // ─────────────── تجاوزات صلاحيات العضو | Member overrides ───────────────

    public function test_owner_can_grant_an_extra_permission_to_an_employee(): void
    {
        $employee = $this->createUser();
        $orgId    = $this->createOrganization();
        $memberId = $this->addMember($orgId, $employee, 'sme_employee');

        $this->actingAs($employee, $orgId);
        $this->assertFalse(TenantContext::can('erp.invoice.manage'));

        (new MembershipRepository())->setMemberPermissions($memberId, ['erp.invoice.manage'], [], null);

        $this->actingAs($employee, $orgId);
        $this->assertTrue(
            TenantContext::can('erp.invoice.manage'),
            'المنح الفردي يجب أن يضيف الصلاحية دون تغيير الدور.',
        );
    }

    public function test_a_denial_override_beats_the_role_grant(): void
    {
        $owner    = $this->createUser();
        $orgId    = $this->createOrganization();
        $memberId = $this->addMember($orgId, $owner, 'sme_owner');

        $this->actingAs($owner, $orgId);
        $this->assertTrue(TenantContext::can('erp.invoice.manage'));

        (new MembershipRepository())->setMemberPermissions($memberId, [], ['erp.invoice.manage'], null);

        $this->actingAs($owner, $orgId);
        $this->assertFalse(
            TenantContext::can('erp.invoice.manage'),
            'المنع الصريح يجب أن يغلب منح الدور.',
        );
    }

    public function test_only_owner_assignable_permissions_can_be_delegated(): void
    {
        $employee = $this->createUser();
        $orgId    = $this->createOrganization();
        $memberId = $this->addMember($orgId, $employee, 'sme_employee');

        // org.verify صلاحية منصّة لا يجوز لصاحب مشروع منحها لأحد
        (new MembershipRepository())->setMemberPermissions($memberId, ['org.account.verify'], [], null);

        $this->actingAs($employee, $orgId);

        $this->assertFalse(
            TenantContext::can('org.account.verify'),
            'صلاحيات المنصة يجب ألّا تكون قابلة للمنح من داخل المنشأة.',
        );
        $this->assertSame(
            0,
            $this->countRows('organization_member_permissions', 'member_id = ?', [$memberId]),
            'الصلاحية غير القابلة للإسناد يجب أن تُرفض عند الحفظ.',
        );
    }

    // ─────────────────── سلامة المصفوفة | Matrix integrity ───────────────────

    public function test_every_role_has_at_least_one_permission(): void
    {
        $orphans = Database::select(
            'SELECT r.code FROM roles r
               LEFT JOIN role_permissions rp ON rp.role_id = r.id
              WHERE r.is_active = 1
              GROUP BY r.id, r.code
             HAVING COUNT(rp.permission_id) = 0',
        );

        $this->assertSame([], $orphans, 'كل دور نشط يجب أن يملك صلاحيات محدّدة.');
    }

    public function test_permission_codes_follow_the_module_resource_action_convention(): void
    {
        $codes = array_column(Database::select('SELECT code FROM permissions'), 'code');

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression(
                '/^[a-z_]+\.[a-z_]+\.[a-z_]+$/',
                (string) $code,
                "الصلاحية {$code} لا تتبع صيغة module.resource.action",
            );
        }
    }

    /**
     * صلاحيات إشرافية على مستوى المنصة | Platform-oversight permissions.
     *
     * تمنح سلطة على منشآت أخرى أو على المنصة نفسها، فلا يجوز أن يحملها أي دور
     * بنطاق منشأة مهما كان نوعها. القائمة صريحة هنا حتى يفشل الاختبار إذا
     * التقطها نمط عام (wildcard) في مصفوفة الأدوار مستقبلاً.
     * These confer authority over other organizations or the platform itself,
     * so no organization-scope role may hold them. The list is explicit so the
     * test fails if a future wildcard sweeps one in.
     *
     * @return array<int,string>
     */
    private function platformOversightPermissions(): array
    {
        return [
            'platform.settings.update', 'platform.reference.manage',
            'platform.role.manage', 'platform.user.manage', 'platform.user.suspend',
            'platform.audit.view',
            'org.account.verify', 'org.account.suspend', 'org.account.view_any',
            'marketplace.listing.moderate', 'marketplace.order.view_any',
            'marketplace.review.moderate', 'marketplace.complaint.manage',
            'finance.application.view_any', 'finance.application.screen',
            'services.request.view_any', 'assessment.needs.view_any',
            'assessment.template.manage', 'bds.case.view_any',
            'reports.platform.view', 'privacy.request.manage',
        ];
    }

    public function test_no_organization_role_holds_a_platform_oversight_permission(): void
    {
        $placeholders = implode(', ', array_fill(0, count($this->platformOversightPermissions()), '?'));

        $leaks = Database::select(
            "SELECT r.code AS role_code, p.code AS permission_code
               FROM roles r
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.scope = 'organization'
                AND p.code IN ({$placeholders})
              ORDER BY r.code, p.code",
            $this->platformOversightPermissions(),
        );

        $described = array_map(
            static fn (array $row): string => $row['role_code'] . ' ← ' . $row['permission_code'],
            $leaks,
        );

        $this->assertSame(
            [],
            $leaks,
            'أدوار المنشآت لا يجوز أن تحمل صلاحيات إشراف على مستوى المنصة: '
            . implode('، ', $described),
        );
    }

    public function test_platform_oversight_permissions_are_not_delegable_by_an_owner(): void
    {
        $placeholders = implode(', ', array_fill(0, count($this->platformOversightPermissions()), '?'));

        $delegable = Database::select(
            "SELECT code FROM permissions
              WHERE assignable_by_owner = 1 AND code IN ({$placeholders})",
            $this->platformOversightPermissions(),
        );

        $this->assertSame(
            [],
            $delegable,
            'صلاحيات الإشراف يجب ألّا تكون قابلة للمنح من صاحب المنشأة: '
            . implode('، ', array_column($delegable, 'code')),
        );
    }

    public function test_sensitive_permissions_are_flagged_for_audit(): void
    {
        // الوسم الحسّاس يعني «سجّل كل ممارسة لها»، لا «امنعها عن المنشآت».
        // بعض الصلاحيات الحسّاسة مشروعة داخل المنشأة (عرض الوثائق، التصدير).
        $sensitiveCount = (int) Database::scalar('SELECT COUNT(*) FROM permissions WHERE is_sensitive = 1');

        $this->assertGreaterThan(
            15,
            $sensitiveCount,
            'يجب وسم الصلاحيات المؤثّرة كحسّاسة حتى تُدقَّق ممارستها.',
        );

        foreach (['platform.role.manage', 'org.account.verify', 'finance.application.decide'] as $code) {
            $this->assertSame(
                1,
                (int) Database::scalar('SELECT is_sensitive FROM permissions WHERE code = ?', [$code]),
                "الصلاحية {$code} يجب أن تكون موسومة كحسّاسة.",
            );
        }
    }

    public function test_platform_roles_are_not_attachable_as_organization_roles(): void
    {
        $platformRoles = $this->resolver->roles('platform');

        foreach ($platformRoles as $role) {
            $this->assertNull(
                $role['organization_type_code'],
                'دور بنطاق المنصة يجب ألّا يرتبط بنوع منشأة.',
            );
        }
    }
}
