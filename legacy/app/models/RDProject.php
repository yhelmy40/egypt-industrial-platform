<?php
/** نموذج مشاريع البحث والتطوير | R&D Projects model */
class RDProject extends Model
{
    protected string $table = 'rd_projects';

    public function allWithRefs(array $filters = []): array
    {
        $sql = "SELECT p.*, c.title AS challenge_title, f.name AS factory_name,
                       rp.name AS researcher_name, s.name_ar AS sector_name
                FROM rd_projects p
                LEFT JOIN challenges c ON c.id = p.challenge_id
                LEFT JOIN factories f ON f.id = p.factory_id
                LEFT JOIN researcher_profiles rp ON rp.id = p.researcher_profile_id
                LEFT JOIN sectors s ON s.id = c.sector_id";
        $where = [];
        $params = [];
        if (!empty($filters['factory_id'])) {
            $where[] = 'p.factory_id = ?';
            $params[] = $filters['factory_id'];
        }
        if (!empty($filters['researcher_profile_id'])) {
            $where[] = 'p.researcher_profile_id = ?';
            $params[] = $filters['researcher_profile_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = $filters['status'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.id DESC';
        return $this->query($sql, $params);
    }

    public function findWithRefs(int $id): ?array
    {
        return $this->queryOne(
            "SELECT p.*, c.title AS challenge_title, f.name AS factory_name,
                    rp.name AS researcher_name, s.name_ar AS sector_name
             FROM rd_projects p
             LEFT JOIN challenges c ON c.id = p.challenge_id
             LEFT JOIN factories f ON f.id = p.factory_id
             LEFT JOIN researcher_profiles rp ON rp.id = p.researcher_profile_id
             LEFT JOIN sectors s ON s.id = c.sector_id
             WHERE p.id = ?",
            [$id]
        );
    }

    public function save(array $data, ?int $id = null): int
    {
        if ($id) {
            $this->update($id, $data);
            return $id;
        }
        return $this->insert($data);
    }

    public function countByStatus(string $status): int
    {
        return $this->count('status = ?', [$status]);
    }

    /** عدد المشاريع حسب القطاع | Projects grouped by sector (for chart) */
    public function countsBySector(): array
    {
        return $this->query(
            "SELECT s.name_ar AS sector, COUNT(p.id) AS c
             FROM rd_projects p
             LEFT JOIN challenges ch ON ch.id = p.challenge_id
             LEFT JOIN sectors s ON s.id = ch.sector_id
             GROUP BY s.name_ar
             HAVING s.name_ar IS NOT NULL
             ORDER BY c DESC"
        );
    }
}
