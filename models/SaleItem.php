<?php
require_once __DIR__.'/../bootstrap/db.php';

class SaleItem {

    public $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /* =====================================================
       CREATE SALE ITEM
       - Backward compatible
       - Matches existing sale_items table exactly
       - Supports item-wise GST
    ===================================================== */
    public function create($data) {

        /* ---------- META SAFE ---------- */
        $meta = null;
        if (isset($data['meta'])) {
            $meta = is_array($data['meta'])
                ? json_encode($data['meta'], JSON_UNESCAPED_UNICODE)
                : $data['meta'];
        }

        /* ---------- BACKWARD COMPATIBILITY ---------- */
        // amount = final line total
        $amount = $data['amount']
            ?? ($data['taxable_amount'] ?? 0)
            ?? 0;

        $stmt = $this->pdo->prepare("
            INSERT INTO sale_items (
                sale_id,
                product_id,
                variant_id,
                quantity,
                rate,
                amount,

                gst_rate,
                taxable_amount,
                cgst,
                sgst,
                igst,

                meta
            ) VALUES (
                :sale_id,
                :product_id,
                :variant_id,
                :quantity,
                :rate,
                :amount,

                :gst_rate,
                :taxable_amount,
                :cgst,
                :sgst,
                :igst,

                :meta
            )
        ");

        $stmt->execute([
            ':sale_id'        => $data['sale_id'],
            ':product_id'     => $data['product_id'],
            ':variant_id'     => $data['variant_id'] ?? null,
            ':quantity'       => $data['quantity'],
            ':rate'           => $data['rate'],
            ':amount'         => $amount,

            // GST (OPTIONAL)
            ':gst_rate'       => $data['gst_rate'] ?? 0,
            ':taxable_amount' => $data['taxable_amount'] ?? $amount,
            ':cgst'           => $data['cgst'] ?? 0,
            ':sgst'           => $data['sgst'] ?? 0,
            ':igst'           => $data['igst'] ?? 0,

            ':meta'           => $meta
        ]);

        return $this->pdo->lastInsertId();
    }

    /* =====================================================
       LIST ITEMS BY SALE
    ===================================================== */
    public function listBySale($sale_id) {

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM sale_items
            WHERE sale_id = ?
        ");

        $stmt->execute([$sale_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
