<?php
/**
 * Availability checker — called before initiating Razorpay payment.
 * Returns whether the requested dates are free for a given room.
 *
 * POST body: { roomId, checkIn, checkOut }
 * Response:  { available: true } | { available: false, message: "..." }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/channel-manager/config.php';
require_once __DIR__ . '/channel-manager/db.php';

$input = json_decode(file_get_contents('php://input'), true);

$roomId   = trim($input['roomId']   ?? '');
$checkIn  = trim($input['checkIn']  ?? '');
$checkOut = trim($input['checkOut'] ?? '');

// Basic validation
if (!$roomId || !$checkIn || !$checkOut) {
    http_response_code(400);
    echo json_encode(['available' => false, 'message' => 'Missing required fields.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut)) {
    http_response_code(400);
    echo json_encode(['available' => false, 'message' => 'Invalid date format.']);
    exit;
}

if ($checkOut <= $checkIn) {
    echo json_encode(['available' => false, 'message' => 'Check-out must be after check-in.']);
    exit;
}

// Check for overlapping confirmed bookings
// Overlap condition: existing.check_in < requested.check_out AND existing.check_out > requested.check_in
$db   = getDB();
$stmt = $db->prepare("
    SELECT COUNT(*) as cnt FROM bookings
    WHERE room_id = ?
      AND status = 'confirmed'
      AND check_in  < ?
      AND check_out > ?
");
$stmt->execute([$roomId, $checkOut, $checkIn]);
$row = $stmt->fetch();

if ($row['cnt'] > 0) {
    echo json_encode([
        'available' => false,
        'message'   => 'Sorry, these dates are not available. Please choose different dates.'
    ]);
} else {
    echo json_encode(['available' => true]);
}
