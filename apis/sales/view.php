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
   INPUT
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
        s.meta,
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
   FETCH SALE ITEMS (BASE)
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
   META (DISCOUNT SNAPSHOT)
------------------------------------------------- */
$meta = json_decode($sale['meta'], true) ?: [];
$itemMetaMap = [];

if (!empty($meta['items_summary'])) {
    foreach ($meta['items_summary'] as $m) {
        $key = $m['product_id'].'_'.$m['variant_id'];
        $itemMetaMap[$key] = $m;
    }
}

/* -------------------------------------------------
   LOYALTY POINTS
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT points_earned
    FROM loyalty_points
    WHERE sale_id = ?
    LIMIT 1
");
$stmt->execute([$sale['id']]);
$loyalty_earned = (float)$stmt->fetchColumn();

/* -------------------------------------------------
   FORMAT ITEMS + DISCOUNT
------------------------------------------------- */
$discount_total = 0;

$formattedItems = array_map(function ($i) use (&$discount_total, $itemMetaMap) {

    $key = $i['product_id'].'_'.$i['variant_id'];
    $meta = $itemMetaMap[$key] ?? [];

    $discount_amount = (float)($meta['discount_amount'] ?? 0);
    $discount_total += $discount_amount * (float)$i['quantity'];

    return [
        "product_id"       => (int)$i['product_id'],
        "variant_id"       => $i['variant_id'] ? (int)$i['variant_id'] : null,
        "product_name"     => $i['product_name'],
        "variant_name"     => $i['variant_name'],
        "quantity"         => (float)$i['quantity'],

        "original_rate"    => (float)($meta['original_rate'] ?? $i['rate']),
        "discount"         => $meta['discount'] ?? null,
        "discount_amount"  => $discount_amount,

        "final_rate"       => (float)$i['rate'],
        "taxable_amount"   => (float)$i['taxable_amount'],

        "gst_rate"         => (float)$i['gst_rate'],
        "cgst"             => (float)$i['cgst'],
        "sgst"             => (float)$i['sgst'],
        "igst"             => (float)$i['igst'],

        "line_total"       => (float)$i['amount']
    ];
}, $items);

/* -------------------------------------------------
   RESPONSE
------------------------------------------------- */
sendSuccess([
    "sale" => [
        "sale_id"        => (int)$sale['id'],
        "invoice_no"     => $sale['invoice_no'],
        "outlet_id"      => (int)$sale['outlet_id'],
        "customer_id"    => (int)$sale['customer_id'],
        "taxable_amount" => (float)$sale['taxable_amount'],
        "discount_total" => round($discount_total, 2),
        "cgst"           => (float)$sale['cgst'],
        "sgst"           => (float)$sale['sgst'],
        "igst"           => (float)$sale['igst'],
        "round_off"      => (float)$sale['round_off'],
        "grand_total"    => (float)$sale['total_amount'],
        "created_at"     => $sale['created_at']
    ],
    "items" => $formattedItems,
    "loyalty" => [
        "points_earned" => $loyalty_earned,
        "basis"         => "1 point per ₹100",
        "sale_id"       => (int)$sale['id']
    ]
], "Sale fetched successfully");
