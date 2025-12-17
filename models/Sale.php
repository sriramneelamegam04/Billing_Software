<?php
class Sale {
    public $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // Create new sale
    public function create($data) {

        $stmt = $this->pdo->prepare("
            INSERT INTO sales (
                org_id,
                outlet_id,
                customer_id,
                total_amount,
                discount,
                cgst,
                sgst,
                igst,
                meta,
                created_at
            )
            VALUES (
                :org_id,
                :outlet_id,
                :customer_id,
                :total_amount,
                :discount,
                :cgst,
                :sgst,
                :igst,
                :meta,
                NOW()
            )
        ");

        $stmt->execute([
            ':org_id'       => $data['org_id'],
            ':outlet_id'    => $data['outlet_id'],
            ':customer_id'  => $data['customer_id'] ?? null,
            ':total_amount' => $data['total_amount'],
            ':discount'     => $data['discount'] ?? 0,
            ':cgst'         => $data['cgst'] ?? 0,
            ':sgst'         => $data['sgst'] ?? 0,
            ':igst'         => $data['igst'] ?? 0,
            ':meta'         => isset($data['meta']) ? json_encode($data['meta']) : null
        ]);

        return $this->pdo->lastInsertId();
    }
}
