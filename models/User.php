<?php
class User {
    private $pdo;
    private $table = "users";

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function create(array $data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} 
            (org_id, outlet_id, name, email, password, role) 
            VALUES (:org_id, :outlet_id, :name, :email, :password, :role)
        ");
        $stmt->execute([
            ':org_id'    => $data['org_id'],
            ':outlet_id' => $data['outlet_id'],
            ':name'      => $data['name'],
            ':email'     => $data['email'],
            ':password'  => $data['password'],
            ':role'      => $data['role']
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getByEmail(string $email) {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
