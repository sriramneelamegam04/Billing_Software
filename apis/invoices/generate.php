<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

require_once __DIR__ . '/../../vendor/autoload.php';
use Dompdf\Dompdf;

require_once __DIR__ . '/../../helpers/barcode_image.php';

/* ================= AUTH ================= */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized",401);

$subscription = new Subscription($pdo);
if (!$subscription->getActive($authUser['org_id'])) {
    sendError("Active subscription required",403);
}

/* ================= INPUT ================= */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) sendError("Invalid JSON");

$sale_id   = (int)($input['sale_id'] ?? 0);
$outlet_id = (int)($input['outlet_id'] ?? 0);
if (!$sale_id || !$outlet_id) sendError("sale_id & outlet_id required");

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

/* ================= INVOICE NUMBER ================= */
$stmt = $pdo->prepare("
    SELECT id, next_invoice_no
    FROM numbering_schemes
    WHERE org_id=? LIMIT 1
");
$stmt->execute([$authUser['org_id']]);
$num = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$num) sendError("Invoice numbering missing");

$invoiceNo   = $num['next_invoice_no'];
$invoiceCode = "INV".str_pad($invoiceNo,8,'0',STR_PAD_LEFT);

$pdo->prepare("
    UPDATE numbering_schemes
    SET next_invoice_no = next_invoice_no + 1
    WHERE id=?
")->execute([$num['id']]);

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
            IF(JSON_EXTRACT(p.meta,'$.discount.type')='flat',
               JSON_EXTRACT(p.meta,'$.discount.value'),0)
        ) AS original_rate
    FROM sale_items si
    JOIN products p ON p.id=si.product_id
    WHERE si.sale_id=?
");
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* ================= THIS SALE LOYALTY ================= */

// Earned points (this sale)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(points_earned),0)
    FROM loyalty_points
    WHERE sale_id=? AND org_id=? AND customer_id=?
");
$stmt->execute([
    $sale_id,
    $sale['org_id'],
    $sale['customer_id']
]);
$saleEarned = (float)$stmt->fetchColumn();

// Redeemed points (this sale)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(points_redeemed),0)
    FROM loyalty_points
    WHERE sale_id=? AND org_id=? AND customer_id=?
");
$stmt->execute([
    $sale_id,
    $sale['org_id'],
    $sale['customer_id']
]);
$saleRedeemed = (float)$stmt->fetchColumn();

/* ================= TOTALS ================= */
$taxable = $cgst = $sgst = $igst = 0;
$total_mrp = $total_discount = 0;

foreach ($items as $i) {
    $taxable += $i['taxable_amount'];
    $cgst    += $i['cgst'];
    $sgst    += $i['sgst'];
    $igst    += $i['igst'];

    $total_mrp      += $i['original_rate'] * $i['quantity'];
    $total_discount += ($i['original_rate'] - $i['final_rate']) * $i['quantity'];

    
}

