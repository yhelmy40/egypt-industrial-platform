<?php
/**
 * Model.php
 * النموذج الأساسي | Base model.
 * يوفّر دوال مساعدة للاستعلامات الآمنة عبر PDO + العبارات المُجهّزة.
 * Provides safe query helpers using PDO prepared statements.
 */
abstract class Model
{
    protected PDO $db;
    protected string $table = '';

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /** تنفيذ استعلام وإرجاع كل الصفوف | Run a query and fetch all rows */
    protected function query(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** تنفيذ استعلام وإرجاع صف واحد | Run a query and fetch a single row */
    protected function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** تنفيذ استعلام عام (INSERT/UPDATE/DELETE) | Execute a write statement */
    protected function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /** قيمة عددية مفردة | Fetch a single scalar value */
    protected function scalar(string $sql, array $params = [])
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    // ---- Generic CRUD helpers (based on $this->table) ----

    public function all(string $orderBy = 'id DESC'): array
    {
        return $this->query("SELECT * FROM {$this->table} ORDER BY {$orderBy}");
    }

    public function find(int $id): ?array
    {
        return $this->queryOne("SELECT * FROM {$this->table} WHERE id = ?", [$id]);
    }

    public function delete(int $id): bool
    {
        return $this->execute("DELETE FROM {$this->table} WHERE id = ?", [$id]);
    }

    public function count(string $where = '', array $params = []): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->table}";
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }
        return (int) $this->scalar($sql, $params);
    }

    /** إدراج صف جديد وإرجاع المعرّف | Insert a row, return new id */
    protected function insert(array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            "INSERT INTO {$this->table} (%s) VALUES (%s)",
            implode(', ', $cols),
            implode(', ', $placeholders)
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);
        return (int) $this->db->lastInsertId();
    }

    /** تحديث صف حسب المعرّف | Update a row by id */
    protected function update(int $id, array $data): bool
    {
        $sets = array_map(fn($c) => "{$c} = :{$c}", array_keys($data));
        $sql = sprintf(
            "UPDATE {$this->table} SET %s WHERE id = :__id",
            implode(', ', $sets)
        );
        $data['__id'] = $id;
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($data);
    }
}
