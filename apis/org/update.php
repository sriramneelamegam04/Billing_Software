<?php
require_once __DIR__.'/../../helpers/response.php';
require_once __DIR__.'/../../bootstrap/db.php';
require_once __DIR__.'/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PATCH , GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

// Read input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['id'])) {
    sendError("Field 'id' is required");
}

$id = intval($input['id']);

// Fetch current org
$stmt = $pdo->prepare("SELECT * FROM orgs WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$org) sendError("Organization not found", 404);

// --- Fields to update ---
$fields = ['name','email','phone','vertical','gstin','gst_type','gst_rate'];
$updateData = [];
$params = [];

// Normalize & validate
foreach ($fields as $field) {
    if (isset($input[$field]) && trim($input[$field]) !== '') {
        $val = trim($input[$field]);
        switch ($field) {
            case 'name':
            case 'vertical':
                $val = strtolower($val);
                break;

            case 'email':
                $val = strtolower($val);
                if (!filter_var($val, FILTER_VALIDATE_EMAIL)) sendError("Invalid email format");
                // Check for duplicate email
                $stmtCheck = $pdo->prepare("SELECT id FROM orgs WHERE email=? AND id<>? LIMIT 1");
                $stmtCheck->execute([$val, $id]);
                if ($stmtCheck->fetch()) sendError("Email already in use by another organization");
                break;

            case 'phone':
                $val = preg_replace('/\s+/', '', $val);
                if (!preg_match('/^[0-9]{10,15}$/', $val)) sendError("Invalid phone number. It must be 10-15 digits.");
                break;

            case 'gst_type':
                if (!in_array($val, ['CGST_SGST','IGST'])) $val = 'CGST_SGST';
                break;

            case 'gst_rate':
                if (!is_numeric($val)) $val = 18.00;
                $val = (float)$val;
                break;

            case 'gstin':
                $val = strtoupper($val);
                break;
        }

        $updateData[] = "$field=?";
        $params[] = $val;
    }
}

if (empty($updateData)) sendError("No fields to update");

// Execute update
$params[] = $id;
$sql = "UPDATE orgs SET ".implode(", ", $updateData)." WHERE id=?";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

sendSuccess([], "Organization updated successfully");
