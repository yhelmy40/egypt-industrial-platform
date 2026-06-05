<?php
/** نموذج ملفات الباحثين والخبراء | Researcher / Expert profile model */
class ResearcherProfile extends Model
{
    protected string $table = 'researcher_profiles';

    public function allWithRefs(?string $type = null): array
    {
        $sql = "SELECT rp.*, g.name_ar AS governorate_name
                FROM researcher_profiles rp
                LEFT JOIN governorates g ON g.id = rp.governorate_id";
        $params = [];
        if ($type !== null) {
            $sql .= " WHERE rp.profile_type = ?";
            $params[] = $type;
        }
        $sql .= " ORDER BY rp.id DESC";
        return $this->query($sql, $params);
    }

    public function findWithRefs(int $id): ?array
    {
        return $this->queryOne(
            "SELECT rp.*, g.name_ar AS governorate_name
             FROM researcher_profiles rp
             LEFT JOIN governorates g ON g.id = rp.governorate_id
             WHERE rp.id = ?",
            [$id]
        );
    }

    public function findByUser(int $userId): ?array
    {
        return $this->queryOne("SELECT * FROM researcher_profiles WHERE user_id = ? LIMIT 1", [$userId]);
    }

    /** كل الملفات (باحثين + خبراء) للمطابقة | All profiles for matching */
    public function allForMatching(): array
    {
        return $this->query("SELECT * FROM researcher_profiles");
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
