<?php
class Org {
    private $pdo;
    private $table = "orgs";

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function create($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} (name, email, vertical, address, phone) 
            VALUES (:name, :email, :vertical, :address, :phone)
        ");

        $stmt->execute([
            ':name'     => $data['name'],
            ':email'    => $data['email'] ?? null,
            ':vertical' => $data['vertical'] ?? null,
            ':address'  => $data['address'] ?? null,
            ':phone'    => $data['phone'] ?? null
        ]);

        return $this->pdo->lastInsertId();
    }
}
