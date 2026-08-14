<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Services\InvitationService;
use App\Services\PermissionResolver;
use App\Validation\Validator;

/**
 * فريق المنشأة | Organization team management (§3.4, §3.5).
 *
 * صاحب المنشأة يدعو الأعضاء ويضبط صلاحياتهم الفردية. الصلاحيات القابلة للإسناد
 * محصورة فيما وُسم `assignable_by_owner` — فلا يمكن تمرير صلاحية إشرافية.
 */
final class TeamController extends Controller
{
    public function __construct(
        private readonly MembershipRepository $memberships = new MembershipRepository(),
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly InvitationService $invitations = new InvitationService(),
        private readonly PermissionResolver $permissions = new PermissionResolver(),
    ) {
    }

    public function index(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $organization   = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            throw new HttpException(404, 'المنشأة غير موجودة.');
        }

        return $this->view('sme/team/index', [
            'pageTitle'      => 'فريق المنشأة',
            'organization'   => $organization,
            'organizations'  => $this->memberships->organizationsForUser((int) $this->currentUserId()),
            'members'        => $this->memberships->membersOf($organizationId),
            'overrides'      => $this->memberships->permissionOverridesForOrganization($organizationId),
            'invitations'    => $this->invitations->pendingFor($organizationId),
            'roles'          => $this->invitations->assignableRoles((string) $organization['type_code']),
            'permissions'    => $this->permissions->ownerAssignablePermissions(),
        ], 'app');
    }

    public function invite(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $organization   = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            throw new HttpException(404, 'المنشأة غير موجودة.');
        }

        $validator = Validator::make($request->all())
            ->labels(['email' => 'البريد الإلكتروني', 'role_code' => 'الدور'])
            ->required('email')->email('email')->maxLength('email', 190)
            ->required('role_code');

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/team');
        }

        try {
            $this->invitations->invite(
                organizationId: $organizationId,
                organizationTypeCode: (string) $organization['type_code'],
                email: (string) $request->input('email'),
                roleCode: (string) $request->input('role_code'),
                invitedBy: (int) $this->currentUserId(),
                request: $request,
            );

            $this->flash('success', 'تم إرسال الدعوة. تنتهي صلاحيتها بعد سبعة أيام.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/team');
    }

    public function revokeInvitation(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $invitationId   = $request->routeInt('id');

        if ($invitationId === null) {
            throw new HttpException(404, 'الدعوة غير موجودة.');
        }

        try {
            $this->invitations->revoke($invitationId, $organizationId, $this->currentUserId(), $request);
            $this->flash('success', 'تم إلغاء الدعوة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/team');
    }

    /** ضبط صلاحيات عضو | Set a member's individual permissions. */
    public function updatePermissions(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $memberId       = $request->routeInt('id');

        if ($memberId === null) {
            throw new HttpException(404, 'العضو غير موجود.');
        }

        // العضوية يجب أن تخصّ هذه المنشأة — لا ضبط صلاحيات عضو منشأة أخرى
        $member = null;
        foreach ($this->memberships->membersOf($organizationId) as $row) {
            if ((int) $row['id'] === $memberId) {
                $member = $row;
                break;
            }
        }

        if ($member === null) {
            throw new HttpException(404, 'العضو غير موجود في هذه المنشأة.');
        }

        if ((int) $member['is_primary_contact'] === 1) {
            $this->flash('warning', 'لا يمكن تقييد صلاحيات جهة الاتصال الرئيسية للمنشأة.');

            return $this->redirect('/app/team');
        }

        $this->memberships->setMemberPermissions(
            memberId: $memberId,
            grant: $request->array('grant'),
            deny: $request->array('deny'),
            grantedBy: $this->currentUserId(),
        );

        $this->flash('success', 'تم تحديث صلاحيات العضو.');

        return $this->redirect('/app/team');
    }

    public function removeMember(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $memberId       = $request->routeInt('id');

        if ($memberId === null) {
            throw new HttpException(404, 'العضو غير موجود.');
        }

        $affected = $this->memberships->removeMember($memberId, $organizationId);

        if ($affected === 0) {
            $this->flash('warning', 'تعذّر إزالة العضو. جهة الاتصال الرئيسية لا يمكن إزالتها.');
        } else {
            $this->flash('success', 'تمت إزالة العضو من المنشأة.');
        }

        return $this->redirect('/app/team');
    }

    /** قبول الدعوة | Accept an invitation (invited user must be logged in). */
    public function acceptInvitation(Request $request): Response
    {
        $token = (string) ($request->input('token') ?? '');

        if ($token === '') {
            throw new HttpException(404, 'الدعوة غير صالحة.');
        }

        $userId = $this->currentUserId();

        if ($userId === null) {
            // بعد تسجيل الدخول يعود المستخدم إلى رابط القبول نفسه
            Session::put('_intended_url', '/invitations/accept?token=' . $token);
            $this->flash('info', 'سجّل الدخول بالبريد المدعو لقبول الدعوة.');

            return $this->redirect('/auth/login');
        }

        try {
            $invitation = $this->invitations->accept($token, $userId, $request);

            Session::put('active_organization_id', (int) $invitation['organization_id']);
            $this->flash('success', 'تم انضمامك إلى ' . ($invitation['trading_name'] ?: $invitation['legal_name']) . '.');

            return $this->redirect('/app');
        } catch (HttpException $e) {
            $this->flash('danger', $e->getMessage());

            return $this->redirect('/app');
        }
    }
}
