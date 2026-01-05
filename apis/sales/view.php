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

/* ================= AUTH + SUBSCRIPTION ================= */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

/* ================= INPUT ================= */
$sale_id    = isset($_GET['id']) ? (int)$_GET['id'] : null;
$invoice_no = isset($_GET['invoice_no']) ? trim($_GET['invoice_no']) : null;

if (!$sale_id && !$invoice_no) {
    sendError("Either sale id or invoice_no is required", 422);
}

/* ================= FETCH SALE ================= */
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
    s.status,
    s.created_at,

    c.name  AS customer_name,
    c.phone AS customer_phone

FROM sales s
JOIN outlets o ON o.id = s.outlet_id
LEFT JOIN customers c ON c.id = s.customer_id
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

if (!$sale) sendError("Sale not found", 404);

/* ================= SALE ITEMS ================= */
$stmt = $pdo->prepare("
    SELECT
        si.product_id,
        si.variant_id,
        p.name AS product_name,
        v.name AS variant_name,
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

/* ================= LATEST ACTIVE PAYMENT ================= */
$stmt = $pdo->prepare("
    SELECT
        p.id AS payment_id,
        p.amount,
        p.payment_mode,
        p.meta
    FROM payments p
    WHERE p.sale_id = ?
      AND p.is_active = 1
    ORDER BY p.id DESC
    LIMIT 1
");
$stmt->execute([$sale['id']]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

$paymentMeta = [];
if ($payment && !empty($payment['meta'])) {
    $paymentMeta = json_decode($payment['meta'], true) ?: [];
}

/* ================= DISCOUNT + LOYALTY ================= */
$manual_discount = (float)($paymentMeta['manual_discount'] ?? 0);
$redeem_points   = (float)($paymentMeta['redeem_points'] ?? 0);
$redeem_value    = (float)($paymentMeta['redeem_value'] ?? 0);

/* ================= FORMAT ITEMS ================= */
$formattedItems = array_map(function ($i) {
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
        "line_total"     => (float)$i['amount']
    ];
}, $items);

/* ================= RESPONSE ================= */
sendSuccess([
    "sale" => [
        "sale_id"        => (int)$sale['id'],
        "invoice_no"     => $sale['invoice_no'],
        "status"         => ((int)$sale['status'] === 1 ? "PAID" : "UNPAID"),

        "customer" => [
            "id"    => (int)$sale['customer_id'],
            "name"  => $sale['customer_name'] ?: "Walk-in Customer",
            "phone" => $sale['customer_phone'] ?: "-"
        ],

        "taxable_amount" => (float)$sale['taxable_amount'],
        "manual_discount"=> round($manual_discount,2),
        "redeem_value"   => round($redeem_value,2),

        "cgst"           => (float)$sale['cgst'],
        "sgst"           => (float)$sale['sgst'],
        "igst"           => (float)$sale['igst'],
        "round_off"      => (float)$sale['round_off'],
        "grand_total"    => (float)$sale['total_amount'],
        "created_at"     => $sale['created_at']
    ],

    "payment" => $payment ? [
        "payment_id"   => (int)$payment['payment_id'],
        "payment_mode" => $payment['payment_mode'],
        "paid_amount"  => (float)$payment['amount']
    ] : null,

    "items" => $formattedItems,

    "loyalty" => [
        "redeemed_points" => $redeem_points,
        "redeem_value"    => $redeem_value
    ]

], "Sale fetched successfully");
