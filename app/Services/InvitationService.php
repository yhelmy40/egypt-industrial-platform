<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MailerInterface;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Repositories\MembershipRepository;
use App\Repositories\UserRepository;

/**
 * دعوة أعضاء الفريق | Team invitations (§3.4, §3.5).
 *
 * صاحب المنشأة يدعو موظفيه بالبريد. الدعوة رمز مجزَّأ محدود الصلاحية، والدور
 * المُسند مقيَّد بأدوار نطاق المنشأة من نوعها — فلا يمكن لصاحب مشروع أن يدعو
 * أحداً بدور «مدير منصة» مهما عبث بالنموذج.
 * The owner invites staff by email. The invitation is a hashed, expiring token,
 * and the assignable role is restricted to organization-scope roles of the
 * organization's own type — an owner can never mint a platform administrator.
 */
final class InvitationService
{
    private const LIFETIME_DAYS = 7;

    public function __construct(
        private readonly MembershipRepository $memberships = new MembershipRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly PermissionResolver $permissions = new PermissionResolver(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * الأدوار التي يجوز لصاحب المنشأة إسنادها | Roles an owner may assign.
     *
     * @return array<int,array<string,mixed>>
     */
    public function assignableRoles(string $organizationTypeCode): array
    {
        return Database::select(
            "SELECT * FROM roles
              WHERE scope = 'organization'
                AND is_active = 1
                AND organization_type_code = ?
              ORDER BY sort_order ASC",
            [$organizationTypeCode],
        );
    }

    /**
     * إرسال دعوة | Send an invitation.
     *
     * @return array{invitation_id:int,token:string}
     */
    public function invite(
        int $organizationId,
        string $organizationTypeCode,
        string $email,
        string $roleCode,
        int $invitedBy,
        Request $request,
    ): array {
        $email = mb_strtolower(trim($email));

        // الدور يجب أن يكون ضمن أدوار هذا النوع من المنشآت — لا قيمة حرة
        $allowed = array_column($this->assignableRoles($organizationTypeCode), 'code');

        if (!in_array($roleCode, $allowed, true)) {
            throw new HttpException(422, 'الدور المختار غير متاح لهذا النوع من المنشآت.');
        }

        $role = $this->permissions->findRoleByCode($roleCode);

        if ($role === null) {
            throw new HttpException(422, 'الدور المختار غير موجود.');
        }

        // عضو بالفعل؟ | Already a member?
        $existingUser = $this->users->findByEmail($email);

        if ($existingUser !== null
            && $this->memberships->findActiveMembership((int) $existingUser['id'], $organizationId) !== null
        ) {
            throw new HttpException(422, 'هذا الشخص عضو بالفعل في المنشأة.');
        }

        // دعوة معلّقة قائمة؟ | An outstanding invitation?
        $pending = Database::selectOne(
            "SELECT * FROM organization_invitations
              WHERE organization_id = ? AND email = ? AND status = 'pending' AND expires_at > NOW()
              LIMIT 1",
            [$organizationId, $email],
        );

        if ($pending !== null) {
            throw new HttpException(422, 'توجد دعوة معلّقة لهذا البريد بالفعل.');
        }

        $token = bin2hex(random_bytes(32));

        $invitationId = Database::insert(
            'INSERT INTO organization_invitations
                (organization_id, email, role_id, token_hash, invited_by, status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
            [
                $organizationId, $email, (int) $role['id'], hash('sha256', $token),
                $invitedBy, 'pending', self::LIFETIME_DAYS,
            ],
        );

        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'organization.member_invited',
            category: AuditLogger::CATEGORY_RBAC,
            entityType: 'organization_invitation',
            entityId: $invitationId,
            description: 'دعوة عضو جديد بدور: ' . $role['name_ar'] . ' — ' . mask_email($email),
            severity: 'notice',
            userId: $invitedBy,
            organizationId: $organizationId,
        );

        $this->sendInvitationEmail($email, $organizationId, $token);

        return ['invitation_id' => $invitationId, 'token' => $token];
    }

    private function sendInvitationEmail(string $email, int $organizationId, string $token): void
    {
        $organization = Database::selectOne(
            'SELECT legal_name, trading_name FROM organizations WHERE id = ?',
            [$organizationId],
        );

        $name = (string) (($organization['trading_name'] ?? '') ?: ($organization['legal_name'] ?? ''));
        $link = rtrim((string) Config::get('app.url'), '/') . url('/invitations/accept') . '?token=' . $token;

        /** @var MailerInterface $mailer */
        $mailer = Container::getInstance()->make(MailerInterface::class);

        $mailer->send(
            $email,
            $email,
            'دعوة للانضمام إلى ' . $name . ' — ' . Config::get('app.name'),
            '<p>تمت دعوتك للانضمام إلى فريق «' . e($name) . '» على منصة رواد النيل.</p>'
            . '<p><a href="' . e($link) . '">' . e($link) . '</a></p>'
            . '<p>الدعوة صالحة لمدة ' . self::LIFETIME_DAYS . ' أيام.</p>',
            "تمت دعوتك للانضمام إلى فريق {$name}.\n\n{$link}\n\nالدعوة صالحة لمدة "
            . self::LIFETIME_DAYS . ' أيام.',
        );
    }

    /** دعوة عبر الرمز | Look up an invitation by its raw token. */
    public function findByToken(string $token): ?array
    {
        return Database::selectOne(
            "SELECT i.*, o.legal_name, o.trading_name, o.slug, r.name_ar AS role_name
               FROM organization_invitations i
               JOIN organizations o ON o.id = i.organization_id
               JOIN roles r ON r.id = i.role_id
              WHERE i.token_hash = ? AND i.status = 'pending'
              LIMIT 1",
            [hash('sha256', $token)],
        );
    }

    /**
     * قبول الدعوة | Accept an invitation.
     *
     * يُشترط أن يكون البريد المسجَّل به المستخدم هو نفسه المدعو، وإلا لأمكن
     * تمرير رابط دعوة لشخص آخر فينضم بدلاً من المقصود.
     * The accepting user's email must match the invited address; otherwise a
     * forwarded link would let the wrong person join.
     */
    public function accept(string $token, int $userId, Request $request): array
    {
        return Database::transaction(function () use ($token, $userId, $request): array {
            $invitation = $this->findByToken($token);

            if ($invitation === null) {
                throw new HttpException(404, 'الدعوة غير صالحة أو سبق استخدامها.');
            }

            if (strtotime((string) $invitation['expires_at']) < time()) {
                Database::statement(
                    "UPDATE organization_invitations SET status = 'expired' WHERE id = ?",
                    [(int) $invitation['id']],
                );

                throw new HttpException(422, 'انتهت صلاحية الدعوة. اطلب دعوة جديدة.');
            }

            $user = $this->users->find($userId);

            if ($user === null) {
                throw new HttpException(404, 'المستخدم غير موجود.');
            }

            if (mb_strtolower((string) $user['email']) !== mb_strtolower((string) $invitation['email'])) {
                throw new HttpException(
                    403,
                    'هذه الدعوة موجّهة إلى بريد إلكتروني آخر. سجّل الدخول بالبريد المدعو.',
                );
            }

            $organizationId = (int) $invitation['organization_id'];

            if ($this->memberships->findActiveMembership($userId, $organizationId) === null) {
                $this->memberships->addMember(
                    organizationId: $organizationId,
                    userId: $userId,
                    roleId: (int) $invitation['role_id'],
                    status: 'active',
                    invitedBy: (int) $invitation['invited_by'],
                );
            }

            Database::statement(
                "UPDATE organization_invitations
                    SET status = 'accepted', accepted_at = NOW(), accepted_user_id = ?
                  WHERE id = ?",
                [$userId, (int) $invitation['id']],
            );

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'organization.member_joined',
                category: AuditLogger::CATEGORY_RBAC,
                entityType: 'organization',
                entityId: $organizationId,
                description: 'انضمام عضو جديد بدور: ' . $invitation['role_name'],
                severity: 'notice',
                userId: $userId,
                organizationId: $organizationId,
            );

            $this->notifications->notifyOrganizationMembers(
                organizationId: $organizationId,
                type: 'organization.member_joined',
                title: 'انضم عضو جديد للفريق',
                body: (string) $user['name'] . ' انضم بدور ' . $invitation['role_name'] . '.',
                severity: 'info',
                actionUrl: url('/app/team'),
                entityType: 'organization',
                entityId: $organizationId,
            );

            return $invitation;
        });
    }

    public function revoke(int $invitationId, int $organizationId, ?int $actorId, Request $request): void
    {
        $affected = Database::affectingStatement(
            "UPDATE organization_invitations
                SET status = 'revoked'
              WHERE id = ? AND organization_id = ? AND status = 'pending'",
            [$invitationId, $organizationId],
        );

        if ($affected === 0) {
            throw new HttpException(404, 'الدعوة غير موجودة أو لم تعد معلّقة.');
        }

        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'organization.invitation_revoked',
            category: AuditLogger::CATEGORY_RBAC,
            entityType: 'organization_invitation',
            entityId: $invitationId,
            description: 'إلغاء دعوة معلّقة',
            severity: 'notice',
            userId: $actorId,
            organizationId: $organizationId,
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function pendingFor(int $organizationId): array
    {
        return Database::select(
            "SELECT i.*, r.name_ar AS role_name, u.name AS inviter_name
               FROM organization_invitations i
               JOIN roles r ON r.id = i.role_id
               LEFT JOIN users u ON u.id = i.invited_by
              WHERE i.organization_id = ? AND i.status = 'pending'
              ORDER BY i.created_at DESC",
            [$organizationId],
        );
    }
}
