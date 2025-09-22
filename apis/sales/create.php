<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/validation.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../services/BillingService.php';
require_once __DIR__.'/../../services/SubscriptionService.php';
require_once __DIR__.'/../../services/HookService.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized",401);

// Decode JSON safely
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if(json_last_error() !== JSON_ERROR_NONE) {
    sendError("Invalid JSON format: " . json_last_error_msg());
}

// ✅ Required fields
$required = ['outlet_id','total_amount','items','customer_id'];
foreach($required as $field){
    if(!isset($input[$field]) || $input[$field]==='') {
        sendError("$field is required");
    }
}

$outlet_id = (int)$input['outlet_id'];

// ✅ Validate outlet belongs to org
$stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$outlet_id, $authUser['org_id']]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$outlet) sendError("Invalid outlet_id or does not belong to your organization",403);

// ✅ Validate items array
if(!is_array($input['items']) || count($input['items'])==0) {
    sendError("At least one item is required");
}

// 🔹 Resolve barcodes -> product_id
foreach ($input['items'] as &$item) {
    if (!empty($item['barcode']) && empty($item['product_id'])) {
        $barcode = preg_replace('/\s+/', '', strval($item['barcode']));
        $q = "SELECT id FROM products 
              WHERE org_id=? AND outlet_id=? 
              AND JSON_UNQUOTE(JSON_EXTRACT(meta,'$.barcode')) = ? 
              LIMIT 1";
        $stmt = $pdo->prepare($q);
        $stmt->execute([$authUser['org_id'],$outlet_id,$barcode]);
        $pid = $stmt->fetchColumn();
        if(!$pid) {
            sendError("Product not found for barcode: $barcode",404);
        }
        $item['product_id'] = (int)$pid;
    }
}
unset($item);

// ✅ Collect product IDs
$productIds = array_map(fn($i) => (int)$i['product_id'], $input['items']);
if (count($productIds) === 0) {
    sendError("No valid products found in items");
}

// ✅ Check all exist
$placeholders = implode(',', array_fill(0, count($productIds), '?'));
$params = array_merge([$authUser['org_id'], $outlet_id], $productIds);
$sql = "SELECT id FROM products WHERE org_id=? AND outlet_id=? AND id IN ($placeholders)";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$existingProducts = $stmt->fetchAll(PDO::FETCH_COLUMN);

$missingProducts = array_diff($productIds, $existingProducts);
if(count($missingProducts) > 0){
    sendError("Some products do not exist in this outlet: ".implode(',',$missingProducts));
}

// ✅ Normalize note & discount
if(isset($input['note'])) $input['note'] = strtolower(trim($input['note']));
if(!isset($input['discount'])) $input['discount'] = 0;

// ✅ Fetch org GST setup
$stmt = $pdo->prepare("SELECT gstin, gst_type, gst_rate FROM orgs WHERE id=? LIMIT 1");
$stmt->execute([$authUser['org_id']]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if ($org) {
    $input['gstin']    = $org['gstin'];
    $input['gst_type'] = $org['gst_type'];
    $input['gst_rate'] = (float)$org['gst_rate'];
}

try {
    $pdo->beginTransaction();

    // 🔑 Subscription check
    $subService = new SubscriptionService($pdo);
    $subService->checkActive($authUser['org_id']);

    // 🔑 Vertical hook (before create)
    $vertical = $authUser['vertical'] ?? 'Generic';
    if(method_exists('HookService','callHook')){
        $input = HookService::callHook($vertical,'beforeSaleCreate',$input);
    }

    // 🔑 Create sale with status=0 (pending)
    $billingService = new BillingService($pdo);
    $result = $billingService->createSale($authUser['org_id'], array_merge($input, ['status'=>0]));

    // 🔑 Loyalty points earn
    $result['loyalty_points_earned'] = 0.0;
    if(strtolower($vertical) !== 'restaurant') {
        $points = $input['total_amount'] / 100; // ₹100 = 1 point
        if($points > 0){
            $stmt = $pdo->prepare("
                INSERT INTO loyalty_points (org_id, outlet_id, customer_id, sale_id, points_earned, points_redeemed)
                VALUES (?,?,?,?,?,0)
            ");
            $stmt->execute([
                $authUser['org_id'],
                $outlet_id,
                $input['customer_id'],
                $result['sale_id'],
                $points
            ]);
            $result['loyalty_points_earned'] = (float)$points;
        }
    }

    $pdo->commit();

    // 🔑 After hook
    if(method_exists('HookService','callHook')){
        $result = HookService::callHook($vertical,'afterSaleCreate',$result);
    }

    // ✅ Response
    $msg = "Sale created successfully (pending payment)";
    if ($result['loyalty_points_earned'] > 0) {
        $msg .= " | You earned {$result['loyalty_points_earned']} loyalty points";
    }

    sendSuccess($result, $msg);

} catch(Exception $e) {
    $pdo->rollBack();
    sendError("Error: ".$e->getMessage());
}
