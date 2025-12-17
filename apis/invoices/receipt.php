<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// Dompdf
require_once __DIR__ . '/../../vendor/autoload.php';
use Dompdf\Dompdf;

// Barcode helper
require_once __DIR__ . '/../../helpers/barcode_image.php';

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized",401);

// Input
$input = json_decode(file_get_contents('php://input'), true);
if(!$input) sendError("Invalid JSON");

if (empty($input['sale_id']) || empty($input['outlet_id'])) {
    sendError("sale_id and outlet_id required");
}

$sale_id   = (int)$input['sale_id'];
$outlet_id = (int)$input['outlet_id'];

/* ---------------- FETCH SALE ---------------- */
$stmt = $pdo->prepare("
    SELECT s.*,
           o.name AS outlet_name,
           org.name AS org_name,
           org.gstin,
           org.gst_type,
           org.gst_rate,
           c.name AS customer_name,
           c.phone AS customer_phone
    FROM sales s
    JOIN outlets o ON o.id = s.outlet_id
    JOIN orgs org ON org.id = s.org_id
    LEFT JOIN customers c ON c.id = s.customer_id
    WHERE s.id=? AND s.org_id=? AND s.outlet_id=?
");
$stmt->execute([$sale_id, $authUser['org_id'], $outlet_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$sale) sendError("Sale not found",404);

/* ---------------- INVOICE NO ---------------- */
$stmtNum = $pdo->prepare("
    SELECT id, next_invoice_no 
    FROM numbering_schemes 
    WHERE org_id=? LIMIT 1
");
$stmtNum->execute([$authUser['org_id']]);
$num = $stmtNum->fetch(PDO::FETCH_ASSOC);
if (!$num) sendError("Numbering scheme missing");

$invoiceNo = $num['next_invoice_no'];

$pdo->prepare("
    UPDATE numbering_schemes
    SET next_invoice_no = next_invoice_no + 1, updated_at = NOW()
    WHERE id = ?
")->execute([$num['id']]);

/* ---------------- ITEMS ---------------- */
$stmtItems = $pdo->prepare("
    SELECT si.*, p.name AS product_name
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    WHERE si.sale_id=?
");
$stmtItems->execute([$sale_id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

/* =================================================
   GST CALCULATION (sales/create.php SAME)
================================================= */
$sub_total = 0;
foreach ($items as $i) {
    $sub_total += (float)$i['amount'];
}

$discount = (float)($sale['discount'] ?? 0);
$taxable  = max(0, $sub_total - $discount);

$cgst = $sgst = $igst = 0;
$gst_rate = (float)$sale['gst_rate'];

if ($gst_rate > 0) {
    if ($sale['gst_type'] === 'CGST_SGST') {
        $half = $gst_rate / 2;
        $cgst = ($taxable * $half) / 100;
        $sgst = ($taxable * $half) / 100;
    } elseif ($sale['gst_type'] === 'IGST') {
        $igst = ($taxable * $gst_rate) / 100;
    }
}

$gross     = $taxable + $cgst + $sgst + $igst;
$round_off = round($gross) - $gross;
$final_amt = round($gross);

/* ---------------- BARCODE ---------------- */
$invoiceCode = "INV".str_pad($invoiceNo, 8, '0', STR_PAD_LEFT);
$barcode = barcodeDataUri($invoiceCode, 60, 2);

/* ---------------- RECEIPT HTML ---------------- */
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

<h3>".strtoupper($sale['org_name'])."</h3>
<div>{$sale['outlet_name']}</div>
<div>GSTIN: {$sale['gstin']}</div>
<div>Invoice: {$invoiceCode}</div>
<div>Date: {$sale['created_at']}</div>

<img src='{$barcode}'><br>
<small>{$invoiceCode}</small>

<div class='line'></div>

<div class='left'>
<b>Customer:</b> ".($sale['customer_name'] ?? 'N/A')."<br>
<b>Phone:</b> ".($sale['customer_phone'] ?? 'N/A')."
</div>

<div class='line'></div>

<table>
<tr>
<td class='left'><b>Item</b></td>
<td>Qty</td>
<td>Rate</td>
<td class='right'>Total</td>
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
<tr><td colspan='3' class='right'><b>Taxable</b></td><td class='right'><b>".number_format($taxable,2)."</b></td></tr>";

if ($cgst > 0) {
    $html .= "<tr><td colspan='3' class='right'>CGST (".($gst_rate/2)."%)</td><td class='right'>".number_format($cgst,2)."</td></tr>";
}
if ($sgst > 0) {
    $html .= "<tr><td colspan='3' class='right'>SGST (".($gst_rate/2)."%)</td><td class='right'>".number_format($sgst,2)."</td></tr>";
}
if ($igst > 0) {
    $html .= "<tr><td colspan='3' class='right'>IGST ({$gst_rate}%)</td><td class='right'>".number_format($igst,2)."</td></tr>";
}

$html .= "
<tr><td colspan='3' class='right'>Round Off</td><td class='right'>".number_format($round_off,2)."</td></tr>
<tr><td colspan='3' class='right'><b>Grand Total</b></td><td class='right'><b>".number_format($final_amt,2)."</b></td></tr>
</table>

<div class='line'></div>
<p>Thank you! Visit again</p>

</body>
</html>";

/* ---------------- PDF (80mm) ---------------- */
$pdf = new Dompdf();
$pdf->loadHtml($html);
$pdf->setPaper([0,0,226.77,600], 'portrait');
$pdf->render();

header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=receipt_{$invoiceCode}.pdf");
echo $pdf->output();
exit;
