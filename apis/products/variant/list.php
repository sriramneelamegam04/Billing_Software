<?php
require_once __DIR__ . '/../../../helpers/response.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../bootstrap/db.php';
require_once __DIR__ . '/../../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError("Method Not Allowed. Use GET", 405);
}

/* -------------------------------------------------
   AUTH + SUBSCRIPTION
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

/* -------------------------------------------------
   INPUT
------------------------------------------------- */
if (empty($_GET['product_id'])) {
    sendError("product_id is required", 422);
}

$product_id = (int)$_GET['product_id'];

$page  = isset($_GET['page'])  ? max(1, (int)$_GET['page'])  : 1;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
$offset = ($page - 1) * $limit;

/* -------------------------------------------------
   VALIDATE PRODUCT (ORG SAFE)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id, org_id, outlet_id
    FROM products
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) sendError("Invalid product_id", 404);
if ((int)$product['org_id'] !== (int)$authUser['org_id']) {
    sendError("Unauthorized product access", 403);
}

/* -------------------------------------------------
   COUNT VARIANTS
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM product_variants
    WHERE product_id = ?
");
$stmt->execute([$product_id]);
$total = (int)$stmt->fetchColumn();

$total_pages = $limit > 0 ? ceil($total / $limit) : 0;

/* -------------------------------------------------
   FETCH VARIANTS + INVENTORY
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        v.id   AS variant_id,
        v.name,
        v.price,
        v.gst_rate,
        COALESCE(i.quantity, 0) AS quantity
    FROM product_variants v
    LEFT JOIN inventory i
        ON i.variant_id = v.id
       AND i.product_id = v.product_id
       AND i.org_id = ?
       AND i.outlet_id = ?
    WHERE v.product_id = ?
    ORDER BY v.id ASC
    LIMIT ? OFFSET ?
");

$stmt->bindValue(1, $authUser['org_id'], PDO::PARAM_INT);
$stmt->bindValue(2, $product['outlet_id'], PDO::PARAM_INT);
$stmt->bindValue(3, $product_id, PDO::PARAM_INT);
$stmt->bindValue(4, $limit, PDO::PARAM_INT);
$stmt->bindValue(5, $offset, PDO::PARAM_INT);
$stmt->execute();

$variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   RESPONSE
------------------------------------------------- */
sendSuccess([
    "product_id"   => $product_id,
    "page"         => $page,
    "limit"        => $limit,
    "total"        => $total,
    "total_pages"  => $total_pages,
    "variants"     => array_map(function ($v) {
        return [
            "variant_id" => (int)$v['variant_id'],
            "name"       => $v['name'],
            "price"      => (float)$v['price'],
            "gst_rate"   => (float)$v['gst_rate'],
            "quantity"   => (int)$v['quantity']
        ];
    }, $variants)
], "Variants fetched successfully");
