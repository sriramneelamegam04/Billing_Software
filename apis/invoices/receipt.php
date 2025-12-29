<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

require_once __DIR__ . '/../../vendor/autoload.php';
use Dompdf\Dompdf;

require_once __DIR__ . '/../../helpers/barcode_image.php';

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Method Not Allowed",405);
}

/* ================= AUTH ================= */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized",401);

$subscription = new Subscription($pdo);
if (!$subscription->getActive($authUser['org_id'])) {
    sendError("Active subscription required",403);
}

/* ================= INPUT ================= */
$input = json_decode(file_get_contents('php://input'), true);
$sale_id   = (int)($input['sale_id'] ?? 0);
$outlet_id = (int)($input['outlet_id'] ?? 0);
if (!$sale_id || !$outlet_id) {
    sendError("sale_id & outlet_id required");
}

/* ================= SALE ================= */
$stmt = $pdo->prepare("
    SELECT 
        s.*,
        o.name AS outlet_name,
        org.name AS org_name,
        org.gstin,
        c.id AS customer_id,
        c.name AS customer_name,
        c.phone AS customer_phone
    FROM sales s
    JOIN outlets o ON o.id=s.outlet_id
    JOIN orgs org ON org.id=s.org_id
    LEFT JOIN customers c ON c.id=s.customer_id
    WHERE s.id=? AND s.org_id=? AND s.outlet_id=?
");
$stmt->execute([$sale_id,$authUser['org_id'],$outlet_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sale) sendError("Sale not found",404);

/* ================= PAYMENT ================= */
$stmt = $pdo->prepare("
    SELECT * FROM payments
    WHERE sale_id=? AND org_id=?
    ORDER BY id DESC LIMIT 1
");
$stmt->execute([$sale_id,$authUser['org_id']]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$payment) sendError("Payment not found",404);

$meta = json_decode($payment['meta'], true) ?? [];
// ✅ Redeemed points/value for THIS SALE (from payment meta)
$sale_redeem_points = (float)($meta['redeem_points'] ?? 0);
$sale_redeem_value  = (float)($meta['redeem_value'] ?? 0);


/* ================= ITEMS ================= */
$stmt = $pdo->prepare("
    SELECT 
    si.quantity,
    si.rate AS final_rate,
    si.amount,
    si.taxable_amount,
    si.gst_rate,
    si.cgst, si.sgst, si.igst,
    p.name AS product_name,
    JSON_UNQUOTE(JSON_EXTRACT(p.meta,'$.discount.type')) AS discount_type,
    JSON_UNQUOTE(JSON_EXTRACT(p.meta,'$.discount.value')) AS discount_value,
    si.rate + 
    IF(
        JSON_EXTRACT(p.meta,'$.discount.type')='percentage',
        (si.rate * JSON_EXTRACT(p.meta,'$.discount.value') / 100),
        IF(
            JSON_EXTRACT(p.meta,'$.discount.type')='flat',
            JSON_EXTRACT(p.meta,'$.discount.value'),
            0
        )
    ) AS original_rate
FROM sale_items si
JOIN products p ON p.id=si.product_id
WHERE si.sale_id=?

");
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= LOYALTY ================= */
$stmt = $pdo->prepare("
    SELECT 
      SUM(points_earned) earned,
      SUM(points_redeemed) redeemed
    FROM loyalty_points
    WHERE org_id=? AND customer_id=?
");
$stmt->execute([$sale['org_id'],$sale['customer_id']]);
$lp = $stmt->fetch(PDO::FETCH_ASSOC);
$points_earned   = (float)($lp['earned'] ?? 0);
$points_balance  = $points_earned - $points_redeemed;

/* ================= THIS SALE LOYALTY ================= */

// Earned points for THIS SALE only
$stmt = $pdo->prepare("
    SELECT points_earned
    FROM loyalty_points
    WHERE sale_id=? AND org_id=? AND customer_id=?
    LIMIT 1
");
$stmt->execute([
    $sale_id,
    $sale['org_id'],
    $sale['customer_id']
]);

$sale_earned_points = (float)($stmt->fetchColumn() ?? 0);


$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(points_redeemed),0)
    FROM loyalty_points
    WHERE sale_id = ?
      AND org_id = ?
      AND customer_id = ?
");
$stmt->execute([
    $sale_id,
    $sale['org_id'],
    $sale['customer_id']
]);

$sale_redeem_points = (float)$stmt->fetchColumn();





/* ================= TOTALS ================= */
$total_mrp = 0;
$total_discount = 0;

foreach ($items as $i) {
    $total_mrp += $i['original_rate'] * $i['quantity'];
    $total_discount += ($i['original_rate'] - $i['final_rate']) * $i['quantity'];
}

/* ================= BARCODE ================= */
$invoice = "INV".str_pad($sale_id,6,'0',STR_PAD_LEFT);
$barcode = barcodeDataUri($invoice,60,2);

/* ================= LOGO ================= */
function imageToBase64($path) {
    if (!file_exists($path)) return null;
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    return 'data:image/'.$ext.';base64,'.base64_encode(file_get_contents($path));
}
$logo = imageToBase64(__DIR__.'/../../assets/logo.png'); // 🔴 adjust if needed

/* ================= HTML ================= */
$html = "
<html><head>
<style>
body{font-family:DejaVu Sans;font-size:11px}
.center{text-align:center}
.left{text-align:left}
.right{text-align:right}
.line{border-top:1px dashed #000;margin:6px 0}
table{width:100%;border-collapse:collapse}
td{padding:2px}
.small{font-size:10px}
</style>
</head>
<body>";

/* ---------- LOGO ---------- */
if ($logo) {
    $html .= "<div class='center'>
        <img src='{$logo}' style='max-width:120px'>
    </div>";
}
/* ---------- HEADER ---------- */
$html .= "
<div class='center'>
<b>{$sale['org_name']}</b><br>
{$sale['outlet_name']}<br>
GSTIN: {$sale['gstin']}<br>
Bill No: {$invoice}<br>
{$sale['created_at']}
</div>

<div class='line'></div>";
$html .= "
<table>
<tr>
<td class='left'>Item</td>
<td>Qty</td>
<td class='right'>Rate</td>
</tr>";
foreach ($items as $i) {

    $disc = "-";
    if (!empty($i['discount_type']) && $i['discount_value'] > 0) {
        $disc = ($i['discount_type']==='percentage')
            ? $i['discount_value']."%"
            : "₹".number_format($i['discount_value'],2);

            $gstText = '';

if ($i['igst'] > 0) {
    $gstText = "GST {$i['gst_rate']}% (IGST {$i['gst_rate']}%)";
} else {
    $half = $i['gst_rate'] / 2;
    $gstText = "GST {$i['gst_rate']}% (CGST {$half}% + SGST {$half}%)";
}

    }

   $html .= "
<tr>
    <td class='left' style='width:60%'>
        <b>{$i['product_name']}</b><br>
        <span class='small'>
            MRP ".number_format($i['original_rate'],2)." |
            Disc {$disc}<br>
            {$gstText}
        </span>
    </td>
    <td style='width:15%; text-align:center'>
        {$i['quantity']}
    </td>
    <td class='right' style='width:25%'>
        ".number_format($i['final_rate'],2)."
    </td>
</tr>";

}
$html .= "</table>

<div class='line'></div>";
$html .= "
<table>
<tr><td>Total MRP</td><td class='right'>".number_format($total_mrp,2)."</td></tr>
<tr><td>Today Savings</td><td class='right'>".number_format($total_discount,2)."</td></tr>
<tr><td>Taxable</td><td class='right'>".number_format($sale['taxable_amount'],2)."</td></tr>
<tr><td>CGST</td><td class='right'>".number_format($sale['cgst'],2)."</td></tr>
<tr><td>SGST</td><td class='right'>".number_format($sale['sgst'],2)."</td></tr>";
if ($sale['igst'] > 0) {
    $html .= "<tr><td>IGST</td><td class='right'>".number_format($sale['igst'],2)."</td></tr>";
}
$html .= "
<tr>
<td><b>NET PAID</b></td>
<td class='right'><b>".number_format($payment['amount'],2)."</b></td>
</tr>
</table>

<div class='line'></div>";
$html .= "
<div class='small'>
Customer: {$sale['customer_name']} ({$sale['customer_id']})<br>
Points Earned (This Sale): {$sale_earned_points}<br>
Points Redeemed (This Sale): {$sale_redeem_points}<br>



</div>";
$html .= "
<div class='center'>
<img src='{$barcode}'><br>
Paid via {$payment['payment_mode']}<br>
Thank You • Visit Again
</div>

</body></html>";


/* ================= PDF ================= */
$pdf = new Dompdf();
$pdf->loadHtml($html);
$pdf->setPaper([0,0,226.77,720],'portrait');
$pdf->render();

header("Content-Type: application/pdf");
header("Content-Disposition: inline; filename=receipt_{$invoice}.pdf");
echo $pdf->output();
exit;
