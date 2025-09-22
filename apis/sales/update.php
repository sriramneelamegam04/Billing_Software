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
if(!$authUser) sendError("Unauthorized", 401);

// Decode JSON input safely
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if(json_last_error() !== JSON_ERROR_NONE) {
    sendError("Invalid JSON format: " . json_last_error_msg());
}

if(empty($input['sale_id'])) sendError("sale_id is required");
$sale_id = (int)$input['sale_id'];

// Fetch the sale to ensure it exists and belongs to this org
$stmt = $pdo->prepare("SELECT * FROM sales WHERE id=? AND org_id=? LIMIT 1");
$stmt->execute([$sale_id, $authUser['org_id']]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$sale) sendError("Sale not found or does not belong to your organization", 404);

// Optional fields to update
$fields = ['total_amount','discount','note','customer_id','outlet_id','items'];
$updateData = [];
$params = [];

// Normalize and validate input
foreach($fields as $field){
    if(isset($input[$field])){
        $val = $input[$field];

        switch($field){
            case 'total_amount':
                $val = (float)$val;
                if($val < 0) sendError("total_amount cannot be negative");
                break;

            case 'discount':
                $val = (float)$val;
                if($val < 0) sendError("discount cannot be negative");
                break;

            case 'note':
                $val = strtolower(trim($val));
                break;

            case 'customer_id':
            case 'outlet_id':
                $val = (int)$val;
                break;

            case 'items':
                if(!is_array($val) || count($val)==0) sendError("items must be a non-empty array");
                break;
        }

        $updateData[$field] = $val;
    }
}

// Validate outlet if changed
if(isset($updateData['outlet_id'])){
    $stmt = $pdo->prepare("SELECT id FROM outlets WHERE id=? AND org_id=? LIMIT 1");
    $stmt->execute([$updateData['outlet_id'], $authUser['org_id']]);
    if(!$stmt->fetch()) sendError("Invalid outlet_id or does not belong to your organization",403);
} else {
    $updateData['outlet_id'] = $sale['outlet_id'];
}

// Validate items and product IDs if items provided
if(isset($updateData['items'])){
    foreach ($updateData['items'] as &$item) {
        if (!empty($item['barcode']) && empty($item['product_id'])) {
            $barcode = preg_replace('/\s+/', '', strval($item['barcode']));
            $stmt = $pdo->prepare("SELECT id FROM products WHERE org_id=? AND outlet_id=? AND JSON_UNQUOTE(JSON_EXTRACT(meta,'$.barcode'))=? LIMIT 1");
            $stmt->execute([$authUser['org_id'],$updateData['outlet_id'],$barcode]);
            $pid = $stmt->fetchColumn();
            if(!$pid) sendError("Product not found for barcode: $barcode",404);
            $item['product_id'] = (int)$pid;
        }
    }
    unset($item);

    // Ensure all products exist
    $productIds = array_map(fn($i)=> (int)$i['product_id'], $updateData['items']);
    if(count($productIds)==0) sendError("No valid products found in items");

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $paramsCheck = array_merge([$authUser['org_id'],$updateData['outlet_id']], $productIds);
    $stmt = $pdo->prepare("SELECT id FROM products WHERE org_id=? AND outlet_id=? AND id IN ($placeholders)");
    $stmt->execute($paramsCheck);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff($productIds, $existing);
    if(count($missing)>0) sendError("Some products do not exist in this outlet: ".implode(',',$missing));
}

// Normalize GST fields from org
$stmt = $pdo->prepare("SELECT gstin,gst_type,gst_rate FROM orgs WHERE id=? LIMIT 1");
$stmt->execute([$authUser['org_id']]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);
$updateData['gstin'] = $org['gstin'];
$updateData['gst_type'] = $org['gst_type'];
$updateData['gst_rate'] = (float)$org['gst_rate'];

try{
    // Subscription check
    $subService = new SubscriptionService($pdo);
    $subService->checkActive($authUser['org_id']);

    // Before hook
    $vertical = $authUser['vertical'] ?? 'Generic';
    if(method_exists('HookService','callHook')){
        $updateData = HookService::callHook($vertical,'beforeSaleUpdate',$updateData);
    }

    // Update sale
    $set = [];
    $params = [];
    foreach($updateData as $k=>$v){
        if($k!=='items'){ // items can be stored separately via BillingService
            $set[] = "$k=?";
            $params[] = $v;
        }
    }
    $params[] = $sale_id;
    $stmt = $pdo->prepare("UPDATE sales SET ".implode(',',$set)." WHERE id=? AND org_id=?");
    $params[] = $authUser['org_id'];
    $stmt->execute($params);

    // Optional: update sale items if provided
    if(isset($updateData['items'])){
        $billingService = new BillingService($pdo);
        $billingService->updateSaleItems($sale_id, $updateData['items']);
    }

    // After hook
    if(method_exists('HookService','callHook')){
        $updateData['sale_id'] = $sale_id;
        $updateData = HookService::callHook($vertical,'afterSaleUpdate',$updateData);
    }

    sendSuccess(['sale_id'=>$sale_id], "Sale updated successfully");

}catch(Exception $e){
    sendError("Failed to update sale: ".$e->getMessage());
}
