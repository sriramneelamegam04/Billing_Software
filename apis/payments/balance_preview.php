<?php
require_once __DIR__.'/../../helpers/response.php';

header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) sendError("Invalid JSON", 422);

$sale_amount   = (float)($input['sale_amount'] ?? 0);
$paid_amount   = (float)($input['paid_amount'] ?? 0);
$return_credit = (float)($input['return_credit'] ?? 0);

if ($sale_amount < 0 || $paid_amount < 0 || $return_credit < 0) {
    sendError("Amounts cannot be negative", 422);
}

/*
CORE FORMULA
*/
$net_due = $sale_amount - $return_credit;
$balance = round($paid_amount - $net_due, 2);

sendSuccess([
    "sale_amount"   => $sale_amount,
    "return_credit" => $return_credit,
    "paid_amount"   => $paid_amount,
    "net_due"       => round($net_due,2),
    "balance"       => $balance,
    "action" => $balance > 0
        ? "GIVE_CHANGE"
        : ($balance < 0 ? "COLLECT_MORE" : "SETTLED")
], "Balance calculated");
