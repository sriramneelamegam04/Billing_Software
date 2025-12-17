<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$product_id = (int)($_GET['product_id'] ?? 0);
$outlet_id  = (int)($_GET['outlet_id'] ?? 0);

if (!$product_id || !$outlet_id) sendError("product_id & outlet_id required");

$stmt = $pdo->prepare("
    SELECT quantity FROM inventory 
    WHERE product_id=? AND outlet_id=? AND org_id=?
");
$stmt->execute([$product_id, $outlet_id, $authUser['org_id']]);
$stock = $stmt->fetchColumn();

sendSuccess([
    "product_id" => $product_id,
    "stock" => (float)$stock
]);
?>
