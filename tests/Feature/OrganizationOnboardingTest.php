<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Policies\OrganizationPolicy;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Services\OrganizationRegistrationService;
use App\Services\ProfileCompletionService;
use App\Support\TenantContext;
use Tests\TestCase;

/**
 * اختبارات تسجيل المنشآت | Organization onboarding tests (§17).
 */
final class OrganizationOnboardingTest extends TestCase
{
    private OrganizationRegistrationService $registration;

    private ProfileCompletionService $completion;

    private OrganizationRepository $organizations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registration  = new OrganizationRegistrationService();
        $this->completion    = new ProfileCompletionService();
        $this->organizations = new OrganizationRepository();
    }

    private function req(): Request
    {
        return Request::create('POST', '/app/organization');
    }

    /** @return array<string,mixed> */
    private function basicData(array $overrides = []): array
    {
        return array_merge([
            'legal_name'        => 'شركة الأمل للصناعات',
            'trading_name'      => 'الأمل',
            'sector_id'         => 1,
            'governorate_id'    => 1,
            'public_phone'      => '01012345678',
            'short_description' => 'وصف مختصر لنشاط المنشأة التجاري والصناعي.',
        ], $overrides);
    }

    // ───────────────────── الإنشاء | Creation ─────────────────────

    public function test_registration_creates_a_draft_with_an_owner_membership(): void
    {
        $userId = $this->createUser();

        $result = $this->registration->create('sme', $this->basicData(), $userId, $this->req());

        $organization = $this->organizations->find($result['organization_id']);

        $this->assertSame('draft', $organization['status'], 'المنشأة الجديدة تبدأ كمسودة.');
        $this->assertSame($userId, (int) $organization['owner_user_id']);

        // العضوية أُنشئت في نفس المعاملة — منشأة بلا مالك سجل يتيم
        $membership = (new MembershipRepository())->findActiveMembership($userId, $result['organization_id']);
        $this->assertNotNull($membership);
        $this->assertSame('sme_owner', $membership['role_code']);
    }

    public function test_the_owner_role_is_derived_from_the_type_not_from_input(): void
    {
        $userId = $this->createUser();

        $expected = [
            'sme'              => 'sme_owner',
            'bank'             => 'bank_admin',
            'ngo'              => 'ngo_admin',
            'service_provider' => 'provider_admin',
            'bds_center'       => 'bds_manager',
            'government'       => 'government_admin',
        ];

        foreach ($expected as $typeCode => $roleCode) {
            // محاولة حقن دور أوسع عبر النموذج
            $result = $this->registration->create(
                $typeCode,
                $this->basicData(['role_code' => 'super_admin', 'role_id' => 1]),
                $userId,
                $this->req(),
            );

            $membership = (new MembershipRepository())->findActiveMembership($userId, $result['organization_id']);

            $this->assertSame(
                $roleCode,
                $membership['role_code'],
                "دور المالك لنوع {$typeCode} يجب أن يُشتقّ من النوع لا من الطلب.",
            );
        }
    }

    public function test_registering_an_unknown_type_is_refused(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(422);

        $this->registration->create('not_a_type', $this->basicData(), $this->createUser(), $this->req());
    }

    public function test_slugs_are_unique_and_support_arabic(): void
    {
        $userId = $this->createUser();

        $first  = $this->registration->create('sme', $this->basicData(['trading_name' => 'مصنع النور']), $userId, $this->req());
        $second = $this->registration->create('sme', $this->basicData(['trading_name' => 'مصنع النور']), $userId, $this->req());

        $this->assertStringContainsString('مصنع', $first['slug']);
        $this->assertNotSame($first['slug'], $second['slug'], 'يجب ألّا يتكرر معرّف الصفحة العامة.');
    }

    public function test_a_profile_row_is_created_for_the_type(): void
    {
        $userId = $this->createUser();

        $sme = $this->registration->create('sme', $this->basicData(), $userId, $this->req());
        $bds = $this->registration->create('bds_center', $this->basicData(), $userId, $this->req());

        $this->assertDatabaseHas('sme_profiles', ['organization_id' => $sme['organization_id']]);
        $this->assertDatabaseHas('bds_centers', ['organization_id' => $bds['organization_id']]);
    }

    public function test_creation_is_recorded_in_the_audit_log(): void
    {
        $userId = $this->createUser();
        $result = $this->registration->create('sme', $this->basicData(), $userId, $this->req());

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'organization.created',
            'entity_id' => $result['organization_id'],
            'user_id'   => $userId,
        ]);
    }

    // ───────────────────── نسبة الاكتمال | Completion score ─────────────────────

    public function test_completion_score_starts_low_and_lists_missing_items_by_name(): void
    {
        $userId = $this->createUser();
        $result = $this->registration->create('sme', $this->basicData(), $userId, $this->req());

        $evaluation = $this->completion->evaluate($result['organization_id']);

        $this->assertLessThan(100, $evaluation['score']);
        $this->assertNotSame([], $evaluation['missing']);

        // كل بند ناقص يحمل تسمية عربية ووزناً ورابطاً للإصلاح
        foreach ($evaluation['missing'] as $item) {
            $this->assertArrayHasKey('label', $item);
            $this->assertArrayHasKey('weight', $item);
            $this->assertArrayHasKey('url', $item);
            $this->assertNotSame('', $item['label']);
        }
    }

    public function test_completion_score_rises_as_data_is_added(): void
    {
        $userId         = $this->createUser();
        $result         = $this->registration->create('sme', $this->basicData(), $userId, $this->req());
        $organizationId = $result['organization_id'];

        $before = $this->completion->evaluate($organizationId)['score'];

        $this->registration->updateProfile($organizationId, 'sme', [
            'contact_person_name'  => 'مسؤول التواصل',
            'contact_person_phone' => '01012345678',
            'formalization_status' => 'llc',
            'company_size'         => 'small',
            'employees_count'      => 15,
            'establishment_date'   => '2019-01-01',
            'financing_needs'      => 'رأس مال عامل',
        ], $userId, $this->req());

        $after = $this->completion->evaluate($organizationId)['score'];

        $this->assertGreaterThan($before, $after);
    }

    public function test_completion_score_is_persisted_on_the_organization(): void
    {
        $userId = $this->createUser();
        $result = $this->registration->create('sme', $this->basicData(), $userId, $this->req());

        $score  = $this->completion->recalculate($result['organization_id']);
        $stored = (int) Database::scalar(
            'SELECT completion_score FROM organizations WHERE id = ?',
            [$result['organization_id']],
        );

        $this->assertSame($score, $stored);
    }

    public function test_completion_checks_differ_by_organization_type(): void
    {
        $userId = $this->createUser();

        $sme = $this->registration->create('sme', $this->basicData(), $userId, $this->req());
        $bds = $this->registration->create('bds_center', $this->basicData(), $userId, $this->req());

        $smeLabels = array_column($this->completion->evaluate($sme['organization_id'])['missing'], 'label');
        $bdsLabels = array_column($this->completion->evaluate($bds['organization_id'])['missing'], 'label');

        $this->assertContains('الوضع القانوني للمنشأة', $smeLabels);
        $this->assertContains('الخدمات التي يقدّمها المركز', $bdsLabels);
        $this->assertNotContains('الوضع القانوني للمنشأة', $bdsLabels);
    }

    // ───────────────────── التحديث والصلاحيات | Updates and policy ─────────────────────

    public function test_profile_updates_ignore_columns_that_do_not_exist(): void
    {
        $userId = $this->createUser();
        $result = $this->registration->create('sme', $this->basicData(), $userId, $this->req());

        // حقول ملفّقة يجب أن تُتجاهل بصمت بدل أن تُفشل الطلب أو تُكتب في مكان خاطئ
        $this->registration->updateProfile($result['organization_id'], 'sme', [
            'contact_person_name' => 'اسم صحيح',
            'is_super_admin'      => 1,
            'organization_id'     => 999999,
            'id'                  => 12345,
        ], $userId, $this->req());

        $profile = Database::selectOne(
            'SELECT * FROM sme_profiles WHERE organization_id = ?',
            [$result['organization_id']],
        );

        $this->assertSame('اسم صحيح', $profile['contact_person_name']);
        $this->assertSame($result['organization_id'], (int) $profile['organization_id']);
    }

    public function test_a_member_of_another_organization_cannot_update_this_one(): void
    {
        $ownerA = $this->createUser();
        $ownerB = $this->createUser();

        $orgA = $this->registration->create('sme', $this->basicData(), $ownerA, $this->req())['organization_id'];
        $orgB = $this->registration->create('sme', $this->basicData(), $ownerB, $this->req())['organization_id'];

        $this->actingAs($ownerB, $orgB);

        $policy       = new OrganizationPolicy();
        $organizationA = $this->organizations->findWithDetails($orgA);

        $this->assertFalse($policy->canUpdate($organizationA));
        $this->assertFalse($policy->canView($organizationA));
    }

    public function test_a_suspended_organization_cannot_be_edited(): void
    {
        $userId = $this->createUser();
        $orgId  = $this->registration->create('sme', $this->basicData(), $userId, $this->req())['organization_id'];

        Database::statement("UPDATE organizations SET status = 'suspended' WHERE id = ?", [$orgId]);

        $this->actingAs($userId, $orgId);

        $policy = new OrganizationPolicy();
        $this->assertFalse($policy->canUpdate($this->organizations->findWithDetails($orgId)));
    }

    public function test_submission_is_only_offered_in_the_right_states(): void
    {
        $userId = $this->createUser();
        $orgId  = $this->registration->create('sme', $this->basicData(), $userId, $this->req())['organization_id'];

        $this->actingAs($userId, $orgId);
        $policy = new OrganizationPolicy();

        foreach (['draft' => true, 'more_info_required' => true, 'rejected' => true,
                  'submitted' => false, 'under_review' => false, 'verified' => false] as $status => $expected) {
            Database::statement('UPDATE organizations SET status = ? WHERE id = ?', [$status, $orgId]);

            $this->assertSame(
                $expected,
                $policy->canSubmit($this->organizations->findWithDetails($orgId)),
                "توفّر الإرسال في الحالة {$status} غير صحيح.",
            );
        }
    }

    public function test_platform_staff_can_view_any_organization_but_not_edit_it(): void
    {
        $ownerId = $this->createUser();
        $orgId   = $this->registration->create('sme', $this->basicData(), $ownerId, $this->req())['organization_id'];

        $officer = $this->createUser();
        $this->assignPlatformRole($officer, 'ops_officer');
        $this->actingAs($officer);

        $policy       = new OrganizationPolicy();
        $organization = $this->organizations->findWithDetails($orgId);

        $this->assertTrue($policy->canView($organization), 'فريق المراجعة يحتاج الاطلاع لأداء التوثيق.');
        $this->assertTrue($policy->canViewDocuments($organization));
        $this->assertFalse(
            $policy->canUpdate($organization),
            'المراجعة لا تعني تحرير بيانات المنشأة نيابةً عنها.',
        );
    }

    public function test_switching_to_a_new_organization_requires_membership(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();

        $orgB = $this->registration->create('sme', $this->basicData(), $userB, $this->req())['organization_id'];

        // المستخدم أ يحاول تفعيل منشأة ب
        Session::put('user_id', $userA);
        $this->actingAs($userA, $orgB);

        $this->assertNull(TenantContext::organizationId());
    }
}
