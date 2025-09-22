<?php
// models/Outlet.php
class Outlet {
    private $pdo;
    private $table = "outlets";

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // Create new outlet
    public function create(array $data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} (org_id, name, address, vertical, created_at)
            VALUES (:org_id, :name, :address, :vertical, NOW())
        ");
        $stmt->execute([
            ':org_id'   => $data['org_id'],
            ':name'     => $data['name'],
            ':address'  => $data['address'],
            ':vertical' => $data['vertical']
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    // Get outlets by org_id
    public function getByOrg($org_id) {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE org_id = ?");
        $stmt->execute([$org_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get single outlet by ID
    public function getById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
