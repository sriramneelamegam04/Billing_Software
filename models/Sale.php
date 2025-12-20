<?php

class Sale {

    public $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /* =====================================================
       CREATE SALE
       - Backward compatible
       - Supports item-wise GST summary
       - Existing logic untouched
    ===================================================== */
    public function create($data) {

        /* ---------- META SAFE HANDLING ---------- */
        $meta = null;
        if (isset($data['meta'])) {
            $meta = is_array($data['meta'])
                ? json_encode($data['meta'], JSON_UNESCAPED_UNICODE)
                : $data['meta'];
        }

        /* ---------- SQL ---------- */
        $stmt = $this->pdo->prepare("
            INSERT INTO sales (
                org_id,
                outlet_id,
                customer_id,

                status,
                taxable_amount,
                total_amount,
                discount,

                cgst,
                sgst,
                igst,
                round_off,

                meta,
                created_at
            ) VALUES (
                :org_id,
                :outlet_id,
                :customer_id,

                :status,
                :taxable_amount,
                :total_amount,
                :discount,

                :cgst,
                :sgst,
                :igst,
                :round_off,

                :meta,
                NOW()
            )
        ");

        $stmt->execute([

            ':org_id'       => $data['org_id'],
            ':outlet_id'    => $data['outlet_id'],

            // 🔥 SAFE CUSTOMER ID
            ':customer_id'  => (!empty($data['customer_id']) && $data['customer_id'] > 0)
                                ? (int)$data['customer_id']
                                : null,

            // 🔥 OPTIONAL / DEFAULT SAFE
            ':status'         => $data['status'] ?? 0,
            ':taxable_amount' => $data['taxable_amount'] ?? 0,
            ':total_amount'   => $data['total_amount'],
            ':discount'       => $data['discount'] ?? 0,

            ':cgst'         => $data['cgst'] ?? 0,
            ':sgst'         => $data['sgst'] ?? 0,
            ':igst'         => $data['igst'] ?? 0,
            ':round_off'    => $data['round_off'] ?? 0,

            ':meta'         => $meta
        ]);

        return $this->pdo->lastInsertId();
    }
}
