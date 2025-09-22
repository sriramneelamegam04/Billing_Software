<?php
require_once __DIR__.'/../bootstrap/db.php';

class SaleItem {
    public $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function create($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO sale_items 
            (sale_id, product_id, variant_id, quantity, rate, amount) 
            VALUES (?,?,?,?,?,?)
        ");
        $stmt->execute([
            $data['sale_id'],
            $data['product_id'],
            $data['variant_id'],
            $data['quantity'],
            $data['rate'],
            $data['amount']
        ]);
        return $this->pdo->lastInsertId();
    }

    public function listBySale($sale_id) {
        $stmt = $this->pdo->prepare("SELECT * FROM sale_items WHERE sale_id=?");
        $stmt->execute([$sale_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
