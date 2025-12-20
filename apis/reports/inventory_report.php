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

// ✅ Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Method Not Allowed. Use GET"]);
    exit;
}

$authUser=getCurrentUser();
if(!$authUser) sendError("Unauthorized",401);

$org_id=(int)($_REQUEST['org_id']??0);
$outlet_id=$_REQUEST['outlet_id']??null;

if($authUser['role']==='manager'){
    $org_id=$authUser['org_id'];
    if(!empty($outlet_id)&&$outlet_id!=$authUser['outlet_id']){
        sendError("Forbidden: cannot access other outlets",403);
    }
    $outlet_id=$authUser['outlet_id'];
}

try{
    if($org_id<=0) sendError("org_id required",422);

    $whereProd="p.org_id=:org_id";
    $whereSales="s.org_id=:org_id";
    $paramsProd=[":org_id"=>$org_id];
    $paramsSales=[":org_id"=>$org_id];

    if($outlet_id){
        $whereProd.=" AND p.outlet_id=:outlet_id";
        $whereSales.=" AND s.outlet_id=:outlet_id";
        $paramsProd[':outlet_id']=$outlet_id;
        $paramsSales[':outlet_id']=$outlet_id;
    }

    $stmt=$pdo->prepare("SELECT p.id,p.name,p.category,p.price,o.name outlet_name
                         FROM products p JOIN outlets o ON o.id=p.outlet_id
                         WHERE $whereProd");
    $stmt->execute($paramsProd);
    $products=$stmt->fetchAll();

    $stmt=$pdo->prepare("SELECT si.product_id,
                                SUM(si.quantity) sold_qty,
                                SUM(si.amount) sold_value
                         FROM sales s
                         JOIN sale_items si ON si.sale_id=s.id
                         WHERE $whereSales
                         GROUP BY si.product_id");
    $stmt->execute($paramsSales);
    $map=$stmt->fetchAll(PDO::FETCH_UNIQUE);

    foreach($products as &$p){
        $r=$map[$p['id']]??['sold_qty'=>0,'sold_value'=>0];
        $p['sold_qty']=(int)$r['sold_qty'];
        $p['sold_value']=(float)$r['sold_value'];
    }

    $totals=[
        'products'=>count($products),
        'sold_qty'=>array_sum(array_column($products,'sold_qty')),
        'sold_value'=>array_sum(array_column($products,'sold_value'))
    ];
    sendSuccess("Inventory report",['rows'=>$products,'totals'=>$totals]);
}catch(Throwable $e){sendError($e->getMessage(),500);}
