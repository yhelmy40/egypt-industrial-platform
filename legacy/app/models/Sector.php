<?php
/** نموذج القطاعات الصناعية | Sectors lookup model */
class Sector extends Model
{
    protected string $table = 'sectors';

    public function all(string $orderBy = 'name_ar ASC'): array
    {
        return $this->query("SELECT * FROM sectors ORDER BY {$orderBy}");
    }
}
