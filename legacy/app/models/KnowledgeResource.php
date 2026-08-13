<?php
/** نموذج مكتبة المعرفة | Knowledge hub resources model */
class KnowledgeResource extends Model
{
    protected string $table = 'knowledge_resources';

    public const CATEGORIES = ['research', 'patent', 'case_study', 'regulation', 'funding', 'guide'];

    public function allWithRefs(array $filters = []): array
    {
        $sql = "SELECT k.*, s.name_ar AS sector_name, u.name AS uploader_name
                FROM knowledge_resources k
                LEFT JOIN sectors s ON s.id = k.sector_id
                LEFT JOIN users u ON u.id = k.uploaded_by";
        $where = [];
        $params = [];
        if (!empty($filters['category'])) {
            $where[] = 'k.category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['sector_id'])) {
            $where[] = 'k.sector_id = ?';
            $params[] = $filters['sector_id'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY k.id DESC';
        return $this->query($sql, $params);
    }

    public function findWithRefs(int $id): ?array
    {
        return $this->queryOne(
            "SELECT k.*, s.name_ar AS sector_name, u.name AS uploader_name
             FROM knowledge_resources k
             LEFT JOIN sectors s ON s.id = k.sector_id
             LEFT JOIN users u ON u.id = k.uploaded_by
             WHERE k.id = ?",
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
}
