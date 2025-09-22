<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../helpers/barcode.php';   // ✅ include barcode helper
require_once __DIR__.'/../../models/Product.php';
require_once __DIR__.'/../../models/ProductVariant.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

// Decode JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    sendError("Invalid JSON input");
}

// Required fields
$required = ['name', 'price', 'outlet_id'];
foreach ($required as $field) {
    if (!isset($input[$field]) || trim($input[$field]) === '') {
        sendError("Field '$field' is required", 422);
    }
}

// Normalize
$name      = trim($input['name']);
$price     = (float)$input['price'];
$outlet_id = (int)$input['outlet_id'];
$category  = isset($input['category']) ? trim($input['category']) : '';

// Prepare meta (brand, size, etc.)
$metaArr = [];
if (isset($input['meta']) && is_array($input['meta'])) {
    $metaArr = $input['meta'];
}

// Validate price
if ($price <= 0) {
    sendError("Price must be greater than zero", 422);
}

// Validate outlet belongs to org
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$outlet_id, $authUser['org_id']]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$outlet) {
    sendError("Invalid outlet_id or outlet does not belong to your organization", 403);
}

try {
    $pdo->beginTransaction();

    $productModel = new Product($pdo);
    $variantModel = new ProductVariant($pdo);

    // First insert without barcode (because we may need product_id)
    $product_id = $productModel->create([
        'name'      => $name,
        'org_id'    => $authUser['org_id'],
        'outlet_id' => $outlet_id,
        'price'     => $price,
        'category'  => $category,
        'meta'      => json_encode($metaArr, JSON_UNESCAPED_UNICODE)
    ]);

    // Barcode handling
    if (isset($input['barcode']) && trim($input['barcode']) !== '') {
        $barcode = preg_replace('/\s+/', '', $input['barcode']);
    } else {
        $barcode = generate_barcode($authUser['org_id'], $product_id);
    }

    // Update product meta with barcode
    $metaArr['barcode'] = $barcode;
    $stmt = $pdo->prepare("UPDATE products SET meta=? WHERE id=?");
    $stmt->execute([json_encode($metaArr, JSON_UNESCAPED_UNICODE), $product_id]);

    // Create variants if provided
    if (isset($input['variants']) && is_array($input['variants'])) {
        foreach ($input['variants'] as $v) {
            if (!isset($v['name']) || !isset($v['price'])) continue;
            $variantModel->create([
                'product_id' => $product_id,
                'name'       => trim($v['name']),
                'price'      => (float)$v['price']
            ]);
        }
    }

    $pdo->commit();

    sendSuccess([
        'product_id' => $product_id,
        'barcode'    => $barcode
    ], "Product created successfully");
} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Failed to create product: " . $e->getMessage(), 500);
}
