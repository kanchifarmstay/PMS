<?php
/**
 * Public Availability API
 *
 * GET /channel-manager/availability-api.php?room=wooden-villa
 *
 * Returns JSON: { "blocked": [["2025-02-01","2025-02-04"], ...] }
 * Each element is a [check_in, check_out] pair (check_out is the departure day,
 * which IS available for a new check-in).
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$roomId = trim($_GET['room'] ?? '');
$rooms  = ROOM_IDS;

if (!$roomId || !isset($rooms[$roomId])) {
    http_response_code(404);
    echo json_encode(['error' => 'Room not found']);
    exit;
}

$ranges  = getBlockedRanges($roomId);
$blocked = array_map(fn($r) => [$r['check_in'], $r['check_out']], $ranges);

echo json_encode(['room' => $roomId, 'blocked' => $blocked]);
