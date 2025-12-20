<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../helpers/barcode.php';
require_once __DIR__.'/../../models/Product.php';
require_once __DIR__.'/../../models/ProductVariant.php';
require_once __DIR__.'/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendError("Method Not Allowed", 405);

/* -------------------------------------------------
   AUTH + SUBSCRIPTION
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$org_id = (int)$authUser['org_id'];

$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($org_id)) {
    sendError("Active subscription required", 403);
}

/* -------------------------------------------------
   FILE VALIDATION
------------------------------------------------- */
if (!isset($_FILES['file'])) sendError("CSV file required");

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) sendError("CSV upload failed");

$handle = fopen($file['tmp_name'], "r");
if (!$handle) sendError("Unable to read CSV");

/* -------------------------------------------------
   HEADER + BOM FIX
------------------------------------------------- */
$header = fgetcsv($handle);
if (!$header) sendError("CSV is empty");

$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
$header = array_map(fn($h) => strtolower(trim($h)), $header);
$idx = array_flip($header);

/* -------------------------------------------------
   REQUIRED COLUMNS (NAME BASED)
------------------------------------------------- */
$required = ['name','price','outlet_id','category_name','sub_category_name'];
$missing = array_diff($required, $header);
if (!empty($missing)) {
    sendError("Missing required columns: ".implode(', ', $missing));
}

/* -------------------------------------------------
   MODELS
------------------------------------------------- */
$productModel = new Product($pdo);
$variantModel = new ProductVariant($pdo);

/* -------------------------------------------------
   COUNTERS
------------------------------------------------- */
$createdProducts = 0;
$createdVariants = 0;
$skipped = 0;
$failed  = 0;

/* -------------------------------------------------
   PROCESS CSV
------------------------------------------------- */
while (($row = fgetcsv($handle)) !== false) {

    $name       = trim($row[$idx['name']] ?? '');
    $price      = (float)($row[$idx['price']] ?? 0);
    $outlet_id  = (int)($row[$idx['outlet_id']] ?? 0);
    $catName    = trim($row[$idx['category_name']] ?? '');
    $subCatName = trim($row[$idx['sub_category_name']] ?? '');
    $gst_rate   = isset($idx['gst_rate']) ? (float)$row[$idx['gst_rate']] : 0;

    if ($name === '' || $price <= 0 || $outlet_id <= 0 || $catName === '' || $subCatName === '') {
        $skipped++;
        continue;
    }

    if ($gst_rate < 0 || $gst_rate > 100) {
        $failed++;
        continue;
    }

    try {
        $pdo->beginTransaction();

        /* -------------------------------------------------
           VALIDATE OUTLET
        ------------------------------------------------- */
        $chk = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=?");
        $chk->execute([$outlet_id, $org_id]);
        if (!$chk->fetch()) throw new Exception("Invalid outlet");

        /* -------------------------------------------------
           CATEGORY (GET OR CREATE)
        ------------------------------------------------- */
        $stmt = $pdo->prepare("
            SELECT id FROM categories
            WHERE name=? AND org_id=? AND status=1
        ");
        $stmt->execute([$catName, $org_id]);
        $category_id = $stmt->fetchColumn();

        if (!$category_id) {
            $pdo->prepare("
                INSERT INTO categories (org_id,name,status)
                VALUES (?,?,1)
            ")->execute([$org_id, $catName]);
            $category_id = $pdo->lastInsertId();
        }

        /* -------------------------------------------------
           SUB CATEGORY (GET OR CREATE)
        ------------------------------------------------- */
        $stmt = $pdo->prepare("
            SELECT id FROM sub_categories
            WHERE name=? AND category_id=? AND status=1
        ");
        $stmt->execute([$subCatName, $category_id]);
        $sub_category_id = $stmt->fetchColumn();

        if (!$sub_category_id) {
            $pdo->prepare("
                INSERT INTO sub_categories (category_id,name,status)
                VALUES (?,?,1)
            ")->execute([$category_id, $subCatName]);
            $sub_category_id = $pdo->lastInsertId();
        }

        /* -------------------------------------------------
           META (ARRAY)
        ------------------------------------------------- */
        $meta = [];
        if (isset($idx['brand'])) $meta['brand'] = trim($row[$idx['brand']] ?? '');
        if (isset($idx['size']))  $meta['size']  = trim($row[$idx['size']] ?? '');
        if (isset($idx['barcode']) && trim($row[$idx['barcode']] ?? '') !== '') {
            $meta['barcode'] = trim($row[$idx['barcode']]);
        }

        /* -------------------------------------------------
           CREATE PRODUCT
        ------------------------------------------------- */
        $product_id = $productModel->create([
            'name'            => $name,
            'org_id'          => $org_id,
            'outlet_id'       => $outlet_id,
            'price'           => $price,
            'gst_rate'        => $gst_rate,
            'category_id'     => $category_id,
            'sub_category_id' => $sub_category_id,
            'meta'            => $meta
        ]);

        if (empty($meta['barcode'])) {
            $meta['barcode'] = generate_barcode($org_id, $product_id);
            $pdo->prepare("UPDATE products SET meta=? WHERE id=?")
                ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $product_id]);
        }

        $createdProducts++;

        /* -------------------------------------------------
           VARIANT (OPTIONAL)
        ------------------------------------------------- */
        $variant_id = null;

        if (isset($idx['variant_name']) && trim($row[$idx['variant_name']] ?? '') !== '') {

            $vname  = trim($row[$idx['variant_name']]);
            $vprice = (float)($row[$idx['variant_price']] ?? 0);
            $vgst   = isset($idx['variant_gst_rate'])
                ? (float)$row[$idx['variant_gst_rate']]
                : 0;

            if ($vgst < 0 || $vgst > 100) {
                throw new Exception("Invalid variant gst_rate");
            }

            $variant_id = $variantModel->create([
                'product_id' => $product_id,
                'name'       => $vname,
                'price'      => $vprice,
                'gst_rate'   => $vgst
            ]);

            $createdVariants++;
        }

        /* -------------------------------------------------
           INVENTORY
        ------------------------------------------------- */
        $qty = 0;
        if ($variant_id && isset($idx['variant_qty'])) {
            $qty = (int)$row[$idx['variant_qty']];
        } elseif (!$variant_id && isset($idx['product_qty'])) {
            $qty = (int)$row[$idx['product_qty']];
        }

        $pdo->prepare("
            INSERT INTO inventory (org_id,outlet_id,product_id,variant_id,quantity)
            VALUES (?,?,?,?,?)
        ")->execute([
            $org_id,
            $outlet_id,
            $product_id,
            $variant_id,
            $qty
        ]);

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
    "products_created" => $createdProducts,
    "variants_created" => $createdVariants,
    "skipped"          => $skipped,
    "failed"           => $failed
], "CSV import completed successfully");
