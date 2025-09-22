<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../bootstrap/db.php';

$authUser = getCurrentUser();
if(!$authUser) sendError("Unauthorized",401);

$input = json_decode(file_get_contents('php://input'), true);
if(!$input || empty($input['user_id'])) sendError("user_id is required");

$stmt = $pdo->prepare("UPDATE users SET username=?, email=?, role=? WHERE id=? AND org_id=?");
$stmt->execute([
    $input['username'] ?? '',
    $input['email'] ?? '',
    $input['role'] ?? '',
    $input['user_id'],
    $authUser['org_id']
]);

sendSuccess([],"User updated successfully");
