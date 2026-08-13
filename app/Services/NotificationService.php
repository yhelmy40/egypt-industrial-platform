<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MailerInterface;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * الإشعارات | Notification service (§4.11).
 *
 * الإشعار داخل المنصة هو القناة المضمونة في هذه النسخة، لأن مزوّد البريد غير
 * مُعدّ بعد. تُرسَل نسخة بالبريد عبر المحوّل المُعدّ (سجل حالياً)، لكن نجاح
 * العملية لا يتوقف على البريد — ولا يُدَّعى أن الرسالة سُلِّمت.
 * In-app notifications are the guaranteed channel while no mail provider is
 * configured. An email copy goes through the configured adapter (currently the
 * log driver), but the operation does not depend on it, and delivery is never
 * claimed.
 */
final class NotificationService
{
    /**
     * إشعار مستخدم بعينه | Notify a single user.
     */
    public function notify(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        string $severity = 'info',
        ?string $actionUrl = null,
        ?string $actionLabel = null,
        ?int $organizationId = null,
        ?string $entityType = null,
        ?int $entityId = null,
    ): int {
        return Database::insert(
            'INSERT INTO notifications
                (user_id, organization_id, type, severity, title, body,
                 action_url, action_label, entity_type, entity_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $organizationId,
                mb_substr($type, 0, 60),
                in_array($severity, ['info', 'success', 'warning', 'danger'], true) ? $severity : 'info',
                mb_substr($title, 0, 200),
                $body === null ? null : mb_substr($body, 0, 1000),
                $actionUrl,
                $actionLabel === null ? null : mb_substr($actionLabel, 0, 80),
                $entityType,
                $entityId,
            ],
        );
    }

    /**
     * إشعار كل أعضاء منشأة | Notify every active member of an organization.
     *
     * قرارات التوثيق تهمّ كل من يعمل باسم المنشأة، لا صاحبها وحده.
     */
    public function notifyOrganizationMembers(
        int $organizationId,
        string $type,
        string $title,
        ?string $body = null,
        string $severity = 'info',
        ?string $actionUrl = null,
        ?string $actionLabel = null,
        ?string $entityType = null,
        ?int $entityId = null,
    ): int {
        $members = Database::select(
            "SELECT m.user_id, u.email, u.name
               FROM organization_members m
               JOIN users u ON u.id = m.user_id
              WHERE m.organization_id = ?
                AND m.status = 'active'
                AND u.deleted_at IS NULL
                AND u.status = 'active'",
            [$organizationId],
        );

        $count = 0;

        foreach ($members as $member) {
            $this->notify(
                userId: (int) $member['user_id'],
                type: $type,
                title: $title,
                body: $body,
                severity: $severity,
                actionUrl: $actionUrl,
                actionLabel: $actionLabel,
                organizationId: $organizationId,
                entityType: $entityType,
                entityId: $entityId,
            );

            $this->sendEmailCopy((string) $member['email'], (string) $member['name'], $title, $body);
            $count++;
        }

        return $count;
    }

    /**
     * إشعار فريق المنصة | Notify platform reviewers.
     * يُستخدم عند وصول طلب توثيق جديد إلى الطابور.
     */
    public function notifyPlatformReviewers(
        string $type,
        string $title,
        ?string $body = null,
        string $severity = 'info',
        ?string $actionUrl = null,
        ?int $organizationId = null,
        ?string $entityType = null,
        ?int $entityId = null,
    ): int {
        $reviewers = Database::select(
            "SELECT DISTINCT u.id, u.email, u.name
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.code = 'org.account.verify'
                AND u.status = 'active'
                AND u.deleted_at IS NULL",
        );

        foreach ($reviewers as $reviewer) {
            $this->notify(
                userId: (int) $reviewer['id'],
                type: $type,
                title: $title,
                body: $body,
                severity: $severity,
                actionUrl: $actionUrl,
                actionLabel: 'فتح طابور المراجعة',
                organizationId: $organizationId,
                entityType: $entityType,
                entityId: $entityId,
            );
        }

        return count($reviewers);
    }

    private function sendEmailCopy(string $email, string $name, string $title, ?string $body): void
    {
        try {
            /** @var MailerInterface $mailer */
            $mailer = Container::getInstance()->make(MailerInterface::class);

            $mailer->send(
                $email,
                $name,
                $title . ' — ' . Config::get('app.name'),
                '<p>مرحباً ' . e($name) . '،</p><p>' . e((string) $body) . '</p>',
                $body ?? '',
                ['channel' => 'notification'],
            );
        } catch (Throwable $e) {
            // فشل البريد لا يُفشل الإشعار داخل المنصة
            Logger::warning('Notification email copy failed', ['error' => $e->getMessage()]);
        }
    }

    // ---------------- القراءة | Reading ----------------

    /**
     * إشعارات مستخدم | A user's notifications.
     *
     * مقيّدة بالمستخدم دائماً — لا يمكن قراءة إشعارات غيره بأي معرّف.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forUser(int $userId, int $limit = 20, bool $unreadOnly = false): array
    {
        $limit = min(100, max(1, $limit));
        $where = $unreadOnly ? ' AND read_at IS NULL' : '';

        return Database::select(
            "SELECT * FROM notifications
              WHERE user_id = ?{$where}
              ORDER BY created_at DESC, id DESC
              LIMIT {$limit}",
            [$userId],
        );
    }

    public function unreadCount(int $userId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL',
            [$userId],
        );
    }

    /** تعليم إشعار كمقروء | Mark one notification read (owner only). */
    public function markRead(int $notificationId, int $userId): int
    {
        return Database::affectingStatement(
            'UPDATE notifications SET read_at = NOW()
              WHERE id = ? AND user_id = ? AND read_at IS NULL',
            [$notificationId, $userId],
        );
    }

    public function markAllRead(int $userId): int
    {
        return Database::affectingStatement(
            'UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL',
            [$userId],
        );
    }
}
