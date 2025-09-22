<?php
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../bootstrap/db.php';
require_once __DIR__ . '/../../models/Org.php';
require_once __DIR__ . '/../../models/Subscription.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit;
}

try {
    // Read raw input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendError("Invalid JSON input", 422);
    }

    // Required fields (now includes vertical)
    $required = ['name', 'email', 'phone', 'vertical'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim($input[$field]) === '') {
            sendError("Field '$field' is required", 422);
        }
    }

    // Normalize and validate
    $name     = strtolower(trim($input['name']));
    $email    = strtolower(trim($input['email']));
    $phone    = preg_replace('/\s+/', '', $input['phone']); // Remove spaces
    $vertical = strtolower(trim($input['vertical']));

    // Allowed verticals (can extend this list)
    $allowedVerticals = ['retail', 'restaurant', 'pharmacy', 'supermarket', 'generic', 'textile'];
    if (!in_array($vertical, $allowedVerticals)) {
        sendError("Invalid vertical. Allowed: " . implode(', ', $allowedVerticals), 422);
    }

    // Email format check
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendError("Invalid email format", 422);
    }

    // Phone format check (10-15 digits)
    if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
        sendError("Invalid phone number. It must be 10-15 digits.", 422);
    }

    // --- GST fields (optional) ---
    $gstin    = isset($input['gstin']) ? trim($input['gstin']) : null;
    $gst_type = isset($input['gst_type']) && in_array($input['gst_type'], ['CGST_SGST','IGST']) 
                ? $input['gst_type'] : 'CGST_SGST';
    $gst_rate = isset($input['gst_rate']) && is_numeric($input['gst_rate']) 
                ? (float)$input['gst_rate'] : 18.00;

    // Check duplicate org by email
    $stmt = $pdo->prepare("SELECT * FROM orgs WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $existingOrg = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingOrg) {
        if (!$existingOrg['is_verified']) {
            sendError("Email already registered but not verified. Please verify your email.", 409);
        }

        // Check subscription
        $subscriptionModel = new Subscription($pdo);
        $activeSub = $subscriptionModel->getActive($existingOrg['id']);

        if ($activeSub) {
            sendError("Email already has an active subscription (".$activeSub['plan']."). Cannot create new org.", 409);
        } else {
            sendError("Subscription expired, please renew", 409);
        }
    }

    // Insert new org with verification token
    $token = bin2hex(random_bytes(16));

    $stmt = $pdo->prepare("
        INSERT INTO orgs (name, email, phone, vertical, gstin, gst_type, gst_rate, verification_token, is_verified) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
    ");
    $stmt->execute([$name, $email, $phone, $vertical, $gstin, $gst_type, $gst_rate, $token]);

    $org_id = $pdo->lastInsertId();

    // Dummy email sending (replace in production with real mailer)
    $verifyLink = "https://yourdomain.com/apis/auth/verify_email.php?token=".$token;
    @mail($email, "Verify your email", "Click here to verify your organization: $verifyLink");

    // Success response
    sendSuccess(
        [
            'org_id'   => $org_id,
            'email'    => $email,     // ✅ return email also
            'vertical' => $vertical,
            'gstin'    => $gstin,
            'gst_type' => $gst_type,
            'gst_rate' => $gst_rate
        ],
        "Organization created successfully. Verification email sent.",
        201
    );

} catch (Exception $e) {
    sendError("Server error: " . $e->getMessage(), 500);
}