/* ================= LOYALTY ================= */
$stmt = $pdo->prepare("
    SELECT SUM(points_earned) e, SUM(points_redeemed) r
    FROM loyalty_points
    WHERE org_id=? AND customer_id=?
");
$stmt->execute([$sale['org_id'],$sale['customer_id']]);
$lp = $stmt->fetch(PDO::FETCH_ASSOC);

$pointsEarned  = (float)($lp['e'] ?? 0);
$pointsRedeem  = (float)($lp['r'] ?? 0);
$pointsBalance = $pointsEarned - $pointsRedeem;

/* ================= BARCODE ================= */
$barcode = barcodeDataUri($invoiceCode,60,2);

/* ================= LOGO ================= */
function imageToBase64($p){
    if(!file_exists($p)) return null;
    return 'data:image/'.pathinfo($p,PATHINFO_EXTENSION).
           ';base64,'.base64_encode(file_get_contents($p));
}
$logo = imageToBase64(__DIR__.'/../../assets/logo.png');

/* ================= HTML ================= */
/* ================= HTML ================= */
/* ================= HTML ================= */
$html = "
<html>
<head>
<style>
body{font-family:DejaVu Sans;font-size:12px}
.center{text-align:center}
.right{text-align:right}
.left{text-align:left}
table{width:100%;border-collapse:collapse;margin-top:6px}
th,td{border:1px solid #000;padding:6px}
th{background:#f0f0f0}
.no-border td{border:none}
.small{font-size:11px}
</style>
</head>
<body>";

/* ---------- LOGO ---------- */
if ($logo) {
    $html .= "
    <table class='no-border'>
        <tr>
            <td class='center'>
                <img src='{$logo}' style='max-width:180px'>
            </td>
        </tr>
    </table>";
}

/* ---------- ORG / OUTLET ---------- */
$html .= "
<table class='no-border'>
    <tr>
        <td class='center'>
            <h2>{$sale['org_name']}</h2>
            {$sale['outlet_name']}<br>
            GSTIN: {$sale['gstin']}
        </td>
    </tr>
</table>";

/* ---------- INVOICE + CUSTOMER ---------- */
$html .= "
<table class='small'>
    <tr>
        <td><b>Invoice</b>: {$invoiceCode}</td>
        <td class='right'><b>Date</b>: {$sale['created_at']}</td>
    </tr>
    <tr>
        <td><b>Customer</b>: {$sale['customer_name']}</td>
        <td class='right'><b>Phone</b>: {$sale['customer_phone']}</td>
    </tr>
</table>";

/* ---------- ITEM TABLE (NO AMOUNT COLUMN) ---------- */
$html .= "
<table>
<tr>
    <th>Item</th>
    <th width='10%'>Qty</th>
    <th width='20%'>Rate</th>
</tr>";

foreach ($items as $i) {

    $disc = "-";
    if (!empty($i['discount_type'])) {
        $disc = ($i['discount_type'] === 'percentage')
            ? $i['discount_value']."%"
            : "₹".$i['discount_value'];

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
        <td>
            {$i['product_name']}<br>
            <span class='small'>
    MRP {$i['original_rate']} | Disc {$disc}<br>
    {$gstText}
</span>

        </td>
        <td class='center'>{$i['quantity']}</td>
        <td class='right'>".number_format($i['final_rate'],2)."</td>
    </tr>";
}

/* ---------- TOTALS ---------- */
$html .= "
<tr>
    <td colspan='2' class='right'>Total MRP</td>
    <td class='right'>".number_format($total_mrp,2)."</td>
</tr>
<tr>
    <td colspan='2' class='right'>Today Savings</td>
    <td class='right'>".number_format($total_discount,2)."</td>
</tr>
<tr>
    <td colspan='2' class='right'>Taxable</td>
    <td class='right'>".number_format($taxable,2)."</td>
</tr>
<tr>
    <td colspan='2' class='right'>CGST</td>
    <td class='right'>".number_format($cgst,2)."</td>
</tr>
<tr>
    <td colspan='2' class='right'>SGST</td>
    <td class='right'>".number_format($sgst,2)."</td>
</tr>";

if ($igst > 0) {
    $html .= "
    <tr>
        <td colspan='2' class='right'>IGST</td>
        <td class='right'>".number_format($igst,2)."</td>
    </tr>";
}

if (!empty($meta['manual_discount'])) {
    $html .= "
    <tr>
        <td colspan='2' class='right'>Manual Discount</td>
        <td class='right'>-".number_format($meta['manual_discount'],2)."</td>
    </tr>";
}

if (!empty($meta['redeem_value'])) {
    $html .= "
    <tr>
        <td colspan='2' class='right'>Loyalty Redeem</td>
        <td class='right'>-".number_format($meta['redeem_value'],2)."</td>
    </tr>";
}

/* ---------- NET PAID ---------- */
$html .= "
<tr>
    <td colspan='2' class='right'><b>NET PAID</b></td>
    <td class='right'><b>".number_format($payment['amount'],2)."</b></td>
</tr>
</table>";

/* ---------- BARCODE ---------- */
$html .= "
<table class='no-border'>
    <tr>
        <td class='center'>
            <img src='{$barcode}'><br>
            <span class='small'>{$invoiceCode}</span>
        </td>
    </tr>
</table>";

/* ---------- FOOTER ---------- */
$html .= "
<p class='small center'>
Points Earned (This Bill): {$saleEarned} |
Points Redeemed (This Bill): {$saleRedeemed} |
Inclusive of GST • Thank You • Visit Again
</p>


</body>
</html>";



/* ================= PDF ================= */
$pdf=new Dompdf();
$pdf->loadHtml($html);
$pdf->setPaper('A4','portrait');
$pdf->render();

header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=invoice_{$invoiceCode}.pdf");
echo $pdf->output();
exit;
