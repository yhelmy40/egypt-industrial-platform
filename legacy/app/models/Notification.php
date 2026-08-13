<?php
/** نموذج الإشعارات الداخلية | Internal notifications model */
class Notification extends Model
{
    protected string $table = 'notifications';

    /** إنشاء إشعار | Create a notification */
    public function push(int $userId, string $title, string $message): int
    {
        return $this->insert([
            'user_id'    => $userId,
            'title'      => $title,
            'message'    => $message,
            'is_read'    => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** إشعار لكل مديري الوزارة | Notify all admins */
    public function pushAdmins(string $title, string $message): void
    {
        $admins = $this->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
        foreach ($admins as $a) {
            $this->push((int) $a['id'], $title, $message);
        }
    }

    public function forUser(int $userId, int $limit = 50): array
    {
        $limit = (int) $limit;
        return $this->query(
            "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT {$limit}",
            [$userId]
        );
    }

    public function unreadCount(int $userId): int
    {
        return $this->count('user_id = ? AND is_read = 0', [$userId]);
    }

    public function markRead(int $id, int $userId): bool
    {
        return $this->execute(
            "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
    }

    public function markAllRead(int $userId): bool
    {
        return $this->execute(
            "UPDATE notifications SET is_read = 1 WHERE user_id = ?",
            [$userId]
        );
    }
}
