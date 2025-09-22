<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

// Require Dompdf
require_once __DIR__ . '/../../vendor/autoload.php';
use Dompdf\Dompdf;

// ✅ Barcode helper (SVG safe)
require_once __DIR__ . '/../../helpers/barcode_image.php';

$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized",401);

// Decode input
$input = json_decode(file_get_contents('php://input'), true);
if(!$input) sendError("Invalid JSON format");

// Required fields
$required = ['sale_id', 'outlet_id'];
foreach($required as $f){
    if(empty($input[$f])) sendError("$f is required");
}

$sale_id   = (int)$input['sale_id'];
$outlet_id = (int)$input['outlet_id'];

// ---- Fetch Sale with org + outlet + customer ----
$stmt = $pdo->prepare("
    SELECT s.*, o.name as outlet_name, org.name as org_name,
           org.gstin, org.gst_type, org.gst_rate,
           c.name as customer_name, c.phone as customer_phone
    FROM sales s
    JOIN outlets o ON o.id = s.outlet_id
    JOIN orgs org ON org.id = s.org_id
    LEFT JOIN customers c ON c.id = s.customer_id
    WHERE s.id = ? AND s.org_id = ? AND s.outlet_id = ?
");
$stmt->execute([$sale_id, $authUser['org_id'], $outlet_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$sale) sendError("Sale not found in this outlet or organization", 404);

// ---- Get next invoice no from numbering_schemes ----
$stmtNum = $pdo->prepare("SELECT id, next_invoice_no FROM numbering_schemes WHERE org_id = ? LIMIT 1");
$stmtNum->execute([$authUser['org_id']]);
$numRow = $stmtNum->fetch(PDO::FETCH_ASSOC);

if (!$numRow) {
    sendError("Numbering scheme not set up for this org", 500);
}

$invoiceNo = $numRow['next_invoice_no'];

// ---- Update next invoice no (increment by 1) ----
$upd = $pdo->prepare("UPDATE numbering_schemes SET next_invoice_no = next_invoice_no + 1, updated_at = NOW() WHERE id = ?");
$upd->execute([$numRow['id']]);

// ---- Fetch Items ----
$stmt2 = $pdo->prepare("
    SELECT si.*, p.name as product_name 
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = ?
");
$stmt2->execute([$sale_id]);
$items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// ---- Totals ----
$subtotal = 0;
foreach ($items as $item) {
    $subtotal += $item['quantity'] * $item['rate'] * $item['quantity'];
}
$discount = (float)($sale['discount'] ?? 0);
$taxable  = $subtotal - $discount;

$cgstAmt = $sgstAmt = $igstAmt = 0;
if ($sale['gst_type'] === 'CGST_SGST') {
    $cgstAmt = $taxable * ($sale['cgst'] / 100);
    $sgstAmt = $taxable * ($sale['sgst'] / 100);
} elseif ($sale['gst_type'] === 'IGST') {
    $igstAmt = $taxable * ($sale['igst'] / 100);
}
$netTotal = $taxable + $cgstAmt + $sgstAmt + $igstAmt;

// ---- Invoice-level Barcode (based on invoiceNo) ----
$invoiceBarcode = "INV" . str_pad($invoiceNo, 8, '0', STR_PAD_LEFT);
$barcodeDataUri = barcodeDataUri($invoiceBarcode, 60, 2);

// ---- Build Invoice HTML ----
$html = "
<html>
<head>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h2 { margin: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #000; padding: 6px; text-align: center; }
        th { background: #f0f0f0; }
        .right { text-align: right; }
        .barcode { margin-top: 10px; text-align:center; }
        .customer { margin-top: 10px; text-align:left; }
    </style>
</head>
<body>
    <div class='header'>
        <h2>".strtoupper($sale['org_name'])."</h2>
        <p>Outlet: {$sale['outlet_name']}</p>
        <p>GSTIN: ".($sale['gstin'] ?? 'N/A')."</p>
        <p>Invoice ID: {$invoiceNo} | Date: {$sale['created_at']}</p>
        <div class='barcode'>
            <img src='".$barcodeDataUri."' alt='barcode' /><br>
            <small>".$invoiceBarcode."</small>
        </div>
    </div>

    <div class='customer'>
        <strong>Customer:</strong> ".htmlspecialchars($sale['customer_name'] ?? 'N/A')."<br>
        <strong>Phone:</strong> ".htmlspecialchars($sale['customer_phone'] ?? 'N/A')."
    </div>

    <table>
        <thead>
            <tr>
                <th style='width:50%'>Product</th>
                <th style='width:15%'>Qty</th>
                <th style='width:15%'>Rate</th>
                <th style='width:20%'>Total</th>
            </tr>
        </thead>
        <tbody>";

foreach ($items as $item) {
    $lineTotal = $item['quantity'] * $item['rate'];
    $html .= "
        <tr>
            <td>{$item['product_name']}</td>
            <td>{$item['quantity']}</td>
            <td class='right'>".number_format($item['rate'],2)."</td>
            <td class='right'>".number_format($lineTotal,2)."</td>
        </tr>";
}

$html .= "
        <tr>
            <td colspan='3' class='right'><strong>Subtotal</strong></td>
            <td class='right'><strong>".number_format($subtotal,2)."</strong></td>
        </tr>
        <tr>
            <td colspan='3' class='right'>Discount</td>
            <td class='right'>".number_format($discount,2)."</td>
        </tr>
        <tr>
            <td colspan='3' class='right'><strong>Taxable Value</strong></td>
            <td class='right'><strong>".number_format($taxable,2)."</strong></td>
        </tr>";

if ($cgstAmt > 0 || $sgstAmt > 0) {
    $html .= "
        <tr>
            <td colspan='3' class='right'>CGST ({$sale['cgst']}%)</td>
            <td class='right'>".number_format($cgstAmt,2)."</td>
        </tr>
        <tr>
            <td colspan='3' class='right'>SGST ({$sale['sgst']}%)</td>
            <td class='right'>".number_format($sgstAmt,2)."</td>
        </tr>";
} elseif ($igstAmt > 0) {
    $html .= "
        <tr>
            <td colspan='3' class='right'>IGST ({$sale['igst']}%)</td>
            <td class='right'>".number_format($igstAmt,2)."</td>
        </tr>";
}

$html .= "
        <tr>
            <td colspan='3' class='right'><strong>Grand Total</strong></td>
            <td class='right'><strong>".number_format($netTotal,2)."</strong></td>
        </tr>
        </tbody>
    </table>
</body>
</html>
";

// ---- Generate PDF ----
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$pdfData = $dompdf->output();

// ---- Force download ----
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="invoice_'.$invoiceNo.'.pdf"');
header('Content-Length: ' . strlen($pdfData));

echo $pdfData;
exit;
