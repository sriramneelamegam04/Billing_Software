<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/validation.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/User.php';

$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized",401);

$input = json_decode(file_get_contents('php://input'), true);
if(!$input) sendError("Invalid JSON format");

$required = ['username','email','password','role'];
foreach($required as $field){
    if(empty($input[$field])) sendError("$field is required");
}

$userModel = new User($pdo);
$existing = $userModel->getByEmail($input['email']);
if($existing) sendError("Email already exists");

$input['org_id'] = $authUser['org_id'];
$input['password'] = password_hash($input['password'], PASSWORD_DEFAULT);
$user_id = $userModel->create($input);

sendSuccess(['user_id'=>$user_id],"User created successfully");
