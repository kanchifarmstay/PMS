<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ical.php';

$roomId = trim((string)($_GET['room'] ?? ''));
$token = (string)($_GET['token'] ?? '');
$destination = strtolower(trim((string)($_GET['destination'] ?? 'generic')));

if (ICAL_TOKEN === '' || !hash_equals(ICAL_TOKEN, $token)) {
    http_response_code(403);
    exit('Invalid calendar token.');
}
if (!isValidRoomId($roomId)) {
    http_response_code(404);
    exit('Room not found.');
}
if ($destination !== 'generic' && !in_array($destination, SUPPORTED_ICAL_PLATFORMS, true)) {
    http_response_code(400);
    exit('Unsupported destination.');
}

$events = collectAvailabilityEvents($roomId, $destination);
$calendar = renderAvailabilityCalendar($roomId, ROOM_IDS[$roomId], $events);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="' . $roomId . '-' . $destination . '.ics"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo $calendar;
