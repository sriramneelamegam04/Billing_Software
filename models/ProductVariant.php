<?php
require_once __DIR__.'/../bootstrap/db.php';

class ProductVariant {

    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /* ============================================
       CREATE VARIANT
    ============================================ */
    public function create(array $data) {

        $stmt = $this->pdo->prepare("
            INSERT INTO product_variants (
                product_id,
                name,
                price,
                gst_rate
            ) VALUES (
                :product_id,
                :name,
                :price,
                :gst_rate
            )
        ");

        $stmt->execute([
            ':product_id' => $data['product_id'],
            ':name'       => trim($data['name']),
            ':price'      => (float)$data['price'],
            ':gst_rate'   => (float)($data['gst_rate'] ?? 0)
        ]);

        return $this->pdo->lastInsertId();
    }

    /* ============================================
       UPDATE VARIANT (ORG SAFE VIA PRODUCT)
    ============================================ */
    public function update($variant_id, $org_id, array $data) {

        $stmt = $this->pdo->prepare("
            UPDATE product_variants pv
            JOIN products p ON pv.product_id = p.id
            SET
                pv.name = :name,
                pv.price = :price,
                pv.gst_rate = :gst_rate
            WHERE pv.id = :id
              AND p.org_id = :org_id
        ");

        return $stmt->execute([
            ':name'     => trim($data['name']),
            ':price'    => (float)$data['price'],
            ':gst_rate' => (float)($data['gst_rate'] ?? 0),
            ':id'       => $variant_id,
            ':org_id'   => $org_id
        ]);
    }

    /* ============================================
       DELETE VARIANT (ORG SAFE)
    ============================================ */
    public function delete($variant_id, $org_id) {

        $stmt = $this->pdo->prepare("
            DELETE pv
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            WHERE pv.id = ? AND p.org_id = ?
        ");

        return $stmt->execute([$variant_id, $org_id]);
    }

    /* ============================================
       LIST VARIANTS BY PRODUCT (ORG SAFE)
    ============================================ */
    public function listByProduct($product_id, $org_id) {

        $stmt = $this->pdo->prepare("
            SELECT pv.*
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            WHERE pv.product_id = ?
              AND p.org_id = ?
            ORDER BY pv.id ASC
        ");

        $stmt->execute([$product_id, $org_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ============================================
       GET SINGLE VARIANT (ORG SAFE)
    ============================================ */
    public function find($variant_id, $org_id) {

        $stmt = $this->pdo->prepare("
            SELECT pv.*
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            WHERE pv.id = ?
              AND p.org_id = ?
            LIMIT 1
        ");

        $stmt->execute([$variant_id, $org_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
