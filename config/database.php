<?php
// Load .env
$env = parse_ini_file(__DIR__."/../.env");

$host = $env['DB_HOST'] ?? 'localhost';
$db   = $env['DB_NAME'] ?? 'multi_billing';
$user = $env['DB_USER'] ?? 'root';
$pass = $env['DB_PASS'] ?? '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    return $pdo;  // ✅ Return the PDO object
} catch(PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "DB Connection failed: ".$e->getMessage()
    ]);
    exit;
}
