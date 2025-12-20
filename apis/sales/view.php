<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

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
   INPUT (QUERY PARAMS)
------------------------------------------------- */
$sale_id    = isset($_GET['id']) ? (int)$_GET['id'] : null;
$invoice_no = isset($_GET['invoice_no']) ? trim($_GET['invoice_no']) : null;

if (!$sale_id && !$invoice_no) {
    sendError("Either sale id or invoice_no is required", 422);
}

/* -------------------------------------------------
   FETCH SALE (ROLE SAFE)
------------------------------------------------- */
$sql = "
    SELECT
        s.id,
        s.invoice_no,
        s.outlet_id,
        s.customer_id,
        s.taxable_amount,
        s.cgst,
        s.sgst,
        s.igst,
        s.round_off,
        s.total_amount,
        s.created_at
    FROM sales s
    INNER JOIN outlets o ON o.id = s.outlet_id
    WHERE o.org_id = ?
";

$params = [$authUser['org_id']];

if ($authUser['role'] !== 'admin') {
    $sql .= " AND s.outlet_id = ?";
    $params[] = $authUser['outlet_id'];
}

if ($sale_id) {
    $sql .= " AND s.id = ?";
    $params[] = $sale_id;
} else {
    $sql .= " AND s.invoice_no = ?";
    $params[] = $invoice_no;
}

$sql .= " LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    sendError("Sale not found", 404);
}

/* -------------------------------------------------
   FETCH SALE ITEMS
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        si.product_id,
        si.variant_id,
        p.name  AS product_name,
        v.name  AS variant_name,
        si.quantity,
        si.rate,
        si.gst_rate,
        si.taxable_amount,
        si.cgst,
        si.sgst,
        si.igst,
        si.amount
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    LEFT JOIN product_variants v ON v.id = si.variant_id
    WHERE si.sale_id = ?
");
$stmt->execute([$sale['id']]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   FORMAT RESPONSE
------------------------------------------------- */
sendSuccess([
    "sale" => [
        "sale_id"        => (int)$sale['id'],
        "invoice_no"     => $sale['invoice_no'],
        "outlet_id"      => (int)$sale['outlet_id'],
        "customer_id"    => (int)$sale['customer_id'],
        "taxable_amount" => (float)$sale['taxable_amount'],
        "cgst"           => (float)$sale['cgst'],
        "sgst"           => (float)$sale['sgst'],
        "igst"           => (float)$sale['igst'],
        "round_off"      => (float)$sale['round_off'],
        "total_amount"   => (float)$sale['total_amount'],
        "created_at"     => $sale['created_at']
    ],
    "items" => array_map(function ($i) {
        return [
            "product_id"     => (int)$i['product_id'],
            "variant_id"     => $i['variant_id'] ? (int)$i['variant_id'] : null,
            "product_name"   => $i['product_name'],
            "variant_name"   => $i['variant_name'],
            "quantity"       => (float)$i['quantity'],
            "rate"           => (float)$i['rate'],
            "gst_rate"       => (float)$i['gst_rate'],
            "taxable_amount" => (float)$i['taxable_amount'],
            "cgst"           => (float)$i['cgst'],
            "sgst"           => (float)$i['sgst'],
            "igst"           => (float)$i['igst'],
            "amount"         => (float)$i['amount']
        ];
    }, $items)
], "Sale fetched successfully");
