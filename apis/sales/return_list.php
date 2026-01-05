<?php
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../bootstrap/db.php';
require_once __DIR__ . '/../../services/SubscriptionService.php';

header("Content-Type: application/json");

/* ================= AUTH ================= */
$authUser = getCurrentUser();
if (!$authUser) sendError("Unauthorized", 401);

(new SubscriptionService($pdo))->checkActive($authUser['org_id']);

/* ================= QUERY PARAMS ================= */
$org_id    = $authUser['org_id'];
$outlet_id = $_GET['outlet_id'] ?? null;

/* ================= FETCH RETURN SALES ================= */
$where = "s.org_id = :org_id AND s.status = 2";
$params = [':org_id' => $org_id];

if ($outlet_id) {
    $where .= " AND s.outlet_id = :outlet_id";
    $params[':outlet_id'] = $outlet_id;
}

/* ================= MAIN QUERY ================= */
$stmt = $pdo->prepare("
    SELECT
        s.id              AS return_sale_id,
        s.created_at      AS return_date,
        ABS(s.total_amount) AS refund_amount,
        s.note            AS return_reason,

        o.name            AS outlet_name,

        c.id              AS customer_id,
        c.name            AS customer_name,
        c.phone           AS customer_phone,

        si.product_id,
        p.name            AS product_name,
        si.quantity,
        si.amount         AS line_amount
    FROM sales s
    JOIN outlets o      ON o.id = s.outlet_id
    LEFT JOIN customers c ON c.id = s.customer_id
    JOIN sale_items si  ON si.sale_id = s.id
    JOIN products p     ON p.id = si.product_id
    WHERE $where
    ORDER BY s.created_at DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= FORMAT RESPONSE ================= */
$list = [];

foreach ($rows as $r) {

    $rid = $r['return_sale_id'];

    if (!isset($list[$rid])) {
        $list[$rid] = [
            'return_sale_id' => (int)$rid,
            'return_date'    => $r['return_date'],
            'refund_amount'  => (float)$r['refund_amount'],
            'return_reason'  => $r['return_reason'],
            'outlet_name'    => $r['outlet_name'],
            'customer_id'    => (int)$r['customer_id'],
            'customer_name'  => $r['customer_name'],
            'customer_phone' => $r['customer_phone'],
            'items'          => []
        ];
    }

    /* 🔥 GST INCLUDED RATE CALCULATION */
    $gstIncludedRate = abs($r['line_amount']) / $r['quantity'];

    $list[$rid]['items'][] = [
        'product_id'   => (int)$r['product_id'],
        'product_name' => $r['product_name'],
        'quantity'     => (float)$r['quantity'],
        'rate'         => number_format($gstIncludedRate, 2, '.', ''),
        'amount'       => number_format($r['line_amount'], 2, '.', '')
    ];
}

/* ================= FINAL ================= */
$result = array_values($list);

sendSuccess([
    'rows'  => $result,
    'count' => count($result)
], "Return sales list");
