<?php
require_once __DIR__.'/../bootstrap/db.php';

class Product {
    private $pdo;
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function create($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO products (name, org_id, outlet_id, price, category, meta)
            VALUES (:name, :org_id, :outlet_id, :price, :category, :meta)
        ");
        $stmt->execute([
            ':name'      => $data['name'],
            ':org_id'    => $data['org_id'],
            ':outlet_id' => $data['outlet_id'],
            ':price'     => $data['price'],
            ':category'  => $data['category'] ?? null,
            ':meta'      => $data['meta'] ?? null
        ]);
        return $this->pdo->lastInsertId();
    }

    public function list($org_id) {
        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE org_id=?");
        $stmt->execute([$org_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
