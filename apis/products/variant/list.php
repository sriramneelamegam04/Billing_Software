<?php
require_once __DIR__ . '/../../../helpers/response.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../bootstrap/db.php';

header("Content-Type: application/json");

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

// ----------------------
// Validate product_id
// ----------------------
if (!isset($_GET['product_id'])) sendError("product_id is required", 422);

$product_id = (int) $_GET['product_id'];

// ----------------------
// Pagination inputs
// ----------------------
$page  = isset($_GET['page'])  ? max(1, (int)$_GET['page'])  : 1;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;

$offset = ($page - 1) * $limit;

// ----------------------
// Validate product belongs to this org
// ----------------------
$stmt = $pdo->prepare("SELECT id, org_id FROM products WHERE id=? LIMIT 1");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) sendError("Invalid product_id", 404);
if ($product['org_id'] != $authUser['org_id']) sendError("Unauthorized product access", 403);

// ----------------------
// Count total variants
// ----------------------
$stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM product_variants WHERE product_id=?");
$stmt->execute([$product_id]);
$totalRow = $stmt->fetch(PDO::FETCH_ASSOC);

$total = (int)$totalRow['total'];
$total_pages = ceil($total / $limit);

// ----------------------
// Fetch paginated results
// ----------------------
$stmt = $pdo->prepare("
    SELECT id, name, price, created_at
    FROM product_variants
    WHERE product_id=?
    ORDER BY id ASC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $product_id, PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();

$variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ----------------------
// Response format
// ----------------------
sendSuccess([
    "page"        => $page,
    "limit"       => $limit,
    "total"       => $total,
    "total_pages" => $total_pages,
    "data"        => $variants
], "Variants fetched successfully");
?>
