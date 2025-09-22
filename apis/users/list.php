<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/User.php';

$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized",401);

$userModel = new User($pdo);
$users = $userModel->list($authUser['org_id']);

sendSuccess($users,"Users list retrieved");
