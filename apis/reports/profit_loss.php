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
      SELECT s.outlet_id,o.name outlet_name,
             COALESCE(SUM(si.amount),0) items_amount,
             COALESCE(SUM(s.discount),0) discount
      FROM sales s
      LEFT JOIN sale_items si ON si.sale_id=s.id
      JOIN outlets o ON o.id=s.outlet_id
      WHERE $where
      GROUP BY s.outlet_id,o.name
    ");
    $stmt->execute($params);
    $sales=$stmt->fetchAll();

    $stmt=$pdo->prepare("
      SELECT s.outlet_id,COALESCE(SUM(p.amount),0) collections
      FROM sales s
      LEFT JOIN payments p ON p.sale_id=s.id
      WHERE $where
      GROUP BY s.outlet_id
    ");
    $stmt->execute($params);
    $collect=$stmt->fetchAll();
    $cmap=[];
    foreach($collect as $r){$cmap[$r['outlet_id']]=$r['collections'];}

    $rows=[];
    foreach($sales as $r){
        $net=$r['items_amount']-$r['discount'];
        $col=$cmap[$r['outlet_id']]??0;
        $rows[]=[
            'outlet_id'=>$r['outlet_id'],
            'outlet_name'=>$r['outlet_name'],
            'items_amount'=>(float)$r['items_amount'],
            'discount'=>(float)$r['discount'],
            'net_sales'=>$net,
            'collections'=>(float)$col,
            'outstanding'=>$net-$col
        ];
    }

    $totals=[
        'items_amount'=>array_sum(array_column($rows,'items_amount')),
        'discount'=>array_sum(array_column($rows,'discount')),
        'net_sales'=>array_sum(array_column($rows,'net_sales')),
        'collections'=>array_sum(array_column($rows,'collections')),
        'outstanding'=>array_sum(array_column($rows,'outstanding'))
    ];
    sendSuccess("Profit & Loss",['rows'=>$rows,'totals'=>$totals]);
}catch(Throwable $e){sendError($e->getMessage(),500);}
