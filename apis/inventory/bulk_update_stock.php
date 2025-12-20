<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../services/SubscriptionService.php';
require_once __DIR__ . '/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// ✅ Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use POST"]);
    exit;
}


$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);
$org_id = (int)$authUser['org_id'];


/* -------------------------------------------------
   SUBSCRIPTION CHECK
------------------------------------------------- */
$subscriptionModel = new Subscription($pdo);
$activeSub = $subscriptionModel->getActive($authUser['org_id']);

if (!$activeSub) {
    sendError("Active subscription required", 403);
}

// ------------------------------
// Validate file
// ------------------------------
if (!isset($_FILES['file'])) sendError("CSV file required");
$fileInfo = $_FILES['file'];

if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
    sendError("File upload failed");
}

$allowed = ['text/csv','application/csv','application/vnd.ms-excel'];
if (!in_array($fileInfo['type'], $allowed)) {
    sendError("Invalid file type, only CSV allowed");
}

$handle = fopen($fileInfo['tmp_name'], "r");
if (!$handle) sendError("Unable to read CSV file");

// ------------------------------
// Read header
// ------------------------------
$header = fgetcsv($handle);
if (!$header) sendError("Empty CSV");

$header = array_map(fn($h)=> strtolower(preg_replace('/^\xEF\xBB\xBF/', '', $h)), $header);
$idx = array_flip($header);

// Required columns
$required = ['product_id','outlet_id','quantity','mode'];
$missing  = array_diff($required, $header);
if (!empty($missing)) {
    sendError("Missing columns: " . implode(",", $missing));
}

$updated = 0;
$skipped = 0;
$failed  = 0;

// ======================================================
// Process all rows
// ======================================================
while (($row = fgetcsv($handle)) !== false) {

    $product_id = (int)($row[$idx['product_id']] ?? 0);
    $outlet_id  = (int)($row[$idx['outlet_id']] ?? 0);
    $qty        = (float)($row[$idx['quantity']] ?? 0);
    $mode       = strtolower(trim($row[$idx['mode']] ?? ''));

    // NEW: Variant support
    $variant_id = isset($idx['variant_id']) ? (int)($row[$idx['variant_id']] ?? 0) : null;
    if ($variant_id === 0) $variant_id = null;

    $note = isset($idx['note']) ? trim($row[$idx['note']] ?? '') : null;

    // Skip badly formatted row
    if ($product_id <= 0 || $outlet_id <= 0 || $qty <= 0 || $mode === '') {
        $skipped++;
        continue;
    }

    // Validate outlet
    $stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=?");
    $stmt->execute([$outlet_id, $org_id]);
    if (!$stmt->fetch()) {
        $failed++;
        continue;
    }

    // Validate product
    $stmt = $pdo->prepare("
        SELECT id FROM products 
        WHERE id=? AND outlet_id=? AND org_id=?
    ");
    $stmt->execute([$product_id, $outlet_id, $org_id]);
    if (!$stmt->fetch()) {
        $failed++;
        continue;
    }

    // Validate variant (if provided)
    if (!empty($variant_id)) {
        $stmt = $pdo->prepare("
            SELECT v.id 
            FROM product_variants v
            JOIN products p ON p.id = v.product_id
            WHERE v.id=? AND v.product_id=? 
              AND p.org_id=? AND p.outlet_id=?
        ");
        $stmt->execute([$variant_id, $product_id, $org_id, $outlet_id]);
        if (!$stmt->fetch()) {
            $failed++;
            continue;
        }
    }

    // --------------------------------------
    // PROCESS ROW
    // --------------------------------------
    try {
        $pdo->beginTransaction();

        // Check if inventory row exists
        $stmt = $pdo->prepare("
            SELECT quantity FROM inventory
            WHERE org_id=? AND outlet_id=? AND product_id=?
              AND (variant_id = ? OR (variant_id IS NULL AND ? IS NULL))
            LIMIT 1
        ");
        $stmt->execute([$org_id, $outlet_id, $product_id, $variant_id, $variant_id]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            // Create new row
            $stmt = $pdo->prepare("
                INSERT INTO inventory (org_id, outlet_id, product_id, variant_id, quantity)
                VALUES (?,?,?,?,0)
            ");
            $stmt->execute([$org_id, $outlet_id, $product_id, $variant_id]);
            $current = 0;
        }

        // Apply mode
        if ($mode === 'add') {
            $newQty = $current + $qty;
            $change = $qty;
            $type = 'manual_in';

        } elseif ($mode === 'reduce') {
            $newQty = max(0, $current - $qty);
            $change = -$qty;
            $type = 'manual_out';

        } elseif ($mode === 'set') {
            $change = $qty - $current;
            $newQty = $qty;
            $type = 'manual_set';

        } else {
            $skipped++;
            $pdo->rollBack();
            continue;
        }

        // Update inventory
        $stmt = $pdo->prepare("
            UPDATE inventory 
            SET quantity=? 
            WHERE org_id=? AND outlet_id=? AND product_id=?
              AND (variant_id = ? OR (variant_id IS NULL AND ? IS NULL))
        ");
        $stmt->execute([$newQty, $org_id, $outlet_id, $product_id, $variant_id, $variant_id]);

        // Insert log
        $stmt = $pdo->prepare("
            INSERT INTO inventory_logs
                (org_id, outlet_id, product_id, variant_id, change_type, quantity_change, note)
            VALUES (?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $org_id,
            $outlet_id,
            $product_id,
            $variant_id,
            $type,
            $change,
            $note
        ]);

        $pdo->commit();
        $updated++;

    } catch (Exception $e) {
        $pdo->rollBack();
        $failed++;
    }
}

fclose($handle);

sendSuccess([
    "updated" => $updated,
    "skipped" => $skipped,
    "failed"  => $failed
], "Bulk stock update completed");

?>
