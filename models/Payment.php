<?php
class Payment {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function create($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO payments (sale_id, org_id, outlet_id, amount, payment_mode, meta)
            VALUES (:sale_id, :org_id, :outlet_id, :amount, :payment_mode, :meta)
        ");
        $stmt->execute([
            ':sale_id'      => $data['sale_id'],
            ':org_id'       => $data['org_id'],
            ':outlet_id'    => $data['outlet_id'],
            ':amount'       => $data['amount'],
            ':payment_mode' => $data['payment_mode'],
            ':meta'         => isset($data['meta']) ? json_encode($data['meta']) : null
        ]);

        return $this->pdo->lastInsertId();
    }

    public function listBySale($sale_id) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM payments WHERE sale_id = :sale_id ORDER BY created_at DESC
        ");
        $stmt->execute([':sale_id'=>$sale_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
