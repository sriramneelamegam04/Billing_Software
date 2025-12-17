<?php
require_once __DIR__.'/../bootstrap/db.php';

class Product {

    public $pdo; // <-- IMPORTANT: must be public for other services

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // --------------------------------------------
    // CREATE PRODUCT
    // --------------------------------------------
    public function create($data) {

        // meta JSON safe convert
        $meta = $data['meta'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }

        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);

        $stmt = $this->pdo->prepare("
            INSERT INTO products 
            (name, org_id, outlet_id, price, category, meta)
            VALUES (:name, :org_id, :outlet_id, :price, :category, :meta)
        ");

        $stmt->execute([
            ':name'      => $data['name'],
            ':org_id'    => $data['org_id'],
            ':outlet_id' => $data['outlet_id'],
            ':price'     => $data['price'],
            ':category'  => $data['category'] ?? '',
            ':meta'      => $metaJson
        ]);

        return $this->pdo->lastInsertId();
    }

    // --------------------------------------------
    // UPDATE PRODUCT
    // --------------------------------------------
    public function update($id, $org_id, $outlet_id, $data) {

        $meta = $data['meta'] ?? null;
        if (is_array($meta)) {
            $meta = json_encode($meta, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $this->pdo->prepare("
            UPDATE products
            SET name = :name,
                price = :price,
                category = :category,
                meta = :meta
            WHERE id = :id AND org_id = :org_id AND outlet_id = :outlet_id
        ");

        return $stmt->execute([
            ':name'      => $data['name'],
            ':price'     => $data['price'],
            ':category'  => $data['category'] ?? '',
            ':meta'      => $meta,
            ':id'        => $id,
            ':org_id'    => $org_id,
            ':outlet_id' => $outlet_id
        ]);
    }

    // --------------------------------------------
    // DELETE PRODUCT
    // --------------------------------------------
    public function delete($id, $org_id, $outlet_id) {

        $stmt = $this->pdo->prepare("
            DELETE FROM products 
            WHERE id = ? AND org_id = ? AND outlet_id = ?
        ");

        return $stmt->execute([$id, $org_id, $outlet_id]);
    }

    // --------------------------------------------
    // LIST PRODUCTS
    // --------------------------------------------
    public function list($org_id) {

        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE org_id = ?");
        $stmt->execute([$org_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // --------------------------------------------
    // GET SINGLE PRODUCT
    // --------------------------------------------
    public function find($id, $org_id, $outlet_id) {

        $stmt = $this->pdo->prepare("
            SELECT * FROM products
            WHERE id = ? AND org_id = ? AND outlet_id = ?
            LIMIT 1
        ");

        $stmt->execute([$id, $org_id, $outlet_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
