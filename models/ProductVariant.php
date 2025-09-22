<?php
require_once __DIR__.'/../bootstrap/db.php';

class ProductVariant {
    private $pdo;
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function create($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO product_variants (product_id, name, price)
            VALUES (:product_id, :name, :price)
        ");
        $stmt->execute([
            ':product_id' => $data['product_id'],
            ':name'       => $data['name'],
            ':price'      => $data['price']
        ]);
        return $this->pdo->lastInsertId();
    }

    public function list($product_id) {
        $stmt = $this->pdo->prepare("SELECT * FROM product_variants WHERE product_id = ?");
        $stmt->execute([$product_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM product_variants WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
