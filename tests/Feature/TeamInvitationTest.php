<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\MembershipRepository;
use App\Services\InvitationService;
use Tests\TestCase;

/**
 * دعوة أعضاء المنشأة | Team invitations (§3.4, §3.5).
 *
 * الدعوة هي الباب الوحيد لدخول عضو جديد إلى منشأة، فهي نقطة اختراق محتملة:
 * لو قُبلت دعوة ببريد غير المدعو، أو مُنح دور إشرافي عبرها، لانهار العزل كله.
 */
final class TeamInvitationTest extends TestCase
{
    private InvitationService $invitations;

    private MembershipRepository $memberships;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invitations = new InvitationService();
        $this->memberships = new MembershipRepository();
    }

    public function test_the_stored_token_is_a_hash_never_the_token_itself(): void
    {
        [$organizationId, $ownerId] = $this->organizationWithOwner();

        $result = $this->invite($organizationId, $ownerId, 'newcomer@test.local');

        $stored = Database::scalar(
            'SELECT token_hash FROM organization_invitations WHERE id = ?',
            [$result['invitation_id']],
        );

        $this->assertNotSame($result['token'], $stored, 'الرمز الخام يجب ألّا يُخزَّن.');
        $this->assertSame(hash('sha256', $result['token']), $stored);
    }

    /**
     * الأدوار المتاحة محصورة بنوع المنشأة | Roles are limited to the org type.
     *
     * لا يستطيع صاحب منشأة صغيرة أن يدعو أحداً بدور مدير منصة أو مسؤول تشغيل.
     */
    public function test_a_platform_role_cannot_be_granted_through_an_invitation(): void
    {
        [$organizationId, $ownerId] = $this->organizationWithOwner();

        foreach (['super_admin', 'operations_officer', 'content_editor'] as $roleCode) {
            try {
                $this->invitations->invite(
                    organizationId: $organizationId,
                    organizationTypeCode: 'sme',
                    email: 'intruder@test.local',
                    roleCode: $roleCode,
                    invitedBy: $ownerId,
                    request: $this->request('POST', '/app/team/invite'),
                );
                $this->fail("كان يجب رفض الدعوة بدور المنصة: {$roleCode}");
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }

        $this->assertSame(0, $this->countRows('organization_invitations'));
    }

    public function test_a_role_belonging_to_another_organization_type_is_refused(): void
    {
        [$organizationId, $ownerId] = $this->organizationWithOwner();

        $this->expectException(HttpException::class);

        $this->invitations->invite(
            organizationId: $organizationId,
            organizationTypeCode: 'sme',
            email: 'wrong@test.local',
            roleCode: 'bank_officer',
            invitedBy: $ownerId,
            request: $this->request('POST', '/app/team/invite'),
        );
    }

    public function test_a_duplicate_pending_invitation_is_refused(): void
    {
        [$organizationId, $ownerId] = $this->organizationWithOwner();

        $this->invite($organizationId, $ownerId, 'twice@test.local');

        $this->expectException(HttpException::class);
        $this->invite($organizationId, $ownerId, 'twice@test.local');
    }

    public function test_an_existing_member_cannot_be_invited_again(): void
    {
        [$organizationId, $ownerId] = $this->organizationWithOwner();

        $memberId = $this->createUser(['email' => 'member@test.local']);
        $this->addMember($organizationId, $memberId, 'sme_employee');

        $this->expectException(HttpException::class);
        $this->invite($organizationId, $ownerId, 'member@test.local');
    }

    public function test_accepting_an_invitation_creates_an_active_membership(): void
    {
        [$organizationId, $ownerId] = $this->organizationWithOwner();

        $result   = $this->invite($organizationId, $ownerId, 'joiner@test.local');
        $joinerId = $this->createUser(['email' => 'joiner@test.local']);

        $this->invitations->accept(
            $result['token'],
            $joinerId,
            $this->request('GET', '/invitations/accept'),
        );

        $membership = $this->memberships->findActiveMembership($joinerId, $organizationId);

        $this->assertNotNull($membership);
        $this->assertSame('sme_employee', $membership['role_code']);
        $this->assertDatabaseHas('organization_invitations', [
            'id' => $result['invitation_id'], 'status' => 'accepted', 'accepted_user_id' => $joinerId,
        ]);
    }

    /**
     * الدعوة مربوطة بالبريد لا بالرمز وحده | The invitation is bound to the email.
     *
     * لو كفى الرمزُ وحده، لصار تسريبه — عبر سجل خادم أو رسالة مُعاد توجيهها —
     * كافياً لأي شخص للانضمام إلى منشأة ليست له.
     */
    public function test_a_different_user_cannot_accept_someone_elses_invitation(): void
    {
        [$organizationId, $ownerId] = $this->organizationWithOwner();

        $result    = $this->invite($organizationId, $ownerId, 'intended@test.local');
        $intruderId = $this->createUser(['email' => 'intruder@test.local']);

        try {
            $this->invitations->accept(
                $result['token'],
                $intruderId,
                $this->request('GET', '/invitations/accept'),
            );
            $this->fail('كان يجب رفض قبول دعوة موجّهة لبريد آخر.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertNull($this->memberships->findActiveMembership($intruderId, $organizationId));
        $this->assertDatabaseHas('organization_invitations', [
            'id' => $result['invitation_id'], 'status' => 'pending',
        ]);
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void
    {
        [$organizationId, $ownerId] = $this->organizationWithOwner();

        $result   = $this->invite($organizationId, $ownerId, 'late@test.local');
        $joinerId = $this->createUser(['email' => 'late@test.local']);

        Database::statement(
            'UPDATE organization_invitations SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?',
            [$result['invitation_id']],
        );

        try {
            $this->invitations->accept($result['token'], $joinerId, $this->request('GET', '/accept'));
            $this->fail('كان يجب رفض دعوة منتهية.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertNull($this->memberships->findActiveMembership($joinerId, $organizationId));
    }

    public function test_an_unknown_token_reaches_no_invitation(): void
    {
        [$organizationId, $ownerId] = $this->organizationWithOwner();
        $this->invite($organizationId, $ownerId, 'someone@test.local');

        $userId = $this->createUser();

        $this->expectException(HttpException::class);
        $this->invitations->accept(bin2hex(random_bytes(32)), $userId, $this->request('GET', '/accept'));
    }

    public function test_revoking_an_invitation_is_scoped_to_the_organization(): void
    {
        [$organizationId, $ownerId] = $this->organizationWithOwner();
        [$otherOrg, $otherOwner]    = $this->organizationWithOwner();

        $result = $this->invite($organizationId, $ownerId, 'revokable@test.local');

        try {
            $this->invitations->revoke(
                $result['invitation_id'],
                $otherOrg,
                $otherOwner,
                $this->request('POST', '/app/team/invitations/revoke'),
            );
            $this->fail('كان يجب رفض إلغاء دعوة منشأة أخرى.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertDatabaseHas('organization_invitations', [
            'id' => $result['invitation_id'], 'status' => 'pending',
        ]);

        $this->invitations->revoke(
            $result['invitation_id'],
            $organizationId,
            $ownerId,
            $this->request('POST', '/app/team/invitations/revoke'),
        );

        $this->assertDatabaseHas('organization_invitations', [
            'id' => $result['invitation_id'], 'status' => 'revoked',
        ]);
    }

    /**
     * صلاحيات العضو محصورة بما يجوز للمالك إسناده | Owner-assignable only.
     *
     * صاحب المنشأة قد يحاول منح عضوٍ صلاحية إشرافية بتمرير رمزها في النموذج.
     * الفلترة تحدث في المستودع لا في الواجهة، فلا ينفع تعديل الـ HTML.
     */
    public function test_owner_cannot_grant_a_permission_marked_as_not_assignable(): void
    {
        [$organizationId, $ownerId] = $this->organizationWithOwner();

        $memberUserId = $this->createUser();
        $memberId     = $this->addMember($organizationId, $memberUserId, 'sme_employee');

        $forbidden = (string) Database::scalar(
            'SELECT code FROM permissions WHERE assignable_by_owner = 0 LIMIT 1',
        );
        $allowed = (string) Database::scalar(
            'SELECT code FROM permissions WHERE assignable_by_owner = 1 LIMIT 1',
        );

        $this->memberships->setMemberPermissions($memberId, [$forbidden, $allowed], [], $ownerId);

        $granted = array_column(
            Database::select(
                'SELECT p.code FROM organization_member_permissions mp
                   JOIN permissions p ON p.id = mp.permission_id
                  WHERE mp.member_id = ?',
                [$memberId],
            ),
            'code',
        );

        $this->assertContains($allowed, $granted);
        $this->assertNotContains($forbidden, $granted, 'الصلاحية غير القابلة للإسناد يجب أن تُسقَط.');
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @return array{0:int,1:int} */
    private function organizationWithOwner(): array
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $ownerId        = $this->createUser();
        $this->addMember($organizationId, $ownerId, 'sme_owner');

        return [$organizationId, $ownerId];
    }

    /** @return array{invitation_id:int,token:string} */
    private function invite(int $organizationId, int $ownerId, string $email): array
    {
        return $this->invitations->invite(
            organizationId: $organizationId,
            organizationTypeCode: 'sme',
            email: $email,
            roleCode: 'sme_employee',
            invitedBy: $ownerId,
            request: $this->request('POST', '/app/team/invite'),
        );
    }
}
