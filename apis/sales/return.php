<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../services/SubscriptionService.php';

header("Content-Type: application/json");

/* ================= AUTH ================= */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized",401);

(new SubscriptionService($pdo))->checkActive($authUser['org_id']);

/* ================= INPUT ================= */
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) sendError("Invalid JSON");

foreach (['sale_id','items','reason'] as $f) {
    if (empty($input[$f])) sendError("$f is required");
}

$sale_id = (int)$input['sale_id'];
$reason  = trim($input['reason']);
$items   = $input['items'];

/* ================= FETCH ORIGINAL SALE ================= */
$stmt = $pdo->prepare("
    SELECT * FROM sales
    WHERE id=? AND org_id=? AND status=1
");
$stmt->execute([$sale_id,$authUser['org_id']]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sale) sendError("Original sale not found");

/* ================= FETCH SALE ITEMS + PRODUCT META ================= */
$stmt = $pdo->prepare("
    SELECT 
        si.*,
        p.name AS product_name,
        JSON_UNQUOTE(JSON_EXTRACT(p.meta,'$.barcode')) AS barcode
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    WHERE si.sale_id=?
");
$stmt->execute([$sale_id]);
$saleItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$saleItems) sendError("No sale items found");

/* ================= BUILD LOOKUP MAPS ================= */
$byProductId = [];
$byBarcode   = [];
$byName      = [];

foreach ($saleItems as $si) {
    $byProductId[$si['product_id']] = $si;
    if (!empty($si['barcode'])) {
        $byBarcode[$si['barcode']] = $si;
    }
    $byName[strtolower($si['product_name'])] = $si;
}

/* ================= RESOLVE RETURN ITEMS ================= */
$resolved = [];

foreach ($items as $r) {

    if (empty($r['return_qty']) || $r['return_qty'] <= 0) {
        sendError("return_qty invalid");
    }

    $si = null;

    if (!empty($r['product_id']) && isset($byProductId[$r['product_id']])) {
        $si = $byProductId[$r['product_id']];
    } elseif (!empty($r['barcode']) && isset($byBarcode[$r['barcode']])) {
        $si = $byBarcode[$r['barcode']];
    } elseif (!empty($r['product_name'])) {
        $key = strtolower(trim($r['product_name']));
        if (isset($byName[$key])) {
            $si = $byName[$key];
        }
    }

    if (!$si) {
        sendError("Unable to match return item with original sale");
    }

    if ($r['return_qty'] > $si['quantity']) {
        sendError("Return qty exceeds sold qty for product {$si['product_name']}");
    }

    $resolved[] = [
        'sale_item' => $si,
        'qty'       => (float)$r['return_qty']
    ];
}

/* ================= TRANSACTION ================= */
try {
    $pdo->beginTransaction();

    /* ---------- CREATE RETURN SALE ---------- */
    $stmt = $pdo->prepare("
        INSERT INTO sales
        (org_id,outlet_id,customer_id,status,total_amount,note,created_at)
        VALUES (?,?,?,?,?,?,NOW())
    ");
    $stmt->execute([
        $sale['org_id'],
        $sale['outlet_id'],
        $sale['customer_id'],
        2,          // status = RETURN
        0,
        $reason
    ]);
    $return_sale_id = $pdo->lastInsertId();

    $refund_total = 0;

    foreach ($resolved as $r) {

        $si  = $r['sale_item'];
        $qty = $r['qty'];

        $unit_price  = $si['amount'] / $si['quantity'];
        $line_refund = round($unit_price * $qty, 2);
        $refund_total += $line_refund;

        /* ---------- RETURN SALE ITEM ---------- */
        $stmt = $pdo->prepare("
            INSERT INTO sale_items
            (sale_id,product_id,variant_id,quantity,rate,amount)
            VALUES (?,?,?,?,?,?)
        ");
        $stmt->execute([
            $return_sale_id,
            $si['product_id'],
            $si['variant_id'],
            $qty,
            $si['rate'],
            -$line_refund
        ]);

        /* ---------- INVENTORY ADD BACK ---------- */
        $stmt = $pdo->prepare("
            UPDATE inventory
            SET quantity = quantity + ?
            WHERE org_id=? AND outlet_id=? AND product_id=?
              AND ((variant_id IS NULL AND ? IS NULL) OR variant_id=?)
        ");
        $stmt->execute([
            $qty,
            $sale['org_id'],
            $sale['outlet_id'],
            $si['product_id'],
            $si['variant_id'],
            $si['variant_id']
        ]);

        /* ---------- INVENTORY LOG ---------- */
        $stmt = $pdo->prepare("
            INSERT INTO inventory_logs
            (org_id,outlet_id,product_id,variant_id,change_type,quantity_change,reference_id,created_at)
            VALUES (?,?,?,?,?,?,?,NOW())
        ");
        $stmt->execute([
            $sale['org_id'],
            $sale['outlet_id'],
            $si['product_id'],
            $si['variant_id'],
            'manual_adjustment',
            $qty,
            $return_sale_id
        ]);
    }

    /* ---------- UPDATE RETURN TOTAL ---------- */
    $pdo->prepare("
        UPDATE sales SET total_amount=? WHERE id=?
    ")->execute([
        -round($refund_total,2),
        $return_sale_id
    ]);

    /* ---------- LOYALTY REVERSE ---------- */
    $stmt = $pdo->prepare("
        SELECT points_earned FROM loyalty_points
        WHERE sale_id=? AND org_id=?
        LIMIT 1
    ");
    $stmt->execute([$sale_id,$sale['org_id']]);
    $earned = (float)$stmt->fetchColumn();

    if ($earned > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO loyalty_points
            (org_id,outlet_id,customer_id,sale_id,points_earned,points_redeemed)
            VALUES (?,?,?,?,0,?)
        ");
        $stmt->execute([
            $sale['org_id'],
            $sale['outlet_id'],
            $sale['customer_id'],
            $return_sale_id,
            $earned
        ]);
    }

    $pdo->commit();

    sendSuccess([
        "return_sale_id" => $return_sale_id,
        "refund_amount"  => round($refund_total,2),
        "status"         => "RETURNED"
    ], "Sale returned successfully");

} catch (Exception $e) {
    $pdo->rollBack();
    sendError("Return failed: ".$e->getMessage());
}
