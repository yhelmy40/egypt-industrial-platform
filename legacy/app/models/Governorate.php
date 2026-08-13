<?php
/** نموذج المحافظات | Governorates lookup model */
class Governorate extends Model
{
    protected string $table = 'governorates';

    public function all(string $orderBy = 'name_ar ASC'): array
    {
        return $this->query("SELECT * FROM governorates ORDER BY {$orderBy}");
    }
}
