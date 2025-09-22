<?php
class Sale {
    public $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // Create new sale
    public function create($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO sales (org_id, outlet_id, total_amount, discount, cgst, sgst, igst, meta, created_at)
            VALUES (:org_id, :outlet_id, :total_amount, :discount, :cgst, :sgst, :igst, :meta, NOW())
        ");

        $stmt->execute([
            ':org_id'       => $data['org_id'],
            ':outlet_id'    => $data['outlet_id'],
            ':total_amount' => $data['total_amount'],
            ':discount'     => $data['discount'] ?? 0,
            ':cgst'         => $data['cgst'] ?? 0,
            ':sgst'         => $data['sgst'] ?? 0,
            ':igst'         => $data['igst'] ?? 0,
            ':meta'         => isset($data['meta']) ? json_encode($data['meta']) : null
        ]);

        return $this->pdo->lastInsertId();
    }

    // List all sales for an org
    public function list($org_id) {
        $stmt = $this->pdo->prepare("
            SELECT id, org_id, outlet_id, total_amount, discount, cgst, sgst, igst, meta, created_at
            FROM sales
            WHERE org_id = :org_id
            ORDER BY created_at DESC
        ");
        $stmt->execute([':org_id' => $org_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get single sale by id
    public function getById($id) {
        $stmt = $this->pdo->prepare("
            SELECT id, org_id, outlet_id, total_amount, discount, cgst, sgst, igst, meta, created_at
            FROM sales
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
