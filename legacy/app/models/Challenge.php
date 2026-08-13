<?php
/** نموذج التحديات الصناعية | Industrial challenges model */
class Challenge extends Model
{
    protected string $table = 'challenges';

    public const STATUSES = ['pending', 'open', 'under_review', 'matched', 'in_progress', 'solved', 'rejected'];

    public function allWithRefs(array $filters = []): array
    {
        $sql = "SELECT c.*, s.name_ar AS sector_name, f.name AS factory_name
                FROM challenges c
                LEFT JOIN sectors s ON s.id = c.sector_id
                LEFT JOIN factories f ON f.id = c.factory_id";
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'c.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['sector_id'])) {
            $where[] = 'c.sector_id = ?';
            $params[] = $filters['sector_id'];
        }
        if (!empty($filters['created_by'])) {
            $where[] = 'c.created_by = ?';
            $params[] = $filters['created_by'];
        }
        if (!empty($filters['factory_id'])) {
            $where[] = 'c.factory_id = ?';
            $params[] = $filters['factory_id'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.id DESC';
        return $this->query($sql, $params);
    }

    public function findWithRefs(int $id): ?array
    {
        return $this->queryOne(
            "SELECT c.*, s.name_ar AS sector_name, f.name AS factory_name, u.name AS creator_name
             FROM challenges c
             LEFT JOIN sectors s ON s.id = c.sector_id
             LEFT JOIN factories f ON f.id = c.factory_id
             LEFT JOIN users u ON u.id = c.created_by
             WHERE c.id = ?",
            [$id]
        );
    }

    public function save(array $data, ?int $id = null): int
    {
        if ($id) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->update($id, $data);
            return $id;
        }
        return $this->insert($data);
    }

    public function setStatus(int $id, string $status): bool
    {
        return $this->execute(
            "UPDATE challenges SET status = ?, updated_at = ? WHERE id = ?",
            [$status, date('Y-m-d H:i:s'), $id]
        );
    }

    public function countByStatus(string $status): int
    {
        return $this->count('status = ?', [$status]);
    }

    /** عدد التحديات حسب الأولوية | Counts grouped by priority */
    public function countsByPriority(): array
    {
        $rows = $this->query("SELECT priority, COUNT(*) AS c FROM challenges GROUP BY priority");
        $out = ['low' => 0, 'medium' => 0, 'high' => 0];
        foreach ($rows as $r) {
            $out[$r['priority']] = (int) $r['c'];
        }
        return $out;
    }
}
