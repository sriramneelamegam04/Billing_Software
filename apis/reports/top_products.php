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
$date_from=$_REQUEST['date_from']??null;
$date_to=$_REQUEST['date_to']??null;
$limit=(int)($_REQUEST['limit']??5);

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
      SELECT si.product_id,p.name product_name,
             SUM(si.quantity) qty_sold,SUM(si.amount) total_amount
      FROM sales s
      JOIN sale_items si ON si.sale_id=s.id
      JOIN products p ON p.id=si.product_id
      WHERE $where
      GROUP BY si.product_id,p.name
      ORDER BY qty_sold DESC,total_amount DESC
      LIMIT $limit
    ");
    $stmt->execute($params);
    $rows=$stmt->fetchAll();

    sendSuccess("Top products",['rows'=>$rows]);
}catch(Throwable $e){sendError($e->getMessage(),500);}
