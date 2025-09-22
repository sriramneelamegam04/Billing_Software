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

    $whereCust="c.org_id=:org_id";
    $whereSales="s.org_id=:org_id";
    $paramsCust=[":org_id"=>$org_id];
    $paramsSales=[":org_id"=>$org_id];

    if($outlet_id){
        $whereCust.=" AND c.outlet_id=:outlet_id";
        $whereSales.=" AND s.outlet_id=:outlet_id";
        $paramsCust[':outlet_id']=$outlet_id;
        $paramsSales[':outlet_id']=$outlet_id;
    }
    if($date_from){$whereSales.=" AND DATE(s.created_at)>=:df";$paramsSales[':df']=$date_from;}
    if($date_to){$whereSales.=" AND DATE(s.created_at)<=:dt";$paramsSales[':dt']=$date_to;}

    $stmt=$pdo->prepare("SELECT c.id,c.name,c.phone,o.name outlet_name
                         FROM customers c JOIN outlets o ON o.id=c.outlet_id
                         WHERE $whereCust");
    $stmt->execute($paramsCust);
    $customers=$stmt->fetchAll();

    $stmt=$pdo->prepare("SELECT s.customer_id,
                                COUNT(DISTINCT s.id) bills,
                                COALESCE(SUM(si.amount),0) items_total,
                                COALESCE(SUM(p.amount),0) collections
                         FROM sales s
                         LEFT JOIN sale_items si ON si.sale_id=s.id
                         LEFT JOIN payments p ON p.sale_id=s.id
                         WHERE $whereSales
                         GROUP BY s.customer_id");
    $stmt->execute($paramsSales);
    $agg=$stmt->fetchAll(PDO::FETCH_UNIQUE);

    foreach($customers as &$c){
        $cid=$c['id'];
        $r=$agg[$cid]??['bills'=>0,'items_total'=>0,'collections'=>0];
        $c['bills']=(int)$r['bills'];
        $c['items_total']=(float)$r['items_total'];
        $c['collections']=(float)$r['collections'];
        $c['outstanding']=$c['items_total']-$c['collections'];
    }

    $totals=[
        'customers'=>count($customers),
        'bills'=>array_sum(array_column($customers,'bills')),
        'items_total'=>array_sum(array_column($customers,'items_total')),
        'collections'=>array_sum(array_column($customers,'collections')),
        'outstanding'=>array_sum(array_column($customers,'outstanding'))
    ];
    sendSuccess("Customer report",['rows'=>$customers,'totals'=>$totals]);
}catch(Throwable $e){sendError($e->getMessage(),500);}
