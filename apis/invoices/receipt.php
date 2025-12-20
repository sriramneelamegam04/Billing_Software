<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

require_once __DIR__ . '/../../vendor/autoload.php';
use Dompdf\Dompdf;

require_once __DIR__ . '/../../helpers/barcode_image.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Method Not Allowed", 405);
}

/* ================= AUTH ================= */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized",401);

$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required",403);
}

/* ================= INPUT ================= */
$input = json_decode(file_get_contents('php://input'), true);
if(!$input) sendError("Invalid JSON");

$sale_id   = (int)($input['sale_id'] ?? 0);
$outlet_id = (int)($input['outlet_id'] ?? 0);
if(!$sale_id || !$outlet_id) {
    sendError("sale_id and outlet_id required");
}

/* ================= FETCH SALE ================= */
$stmt = $pdo->prepare("
    SELECT s.*, o.name AS outlet_name, org.name AS org_name, org.gstin,
           c.name AS customer_name, c.phone AS customer_phone
    FROM sales s
    JOIN outlets o ON o.id = s.outlet_id
    JOIN orgs org ON org.id = s.org_id
    LEFT JOIN customers c ON c.id = s.customer_id
    WHERE s.id=? AND s.org_id=? AND s.outlet_id=?
    LIMIT 1
");
$stmt->execute([$sale_id,$authUser['org_id'],$outlet_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$sale) sendError("Sale not found",404);

/* ================= ITEMS (GST ALREADY CALCULATED) ================= */
$stmt = $pdo->prepare("
    SELECT 
        si.quantity,
        si.rate,
        si.amount,
        si.taxable_amount,
        si.cgst,
        si.sgst,
        si.igst,
        p.name AS product_name
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    WHERE si.sale_id=?
");
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= TOTALS (SUM ONLY) ================= */
$sub_total = 0;
$taxable_total = 0;
$cgst_total = 0;
$sgst_total = 0;
$igst_total = 0;

foreach ($items as $i) {
    $sub_total      += (float)$i['amount'];
    $taxable_total  += (float)$i['taxable_amount'];
    $cgst_total     += (float)$i['cgst'];
    $sgst_total     += (float)$i['sgst'];
    $igst_total     += (float)$i['igst'];
}

$discount  = (float)($sale['discount'] ?? 0);
$gross     = $sub_total - $discount;
$round_off = round($gross) - $gross;
$net_total = round($gross);

/* ================= BARCODE ================= */
$invoiceCode = "INV".$sale['id'];
$barcode = barcodeDataUri($invoiceCode, 60, 2);

/* ================= RECEIPT HTML (THERMAL STYLE) ================= */
$html = "
<html>
<head>
<style>
body { font-family: DejaVu Sans; font-size:11px; text-align:center; }
.line { border-top:1px dashed #000; margin:4px 0; }
table { width:100%; border-collapse:collapse; }
td { padding:2px; }
.right { text-align:right; }
.left { text-align:left; }
</style>
</head>
<body>

<h3>{$sale['org_name']}</h3>
<div>{$sale['outlet_name']}</div>
<div>GSTIN: {$sale['gstin']}</div>
<div>Bill No: {$invoiceCode}</div>
<div>Date: {$sale['created_at']}</div>

<img src='{$barcode}'><br>

<div class='line'></div>

<table>
<tr>
<td class='left'>Item</td>
<td>Qty</td>
<td>Rate</td>
<td class='right'>Amt</td>
</tr>";

foreach ($items as $i) {
    $html .= "
    <tr>
        <td class='left'>{$i['product_name']}</td>
        <td>{$i['quantity']}</td>
        <td>".number_format($i['rate'],2)."</td>
        <td class='right'>".number_format($i['amount'],2)."</td>
    </tr>";
}

$html .= "
<tr><td colspan='3' class='right'>Sub Total</td><td class='right'>".number_format($sub_total,2)."</td></tr>
<tr><td colspan='3' class='right'>Discount</td><td class='right'>".number_format($discount,2)."</td></tr>

<tr><td colspan='3' class='right'>CGST</td><td class='right'>".number_format($cgst_total,2)."</td></tr>
<tr><td colspan='3' class='right'>SGST</td><td class='right'>".number_format($sgst_total,2)."</td></tr>
<tr><td colspan='3' class='right'>IGST</td><td class='right'>".number_format($igst_total,2)."</td></tr>

<tr><td colspan='3' class='right'>Round Off</td><td class='right'>".number_format($round_off,2)."</td></tr>
<tr><td colspan='3' class='right'><b>NET AMOUNT</b></td>
<td class='right'><b>".number_format($net_total,2)."</b></td></tr>
</table>

<div class='line'></div>
<p>Inclusive of GST • Thank You Visit Again</p>

</body>
</html>";

/* ================= PDF (80mm) ================= */
$pdf = new Dompdf();
$pdf->loadHtml($html);
$pdf->setPaper([0,0,226.77,600],'portrait');
$pdf->render();

header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=receipt_{$invoiceCode}.pdf");
echo $pdf->output();
exit;
