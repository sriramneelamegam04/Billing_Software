<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../helpers/barcode.php';
require_once __DIR__.'/../../models/Product.php';
require_once __DIR__.'/../../models/ProductVariant.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/* -------------------------------------------------
   AUTH
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$org_id = (int)$authUser['org_id'];

/* -------------------------------------------------
   FILE VALIDATION
------------------------------------------------- */
if (!isset($_FILES['file'])) sendError("CSV file required");

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) sendError("CSV upload failed");

$handle = fopen($file['tmp_name'], "r");
if (!$handle) sendError("Unable to read CSV");

/* -------------------------------------------------
   READ HEADER + BOM FIX
------------------------------------------------- */
$header = fgetcsv($handle);
if (!$header) sendError("CSV is empty");

// 🔥 UTF-8 BOM STRIP (IMPORTANT FIX)
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

// normalize headers
$header = array_map(fn($h) => strtolower(trim($h)), $header);
$idx = array_flip($header);

// required columns
$required = ['name','price','outlet_id'];
$missing = array_diff($required, $header);
if (!empty($missing)) {
    sendError("Missing required columns: " . implode(', ', $missing));
}

/* -------------------------------------------------
   MODELS
------------------------------------------------- */
$productModel = new Product($pdo);
$variantModel = new ProductVariant($pdo);

/* -------------------------------------------------
   COUNTERS
------------------------------------------------- */
$inserted = 0;
$variants = 0;
$skipped  = 0;
$failed   = 0;

/* -------------------------------------------------
   PROCESS CSV
------------------------------------------------- */
while (($row = fgetcsv($handle)) !== false) {

    $name      = trim($row[$idx['name']] ?? '');
    $price     = (float)($row[$idx['price']] ?? 0);
    $outlet_id = (int)($row[$idx['outlet_id']] ?? 0);

    if ($name === '' || $price <= 0 || $outlet_id <= 0) {
        $skipped++;
        continue;
    }

    // Validate outlet belongs to org
    $chk = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=?");
    $chk->execute([$outlet_id, $org_id]);
    if (!$chk->fetch()) {
        $failed++;
        continue;
    }

    $pdo->beginTransaction();

    try {
        /* -------------------------
           PRODUCT
        ------------------------- */
        $stmt = $pdo->prepare("
            SELECT id FROM products
            WHERE org_id=? AND outlet_id=? AND name=?
            LIMIT 1
        ");
        $stmt->execute([$org_id, $outlet_id, $name]);
        $product_id = $stmt->fetchColumn();

        if (!$product_id) {

            $meta = [];
            if (isset($idx['brand']))   $meta['brand'] = trim($row[$idx['brand']] ?? '');
            if (isset($idx['size']))    $meta['size']  = trim($row[$idx['size']] ?? '');
            if (isset($idx['barcode']) && !empty($row[$idx['barcode']])) {
                $meta['barcode'] = trim($row[$idx['barcode']]);
            }

            $product_id = $productModel->create([
                'name'      => $name,
                'org_id'    => $org_id,
                'outlet_id' => $outlet_id,
                'price'     => $price,
                'category'  => $row[$idx['category']] ?? '',
                'meta'      => json_encode($meta, JSON_UNESCAPED_UNICODE)
            ]);

            // Auto-generate barcode if missing
            if (empty($meta['barcode'])) {
                $meta['barcode'] = generate_barcode($org_id, $product_id);
                $pdo->prepare("UPDATE products SET meta=? WHERE id=?")
                    ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $product_id]);
            }

            $inserted++;
        }

        /* -------------------------
           VARIANT (OPTIONAL)
        ------------------------- */
        $variant_id = null;

        if (isset($idx['variant_name']) && trim($row[$idx['variant_name']] ?? '') !== '') {

            $vname  = trim($row[$idx['variant_name']]);
            $vprice = (float)($row[$idx['variant_price']] ?? 0);

            $stmt = $pdo->prepare("
                SELECT id FROM product_variants
                WHERE product_id=? AND name=?
                LIMIT 1
            ");
            $stmt->execute([$product_id, $vname]);
            $variant_id = $stmt->fetchColumn();

            if (!$variant_id) {
                $variant_id = $variantModel->create([
                    'product_id' => $product_id,
                    'name'       => $vname,
                    'price'      => $vprice
                ]);
                $variants++;
            }
        }

        /* -------------------------
           INVENTORY
        ------------------------- */
        $qty = 0;

        if ($variant_id && isset($idx['variant_qty'])) {
            $qty = (float)($row[$idx['variant_qty']] ?? 0);
        } elseif (!$variant_id && isset($idx['product_qty'])) {
            $qty = (float)($row[$idx['product_qty']] ?? 0);
        }

        $stmt = $pdo->prepare("
            INSERT INTO inventory (org_id, outlet_id, product_id, variant_id, quantity)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ");
        $stmt->execute([$org_id, $outlet_id, $product_id, $variant_id, $qty]);

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $failed++;
    }
}

fclose($handle);

/* -------------------------------------------------
   RESPONSE
------------------------------------------------- */
sendSuccess([
    "products_created" => $inserted,
    "variants_created" => $variants,
    "skipped"          => $skipped,
    "failed"           => $failed
], "CSV import completed successfully");
