<?php
require_once __DIR__ . '/../helpers/response.php';
$pdo = require __DIR__ . '/../config/database.php';

if(!$pdo || !($pdo instanceof PDO)){
    sendError("DB Connection failed", 500);
}
