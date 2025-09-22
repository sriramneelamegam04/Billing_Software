<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized",401);

$q = $_GET['q'] ?? '';
if(empty($q)) sendError("Query parameter q is required");

$stmt = $pdo->prepare("SELECT * FROM users WHERE org_id=? AND (username LIKE ? OR email LIKE ?)");
$stmt->execute([$authUser['org_id'], "%$q%", "%$q%"]);
$users = $stmt->fetchAll();

sendSuccess($users,"Search results");
