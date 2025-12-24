<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../services/SubscriptionService.php';
require_once __DIR__ . '/../../models/Subscription.php';

require_once __DIR__ . '/../../vendor/autoload.php';
use Dompdf\Dompdf;

require_once __DIR__ . '/../../helpers/barcode_image.php';

/* =========================
   AUTH + SUBSCRIPTION
========================= */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

$subscriptionModel = new Subscription($pdo);
if (!$subscriptionModel->getActive($authUser['org_id'])) {
    sendError("Active subscription required", 403);
}

/* =========================
   INPUT
========================= */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) sendError("Invalid JSON");

$sale_id   = (int)($input['sale_id'] ?? 0);
$outlet_id = (int)($input['outlet_id'] ?? 0);
if (!$sale_id || !$outlet_id) sendError("sale_id and outlet_id required");

/* =========================
   FETCH SALE
========================= */
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
$stmt->execute([$sale_id, $authUser['org_id'], $outlet_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sale) sendError("Sale not found", 404);

/* =========================
   FETCH LATEST PAYMENT (SOURCE OF TRUTH)
========================= */
$stmt = $pdo->prepare("
    SELECT *
    FROM payments
    WHERE sale_id=? AND org_id=?
    ORDER BY id DESC
    LIMIT 1
");
$stmt->execute([$sale_id, $authUser['org_id']]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$payment) sendError("Payment not found for this sale", 404);

$paymentMeta = json_decode($payment['meta'], true) ?: [];

/* =========================
   CUSTOMER FALLBACK
========================= */
$customerName  = trim($sale['customer_name'] ?? '') ?: 'Walk-in Customer';
$customerPhone = trim($sale['customer_phone'] ?? '') ?: '-';

/* =========================
   INVOICE NUMBER
========================= */
$stmt = $pdo->prepare("
    SELECT id, next_invoice_no
    FROM numbering_schemes
    WHERE org_id=? LIMIT 1
");
$stmt->execute([$authUser['org_id']]);
$num = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$num) sendError("Invoice numbering missing");

$invoiceNo   = $num['next_invoice_no'];
$invoiceCode = "INV".str_pad($invoiceNo, 8, '0', STR_PAD_LEFT);

$pdo->prepare("
    UPDATE numbering_schemes
    SET next_invoice_no = next_invoice_no + 1
    WHERE id=?
")->execute([$num['id']]);

/* =========================
   FETCH ITEMS
========================= */
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

/* =========================
   TOTALS
========================= */
$taxableTotal = $cgstTotal = $sgstTotal = $igstTotal = 0;
foreach ($items as $i) {
    $taxableTotal += (float)$i['taxable_amount'];
    $cgstTotal    += (float)$i['cgst'];
    $sgstTotal    += (float)$i['sgst'];
    $igstTotal    += (float)$i['igst'];
}

/* =========================
   PAYMENT VALUES
========================= */
$originalAmount = (float)($paymentMeta['original_amount'] ?? $sale['total_amount']);
$redeemPoints   = (float)($paymentMeta['redeem_points'] ?? 0);
$redeemValue    = (float)($paymentMeta['redeem_value'] ?? 0);
$netAmount      = (float)$payment['amount'];

/* =========================
   BARCODE
========================= */
$barcode = barcodeDataUri($invoiceCode, 60, 2);

/* =========================
   HTML
========================= */
$html = "
<html>
<head>
<style>
body { font-family: DejaVu Sans; font-size:12px; }
.header { text-align:center; }
table { width:100%; border-collapse:collapse; margin-top:8px; }
th,td { border:1px solid #000; padding:6px; }
th { background:#eee; }
.right { text-align:right; }
.center { text-align:center; }
.no-border td { border:none; }
.total { font-size:14px; font-weight:bold; }
.small { font-size:11px; }
</style>
</head>
<body>

<div class='header'>
<h2>{$sale['org_name']}</h2>
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

<div class='center' style='margin:10px 0'>
<img src='{$barcode}'><br>
<small>{$invoiceCode}</small>
</div>

<table>
<tr>
<th>Product</th>
<th width='10%'>Qty</th>
<th width='20%'>Rate</th>
<th width='20%'>Amount</th>
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
<tr><td colspan='3' class='right'>Taxable Total</td><td class='right'>".number_format($taxableTotal,2)."</td></tr>
<tr><td colspan='3' class='right'>CGST</td><td class='right'>".number_format($cgstTotal,2)."</td></tr>
<tr><td colspan='3' class='right'>SGST</td><td class='right'>".number_format($sgstTotal,2)."</td></tr>";

if ($igstTotal > 0) {
    $html .= "<tr><td colspan='3' class='right'>IGST</td><td class='right'>".number_format($igstTotal,2)."</td></tr>";
}

if ($redeemValue > 0) {
    $html .= "<tr><td colspan='3' class='right'>Loyalty Redeemed ({$redeemPoints} pts)</td>
              <td class='right'>-".number_format($redeemValue,2)."</td></tr>";
}

$html .= "
<tr class='total'><td colspan='3' class='right'>Net Amount Paid</td>
<td class='right'>".number_format($netAmount,2)."</td></tr>
</table>

<p class='small center'>Inclusive of GST • Thank You Visit Again</p>

</body>
</html>";

/* =========================
   PDF
========================= */
$pdf = new Dompdf();
$pdf->loadHtml($html);
$pdf->setPaper('A4','portrait');
$pdf->render();

header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=invoice_{$invoiceCode}.pdf");
echo $pdf->output();
exit;
