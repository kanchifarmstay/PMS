<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking-service.php';
require_once __DIR__ . '/api.php';

sendJsonHeaders('GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

try {
    $roomId = trim((string)($_GET['room'] ?? ''));
    if (!isValidRoomId($roomId)) jsonResponse(['error'=>'Room not found.'], 404);
    $ranges = getBlockedRangesForRoom($roomId);
    jsonResponse([
        'room'=>$roomId,
        'blocked'=>array_map(static fn(array $range): array => [$range['check_in'], $range['check_out']], $ranges),
    ]);
} catch (Throwable) {
    jsonResponse(['error'=>'Availability is temporarily unavailable.'], 500);
}
