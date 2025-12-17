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

require_once __DIR__ . '/../../vendor/autoload.php';
use Dompdf\Dompdf;

require_once __DIR__ . '/../../helpers/barcode_image.php';

/* -------------------------------------------------
   AUTH
------------------------------------------------- */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

/* -------------------------------------------------
   INPUT
------------------------------------------------- */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) sendError("Invalid JSON");

$sale_id   = (int)($input['sale_id'] ?? 0);
$outlet_id = (int)($input['outlet_id'] ?? 0);

if (!$sale_id || !$outlet_id) {
    sendError("sale_id and outlet_id required");
}

/* -------------------------------------------------
   FETCH SALE + CUSTOMER (SCHEMA SAFE)
------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT 
        s.*,
        o.name AS outlet_name,
        org.name AS org_name,
        org.gstin,
        org.gst_type,
        org.gst_rate,

        c.id   AS customer_id,
        c.name AS customer_name,
        c.phone AS customer_phone

    FROM sales s
    JOIN outlets o ON o.id = s.outlet_id
    JOIN orgs org ON org.id = s.org_id
    LEFT JOIN customers c 
        ON c.id = s.customer_id
       AND c.org_id = s.org_id
       AND c.outlet_id = s.outlet_id

    WHERE s.id = ?
      AND s.org_id = ?
      AND s.outlet_id = ?
    LIMIT 1
");
$stmt->execute([$sale_id, $authUser['org_id'], $outlet_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) sendError("Sale not found", 404);

/* -------------------------------------------------
   CUSTOMER FALLBACK (IMPORTANT)
------------------------------------------------- */
$customerName  = trim((string)$sale['customer_name']);
$customerPhone = trim((string)$sale['customer_phone']);

if ($customerName === '') {
    $customerName = 'Walk-in Customer';
}
if ($customerPhone === '') {
    $customerPhone = '-';
}

/* -------------------------------------------------
   INVOICE NUMBER
------------------------------------------------- */
$stmtNum = $pdo->prepare("
    SELECT id, next_invoice_no
    FROM numbering_schemes
    WHERE org_id = ?
    LIMIT 1
");
$stmtNum->execute([$authUser['org_id']]);
$num = $stmtNum->fetch(PDO::FETCH_ASSOC);
if (!$num) sendError("Numbering scheme missing");

$invoiceNo = $num['next_invoice_no'];

$pdo->prepare("
    UPDATE numbering_schemes
    SET next_invoice_no = next_invoice_no + 1,
        updated_at = NOW()
    WHERE id = ?
")->execute([$num['id']]);

/* -------------------------------------------------
   SALE ITEMS
------------------------------------------------- */
$stmtItems = $pdo->prepare("
    SELECT 
        si.quantity,
        si.rate,
        si.amount,
        p.name AS product_name
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = ?
");
$stmtItems->execute([$sale_id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   GST CALC (EXACT AS sales/create.php)
------------------------------------------------- */
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
    } else {
        $igst = ($taxable * $gst_rate) / 100;
    }
}

$gross     = $taxable + $cgst + $sgst + $igst;
$round_off = round($gross) - $gross;
$final_amt = round($gross);

/* -------------------------------------------------
   BARCODE
------------------------------------------------- */
$invoiceCode = "INV" . str_pad($invoiceNo, 8, '0', STR_PAD_LEFT);
$barcode = barcodeDataUri($invoiceCode, 60, 2);

/* -------------------------------------------------
   HTML (TABLE FIXED – NO CUT)
------------------------------------------------- */
$html = "
<html>
<head>
<style>
body { font-family: DejaVu Sans; font-size:12px; }
.header { text-align:center; border-bottom:2px solid #000; padding-bottom:8px; }
table { width:100%; border-collapse:collapse; margin-top:8px; }
th,td { border:1px solid #000; padding:6px; }
th { background:#f2f2f2; }
.right { text-align:right; }
.center { text-align:center; }
.no-border td { border:none; }
.total { font-size:14px; font-weight:bold; }
</style>
</head>
<body>

<div class='header'>
<h2>".strtoupper($sale['org_name'])."</h2>
<div>{$sale['outlet_name']}</div>
<div>GSTIN: {$sale['gstin']}</div>
</div>

<table class='no-border'>
<tr>
<td><b>Invoice:</b> {$invoiceCode}</td>
<td class='right'><b>Date:</b> {$sale['created_at']}</td>
</tr>
<tr>
<td><b>Customer:</b> {$customerName}</td>
<td class='right'><b>Phone:</b> {$customerPhone}</td>
</tr>
</table>

<div class='center' style='margin:10px 0;'>
<img src='{$barcode}'><br>
<small>{$invoiceCode}</small>
</div>

<table>
<tr>
<th width='40%'>Product</th>
<th width='15%'>Qty</th>
<th width='20%'>Rate</th>
<th width='25%'>Total</th>
</tr>";

foreach ($items as $i) {
    $html .= "
    <tr>
        <td>{$i['product_name']}</td>
        <td class='center'>{$i['quantity']}</td>
        <td class='right'>".number_format($i['rate'],2)."</td>
        <td class='right'>".number_format($i['amount'],2)."</td>
    </tr>";
}

$html .= "
<tr><td colspan='3' class='right'>Sub Total</td><td class='right'>".number_format($sub_total,2)."</td></tr>
<tr><td colspan='3' class='right'>Discount</td><td class='right'>".number_format($discount,2)."</td></tr>
<tr><td colspan='3' class='right'><b>Taxable Value</b></td><td class='right'><b>".number_format($taxable,2)."</b></td></tr>";

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
<tr class='total'><td colspan='3' class='right'>Grand Total</td><td class='right'>".number_format($final_amt,2)."</td></tr>
</table>

</body>
</html>";

/* -------------------------------------------------
   PDF
------------------------------------------------- */
$pdf = new Dompdf();
$pdf->loadHtml($html);
$pdf->setPaper('A4','portrait');
$pdf->render();

header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=invoice_{$invoiceCode}.pdf");
echo $pdf->output();
exit;
