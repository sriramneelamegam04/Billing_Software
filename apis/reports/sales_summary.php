<?php
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../bootstrap/db.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

$authUser=getCurrentUser();
if(!$authUser) sendError("Unauthorized",401);

$org_id=(int)($_REQUEST['org_id']??0);
$outlet_id=$_REQUEST['outlet_id']??null;
$date_from=$_REQUEST['date_from']??null;
$date_to=$_REQUEST['date_to']??null;

if($authUser['role']==='manager'){
    $org_id=$authUser['org_id'];
    if(!empty($outlet_id)&&$outlet_id!=$authUser['outlet_id']){
        sendError("Forbidden: cannot access other outlets",403);
    }
    $outlet_id=$authUser['outlet_id'];
}

try{
    if($org_id<=0) sendError("org_id required",422);

    $where="s.org_id=:org_id";
    $params=[":org_id"=>$org_id];
    if($outlet_id){$where.=" AND s.outlet_id=:outlet_id";$params[':outlet_id']=$outlet_id;}
    if($date_from){$where.=" AND DATE(s.created_at)>=:df";$params[':df']=$date_from;}
    if($date_to){$where.=" AND DATE(s.created_at)<=:dt";$params[':dt']=$date_to;}

    $stmt=$pdo->prepare("
      SELECT DATE(s.created_at) sdate,
             COUNT(DISTINCT s.id) bills,
             COALESCE(SUM(si.quantity),0) items_qty,
             COALESCE(SUM(si.amount),0) items_amount,
             COALESCE(SUM(s.discount),0) discount,
             COALESCE(SUM(p.amount),0) collections
      FROM sales s
      LEFT JOIN sale_items si ON si.sale_id=s.id
      LEFT JOIN payments p ON p.sale_id=s.id
      WHERE $where
      GROUP BY DATE(s.created_at)
      ORDER BY sdate
    ");
    $stmt->execute($params);
    $rows=$stmt->fetchAll();

    $totals=[
      'bills'=>array_sum(array_column($rows,'bills')),
      'items_qty'=>array_sum(array_column($rows,'items_qty')),
      'items_amount'=>array_sum(array_column($rows,'items_amount')),
      'discount'=>array_sum(array_column($rows,'discount')),
      'collections'=>array_sum(array_column($rows,'collections'))
    ];
    $totals['net_sales']=$totals['items_amount']-$totals['discount'];
    $totals['outstanding']=$totals['net_sales']-$totals['collections'];

    sendSuccess("Sales summary",['rows'=>$rows,'totals'=>$totals]);
}catch(Throwable $e){sendError($e->getMessage(),500);}
