<?php
class Subscription {
    private $pdo;
    private $table = "subscriptions";

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // Create PENDING subscription (paid plan only)
    public function createPending(array $data): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table}
            (org_id, plan, allowed_verticals, max_outlets, features, starts_at, expires_at, status, razorpay_order_id)
            VALUES (:org_id, :plan, :allowed_verticals, :max_outlets, :features, :starts_at, :expires_at, :status, :razorpay_order_id)
        ");

        $stmt->execute([
            ':org_id'            => $data['org_id'],
            ':plan'              => $data['plan'],
            ':allowed_verticals' => $data['allowed_verticals'],
            ':max_outlets'       => $data['max_outlets'],
            ':features'          => $data['features'],
            ':starts_at'         => $data['starts_at'],
            ':expires_at'        => $data['expires_at'],
            ':status'            => $data['status'] ?? 'PENDING',
            ':razorpay_order_id' => $data['razorpay_order_id']
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    // Activate subscription after payment success
    public function activateByOrderId(string $razorpay_order_id, array $paymentInfo = []): int|false {
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE razorpay_order_id = ?
            LIMIT 1
        ");
        $stmt->execute([$razorpay_order_id]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) return false;

        $stmt2 = $this->pdo->prepare("
            UPDATE {$this->table}
            SET status = 'ACTIVE',
                razorpay_payment_id = :payid,
                razorpay_signature = :sig
            WHERE id = :id
        ");

        $stmt2->execute([
            ':payid' => $paymentInfo['razorpay_payment_id'] ?? null,
            ':sig'   => $paymentInfo['razorpay_signature'] ?? null,
            ':id'    => $sub['id']
        ]);

        return (int)$sub['id'];
    }

    // Get currently active subscription
    public function getActive(int $org_id): array|false {
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE org_id = ?
              AND status = 'ACTIVE'
              AND (expires_at IS NULL OR expires_at >= NOW())
            ORDER BY COALESCE(expires_at, '9999-12-31') DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$org_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
