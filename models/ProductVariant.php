<?php
require_once __DIR__.'/../bootstrap/db.php';

class ProductVariant {

    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /* ============================================
       CREATE VARIANT (META SUPPORT)
    ============================================ */
    public function create(array $data) {

        $meta = null;
        if (isset($data['meta']) && is_array($data['meta'])) {
            $meta = json_encode($data['meta'], JSON_UNESCAPED_UNICODE);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO product_variants (
                product_id,
                name,
                price,
                gst_rate,
                meta
            ) VALUES (
                :product_id,
                :name,
                :price,
                :gst_rate,
                :meta
            )
        ");

        $stmt->execute([
            ':product_id' => (int)$data['product_id'],
            ':name'       => trim($data['name']),
            ':price'      => (float)$data['price'],
            ':gst_rate'   => (float)($data['gst_rate'] ?? 0),
            ':meta'       => $meta
        ]);

        return $this->pdo->lastInsertId();
    }

    /* ============================================
       SAFE META UPDATE (ORG SAFE)
    ============================================ */
    public function updateMeta($variant_id, $org_id, array $newMeta) {

        $stmt = $this->pdo->prepare("
            SELECT pv.meta
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            WHERE pv.id=? AND p.org_id=?
        ");
        $stmt->execute([$variant_id, $org_id]);
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

        // MERGE META
        $meta = array_merge($meta, $newMeta);

        $stmt = $this->pdo->prepare("
            UPDATE product_variants pv
            JOIN products p ON pv.product_id = p.id
            SET pv.meta = ?
            WHERE pv.id=? AND p.org_id=?
        ");

        return $stmt->execute([
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            $variant_id,
            $org_id
        ]);
    }

    /* ============================================
       SET / UPDATE VARIANT BARCODE
    ============================================ */
    public function setBarcode($variant_id, $org_id, $barcode) {

        return $this->updateMeta($variant_id, $org_id, [
            'barcode' => $barcode
        ]);
    }

    /* ============================================
       UPDATE VARIANT (FIELDS + SAFE META)
    ============================================ */
    public function update($variant_id, $org_id, array $data) {

        $stmt = $this->pdo->prepare("
            SELECT pv.meta
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            WHERE pv.id=? AND p.org_id=?
        ");
        $stmt->execute([$variant_id, $org_id]);
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
            UPDATE product_variants pv
            JOIN products p ON pv.product_id = p.id
            SET
                pv.name = :name,
                pv.price = :price,
                pv.gst_rate = :gst_rate,
                pv.meta = :meta
            WHERE pv.id = :id
              AND p.org_id = :org_id
        ");

        return $stmt->execute([
            ':name'     => trim($data['name']),
            ':price'    => (float)$data['price'],
            ':gst_rate' => (float)($data['gst_rate'] ?? 0),
            ':meta'     => json_encode($meta, JSON_UNESCAPED_UNICODE),
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
       LIST VARIANTS BY PRODUCT
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
       FIND SINGLE VARIANT
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
