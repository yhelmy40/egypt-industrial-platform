<?php
/** نموذج فرص التمويل | Funding opportunities model */
class FundingOpportunity extends Model
{
    protected string $table = 'funding_opportunities';

    public function allWithRefs(): array
    {
        return $this->query(
            "SELECT fo.*, u.name AS owner_name
             FROM funding_opportunities fo
             LEFT JOIN users u ON u.id = fo.user_id
             ORDER BY fo.application_deadline ASC, fo.id DESC"
        );
    }

    public function findWithRefs(int $id): ?array
    {
        return $this->queryOne(
            "SELECT fo.*, u.name AS owner_name
             FROM funding_opportunities fo
             LEFT JOIN users u ON u.id = fo.user_id
             WHERE fo.id = ?",
            [$id]
        );
    }

    public function byUser(int $userId): array
    {
        return $this->query(
            "SELECT * FROM funding_opportunities WHERE user_id = ? ORDER BY id DESC",
            [$userId]
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
