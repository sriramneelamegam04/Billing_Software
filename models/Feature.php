<?php
require_once __DIR__.'/../bootstrap/db.php';

class Feature {
    private $pdo;
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function create($data) {
        $stmt = $this->pdo->prepare("INSERT INTO features (name,description) VALUES (?,?)");
        $stmt->execute([$data['name'],$data['description']]);
        return $this->pdo->lastInsertId();
    }

    public function list() {
        $stmt = $this->pdo->query("SELECT * FROM features");
        return $stmt->fetchAll();
    }
}
