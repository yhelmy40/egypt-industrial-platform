<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Services\VerificationService;
use App\Support\TenantContext;
use Tests\TestCase;

/**
 * اختبارات مسار التوثيق | Verification workflow tests (§17).
 *
 * يغطي: الانتقالات المسموحة والممنوعة، إلزام السبب، حصر القرار بفريق المنصة،
 * سجل القرارات، الإشعارات، وثبات الحالة.
 */
final class VerificationWorkflowTest extends TestCase
{
    private VerificationService $verification;

    private int $organizationId;

    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verification = new VerificationService();

        $this->ownerId        = $this->createUser(['name' => 'صاحب المشروع']);
        $this->organizationId = $this->createOrganization([
            'status'            => 'draft',
            'owner_user_id'     => $this->ownerId,
            'legal_name'        => 'منشأة قيد التوثيق',
            'short_description' => 'وصف مختصر كافٍ للنشاط التجاري للمنشأة.',
            'description'       => 'وصف تفصيلي للمنشأة وأنشطتها.',
            'public_phone'      => '01012345678',
            'sector_id'         => 1,
            'governorate_id'    => 1,
        ]);

        $this->addMember($this->organizationId, $this->ownerId, 'sme_owner');
    }

    private function req(): Request
    {
        return Request::create('POST', '/admin/verifications/1/decide');
    }

    /**
     * استكمال بيانات الملف فقط | Complete the profile data only.
     * يُستخدم لعزل بوابة المستندات عن بوابة نسبة الاكتمال في الاختبارات.
     * Used to isolate the document gate from the completeness gate.
     */
    private function completeProfile(): void
    {
        Database::statement(
            'UPDATE sme_profiles SET contact_person_name = ?, contact_person_phone = ?,
                    formalization_status = ?, company_size = ?, employees_count = ?,
                    establishment_date = ?, financing_needs = ?
              WHERE organization_id = ?',
            ['مسؤول التواصل', '01012345678', 'llc', 'small', 12, '2020-01-01', 'رأس مال عامل', $this->organizationId],
        );

        if (Database::scalar('SELECT COUNT(*) FROM sme_profiles WHERE organization_id = ?', [$this->organizationId]) == 0) {
            Database::statement(
                'INSERT INTO sme_profiles
                    (organization_id, contact_person_name, contact_person_phone,
                     formalization_status, company_size, employees_count,
                     establishment_date, financing_needs)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$this->organizationId, 'مسؤول التواصل', '01012345678', 'llc', 'small', 12, '2020-01-01', 'رأس مال عامل'],
            );
        }

    }

    /** رفع المستندات الإلزامية | Upload every required document. */
    private function uploadRequiredDocuments(): void
    {
        $required = Database::select(
            "SELECT id FROM document_types
              WHERE is_required = 1 AND is_active = 1 AND (applies_to = 'sme' OR applies_to = 'all')",
        );

        foreach ($required as $type) {
            $mediaId = Database::insert(
                'INSERT INTO media
                    (organization_id, uploaded_by, disk_path, original_name, extension,
                     mime_type, size_bytes, checksum, visibility, collection)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $this->organizationId, $this->ownerId, 'documents/test/file.pdf', 'مستند.pdf',
                    'pdf', 'application/pdf', 1024, str_repeat('a', 64), 'private', 'documents',
                ],
            );

            Database::statement(
                'INSERT INTO organization_documents
                    (organization_id, document_type_id, media_id, status, uploaded_by)
                 VALUES (?, ?, ?, ?, ?)',
                [$this->organizationId, (int) $type['id'], $mediaId, 'pending', $this->ownerId],
            );
        }
    }

    private function makeSubmittable(): void
    {
        $this->completeProfile();
        $this->uploadRequiredDocuments();
    }

    // ───────────────────── الانتقالات | Transitions ─────────────────────

    public function test_allowed_transitions_match_the_documented_state_machine(): void
    {
        $expected = [
            'draft'              => ['submit'],
            'submitted'          => ['start_review', 'request_info', 'approve', 'reject'],
            'under_review'       => ['request_info', 'approve', 'reject'],
            'more_info_required' => ['submit'],
            'rejected'           => ['submit'],
            'verified'           => ['suspend'],
            'suspended'          => ['reinstate'],
        ];

        foreach ($expected as $status => $actions) {
            $this->assertSame(
                $actions,
                $this->verification->availableActions($status),
                "الإجراءات المتاحة من الحالة {$status} لا تطابق آلة الحالة الموثّقة.",
            );
        }
    }

    public function test_undocumented_transitions_are_refused(): void
    {
        // لا يمكن اعتماد مسودة مباشرة دون إرسال
        $this->assertFalse($this->verification->can('draft', 'approve'));
        // لا يمكن إيقاف منشأة مرفوضة
        $this->assertFalse($this->verification->can('rejected', 'suspend'));
        // لا يمكن إعادة تفعيل منشأة موثّقة أصلاً
        $this->assertFalse($this->verification->can('verified', 'reinstate'));
        // لا يمكن إعادة إرسال منشأة موثّقة
        $this->assertFalse($this->verification->can('verified', 'submit'));
    }

    public function test_transitioning_from_a_disallowed_state_throws(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(422);

        // المنشأة مسودة — لا يمكن اعتمادها مباشرة
        $this->verification->transition(
            $this->organizationId, 'approve', $this->createUser(), 'ops_officer', $this->req(),
        );
    }

    public function test_submission_is_blocked_until_required_documents_are_uploaded(): void
    {
        // الملف مكتمل بالبيانات، والناقص هو المستندات وحدها
        $this->completeProfile();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/المستندات الإلزامية/u');

        $this->verification->transition(
            $this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req(),
        );
    }

    public function test_submission_succeeds_once_the_profile_and_documents_are_ready(): void
    {
        $this->makeSubmittable();

        $result = $this->verification->transition(
            $this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req(),
        );

        $this->assertSame('draft', $result['from']);
        $this->assertSame('submitted', $result['to']);
        $this->assertDatabaseHas('organizations', ['id' => $this->organizationId, 'status' => 'submitted']);
    }

    public function test_full_happy_path_from_draft_to_verified(): void
    {
        $reviewer = $this->createUser();
        $this->makeSubmittable();

        $this->verification->transition($this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req());
        $this->verification->transition($this->organizationId, 'start_review', $reviewer, 'ops_officer', $this->req());
        $this->verification->transition($this->organizationId, 'approve', $reviewer, 'ops_officer', $this->req());

        $organization = Database::selectOne('SELECT * FROM organizations WHERE id = ?', [$this->organizationId]);

        $this->assertSame('verified', $organization['status']);
        $this->assertNotNull($organization['verified_at']);
        $this->assertSame($reviewer, (int) $organization['verified_by']);
    }

    // ───────────────────── إلزام السبب | Reason enforcement ─────────────────────

    public function test_rejection_without_a_reason_is_refused(): void
    {
        $this->makeSubmittable();
        $this->verification->transition($this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req());

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/يجب توضيح السبب/u');

        $this->verification->transition(
            $this->organizationId, 'reject', $this->createUser(), 'ops_officer', $this->req(),
        );
    }

    public function test_requesting_more_information_without_a_reason_is_refused(): void
    {
        $this->makeSubmittable();
        $this->verification->transition($this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req());

        $this->expectException(HttpException::class);

        $this->verification->transition(
            $this->organizationId, 'request_info', $this->createUser(), 'ops_officer', $this->req(), '   ',
        );
    }

    public function test_rejection_reason_is_stored_and_visible_to_the_organization(): void
    {
        $this->makeSubmittable();
        $this->verification->transition($this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req());

        $this->verification->transition(
            $this->organizationId, 'reject', $this->createUser(), 'ops_officer', $this->req(),
            'صورة السجل التجاري غير واضحة.',
        );

        $organization = Database::selectOne('SELECT * FROM organizations WHERE id = ?', [$this->organizationId]);

        $this->assertSame('rejected', $organization['status']);
        $this->assertSame('صورة السجل التجاري غير واضحة.', $organization['rejection_reason']);
    }

    public function test_internal_notes_are_kept_out_of_the_organization_facing_reason(): void
    {
        $this->makeSubmittable();
        $this->verification->transition($this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req());

        $this->verification->transition(
            $this->organizationId, 'request_info', $this->createUser(), 'ops_officer', $this->req(),
            reason: 'يرجى رفع صورة أوضح من السجل التجاري.',
            internalNote: 'ملاحظة داخلية: يُشتبه في تعديل المستند.',
        );

        $organization = Database::selectOne('SELECT * FROM organizations WHERE id = ?', [$this->organizationId]);

        // الملاحظة الداخلية تُحفظ في سجل القرارات فقط، لا على المنشأة
        $this->assertStringNotContainsString('يُشتبه', (string) $organization['rejection_reason']);

        $decision = Database::selectOne(
            "SELECT * FROM organization_verifications
              WHERE organization_id = ? AND action = 'request_info' ORDER BY id DESC LIMIT 1",
            [$this->organizationId],
        );

        $this->assertStringContainsString('يُشتبه', (string) $decision['internal_note']);
    }

    // ───────────────────── التفويض | Authorization ─────────────────────

    public function test_platform_decisions_are_refused_to_organization_members(): void
    {
        foreach (['approve', 'reject', 'suspend', 'start_review', 'request_info', 'reinstate'] as $action) {
            try {
                $this->verification->assertActorMayPerform($action, false, true);
                $this->fail("الإجراء {$action} كان يجب أن يُرفض لغير فريق المنصة.");
            } catch (AuthorizationException $e) {
                $this->assertSame('org.account.verify', $e->permission());
            }
        }
    }

    public function test_platform_staff_may_take_verification_decisions(): void
    {
        $this->verification->assertActorMayPerform('approve', true, false);
        $this->verification->assertActorMayPerform('reject', true, false);

        $this->addToAssertionCount(2);
    }

    public function test_submission_is_refused_to_a_stranger(): void
    {
        $this->expectException(AuthorizationException::class);

        // ليس عضواً ولا من فريق المنصة
        $this->verification->assertActorMayPerform('submit', false, false);
    }

    public function test_an_sme_owner_cannot_hold_the_verification_permission(): void
    {
        $this->actingAs($this->ownerId, $this->organizationId);

        $this->assertFalse(
            TenantContext::can('org.account.verify'),
            'صاحب المشروع يجب ألّا يملك صلاحية توثيق منشأته.',
        );
    }

    // ───────────────────── الأثر | Side effects ─────────────────────

    public function test_every_transition_is_recorded_in_the_decision_history(): void
    {
        $reviewer = $this->createUser();
        $this->makeSubmittable();

        $this->verification->transition($this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req());
        $this->verification->transition($this->organizationId, 'start_review', $reviewer, 'ops_officer', $this->req());
        $this->verification->transition($this->organizationId, 'approve', $reviewer, 'ops_officer', $this->req());

        $history = $this->verification->history($this->organizationId);

        $this->assertCount(3, $history);
        $this->assertSame(['approve', 'start_review', 'submit'], array_column($history, 'action'));

        // اللقطة وقت القرار محفوظة | Snapshot preserved
        $this->assertNotNull($history[0]['completion_score']);
    }

    public function test_transitions_write_an_audit_entry(): void
    {
        $reviewer = $this->createUser();
        $this->makeSubmittable();

        $this->verification->transition($this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req());
        $this->verification->transition($this->organizationId, 'approve', $reviewer, 'ops_officer', $this->req());

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'organization.approve',
            'entity_id' => $this->organizationId,
            'user_id'   => $reviewer,
        ]);
    }

    public function test_every_active_member_is_notified_of_the_outcome(): void
    {
        $employee = $this->createUser();
        $this->addMember($this->organizationId, $employee, 'sme_employee');

        $this->makeSubmittable();
        $this->verification->transition($this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req());
        $this->verification->transition($this->organizationId, 'approve', $this->createUser(), 'ops_officer', $this->req());

        foreach ([$this->ownerId, $employee] as $userId) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $userId,
                'type'    => 'organization.approve',
            ]);
        }
    }

    public function test_suspension_clears_the_verified_stamp(): void
    {
        $reviewer = $this->createUser();
        $this->makeSubmittable();

        $this->verification->transition($this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req());
        $this->verification->transition($this->organizationId, 'approve', $reviewer, 'ops_officer', $this->req());
        $this->verification->transition(
            $this->organizationId, 'suspend', $reviewer, 'ops_officer', $this->req(), 'مخالفة سياسات المنصة.',
        );

        $organization = Database::selectOne('SELECT * FROM organizations WHERE id = ?', [$this->organizationId]);

        $this->assertSame('suspended', $organization['status']);
        $this->assertNull(
            $organization['verified_at'],
            'منشأة موقوفة يجب ألّا تحمل ختم توثيق ساري، وإلا بدت موثّقة لأي استعلام يعتمد عليه.',
        );
    }

    public function test_reinstatement_restores_verification(): void
    {
        $reviewer = $this->createUser();
        $this->makeSubmittable();

        $this->verification->transition($this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req());
        $this->verification->transition($this->organizationId, 'approve', $reviewer, 'ops_officer', $this->req());
        $this->verification->transition($this->organizationId, 'suspend', $reviewer, 'ops_officer', $this->req(), 'سبب');
        $this->verification->transition($this->organizationId, 'reinstate', $reviewer, 'ops_officer', $this->req());

        $organization = Database::selectOne('SELECT * FROM organizations WHERE id = ?', [$this->organizationId]);

        $this->assertSame('verified', $organization['status']);
        $this->assertNotNull($organization['verified_at']);
        $this->assertNull($organization['suspended_at']);
    }

    public function test_resubmission_after_rejection_clears_the_stale_decision(): void
    {
        $reviewer = $this->createUser();
        $this->makeSubmittable();

        $this->verification->transition($this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req());
        $this->verification->transition(
            $this->organizationId, 'reject', $reviewer, 'ops_officer', $this->req(), 'بيانات ناقصة.',
        );

        $this->verification->transition($this->organizationId, 'submit', $this->ownerId, 'sme_owner', $this->req());

        $organization = Database::selectOne('SELECT * FROM organizations WHERE id = ?', [$this->organizationId]);

        $this->assertSame('submitted', $organization['status']);
        $this->assertNull(
            $organization['rejection_reason'],
            'سبب رفض قديم يجب ألّا يبقى معروضاً على طلب أُعيد إرساله.',
        );
    }
}
