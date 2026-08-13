<?php
/** نموذج المصانع | Factory profile model */
class Factory extends Model
{
    protected string $table = 'factories';

    /** قائمة المصانع مع أسماء القطاع والمحافظة | List with sector + governorate names */
    public function allWithRefs(): array
    {
        return $this->query(
            "SELECT f.*, s.name_ar AS sector_name, g.name_ar AS governorate_name
             FROM factories f
             LEFT JOIN sectors s ON s.id = f.sector_id
             LEFT JOIN governorates g ON g.id = f.governorate_id
             ORDER BY f.id DESC"
        );
    }

    public function findWithRefs(int $id): ?array
    {
        return $this->queryOne(
            "SELECT f.*, s.name_ar AS sector_name, g.name_ar AS governorate_name
             FROM factories f
             LEFT JOIN sectors s ON s.id = f.sector_id
             LEFT JOIN governorates g ON g.id = f.governorate_id
             WHERE f.id = ?",
            [$id]
        );
    }

    /** ملف المصنع الخاص بمستخدم | A user's own factory profile */
    public function findByUser(int $userId): ?array
    {
        return $this->queryOne("SELECT * FROM factories WHERE user_id = ? LIMIT 1", [$userId]);
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
