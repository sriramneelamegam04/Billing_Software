<?php
require_once __DIR__.'/../bootstrap/db.php';

class Product {

    public $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /* ============================================
       CREATE PRODUCT
    ============================================ */
    public function create(array $data) {

        $meta = [];
        if (isset($data['meta']) && is_array($data['meta'])) {
            $meta = $data['meta'];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO products (
                name,
                org_id,
                outlet_id,
                price,
                category_id,
                sub_category_id,
                gst_rate,
                meta
            ) VALUES (
                :name,
                :org_id,
                :outlet_id,
                :price,
                :category_id,
                :sub_category_id,
                :gst_rate,
                :meta
            )
        ");

        $stmt->execute([
            ':name'            => $data['name'],
            ':org_id'          => $data['org_id'],
            ':outlet_id'       => $data['outlet_id'],
            ':price'           => $data['price'],
            ':category_id'     => $data['category_id'] ?? null,
            ':sub_category_id' => $data['sub_category_id'] ?? null,
            ':gst_rate'        => $data['gst_rate'] ?? 0,
            ':meta'            => json_encode($meta, JSON_UNESCAPED_UNICODE)
        ]);

        return $this->pdo->lastInsertId();
    }

    /* ============================================
       SAFE META UPDATE (GENERIC)
       👉 Used for barcode, purchase_price, discount
    ============================================ */
    public function updateMeta($id, $org_id, $outlet_id, array $newMeta) {

        $stmt = $this->pdo->prepare("
            SELECT meta FROM products
            WHERE id=? AND org_id=? AND outlet_id=?
        ");
        $stmt->execute([$id, $org_id, $outlet_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            return false;
        }

        $meta = [];
        if (!empty($existing['meta'])) {
            $decoded = json_decode($existing['meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        // MERGE
        $meta = array_merge($meta, $newMeta);

        $stmt = $this->pdo->prepare("
            UPDATE products
            SET meta = ?
            WHERE id=? AND org_id=? AND outlet_id=?
        ");

        return $stmt->execute([
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            $id,
            $org_id,
            $outlet_id
        ]);
    }

    /* ============================================
       SET / UPDATE PRODUCT BARCODE
    ============================================ */
    public function setBarcode($id, $org_id, $outlet_id, $barcode) {

        return $this->updateMeta($id, $org_id, $outlet_id, [
            'barcode' => $barcode
        ]);
    }

    /* ============================================
       UPDATE PRODUCT (FIELDS + META)
    ============================================ */
    public function update($id, $org_id, $outlet_id, array $data) {

        $stmt = $this->pdo->prepare("
            SELECT meta FROM products
            WHERE id=? AND org_id=? AND outlet_id=?
        ");
        $stmt->execute([$id, $org_id, $outlet_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            return false;
        }

        $meta = [];
        if (!empty($existing['meta'])) {
            $decoded = json_decode($existing['meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        if (isset($data['meta']) && is_array($data['meta'])) {
            $meta = array_merge($meta, $data['meta']);
        }

        $stmt = $this->pdo->prepare("
            UPDATE products
            SET
                name = :name,
                price = :price,
                category_id = :category_id,
                sub_category_id = :sub_category_id,
                gst_rate = :gst_rate,
                meta = :meta
            WHERE id = :id
              AND org_id = :org_id
              AND outlet_id = :outlet_id
        ");

        return $stmt->execute([
            ':name'            => $data['name'],
            ':price'           => $data['price'],
            ':category_id'     => $data['category_id'] ?? null,
            ':sub_category_id' => $data['sub_category_id'] ?? null,
            ':gst_rate'        => $data['gst_rate'] ?? 0,
            ':meta'            => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ':id'              => $id,
            ':org_id'          => $org_id,
            ':outlet_id'       => $outlet_id
        ]);
    }

    /* ============================================
       DELETE PRODUCT
    ============================================ */
    public function delete($id, $org_id, $outlet_id) {

        $stmt = $this->pdo->prepare("
            DELETE FROM products
            WHERE id = ? AND org_id = ? AND outlet_id = ?
        ");

        return $stmt->execute([$id, $org_id, $outlet_id]);
    }

    /* ============================================
       LIST PRODUCTS
    ============================================ */
    public function list($org_id) {

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM products
            WHERE org_id = ?
        ");
        $stmt->execute([$org_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ============================================
       FIND PRODUCT
    ============================================ */
    public function find($id, $org_id, $outlet_id) {

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM products
            WHERE id = ?
              AND org_id = ?
              AND outlet_id = ?
            LIMIT 1
        ");

        $stmt->execute([$id, $org_id, $outlet_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
