<?php
declare(strict_types=1);

require_once __DIR__ . '/channel-manager/config.php';
require_once __DIR__ . '/channel-manager/db.php';
require_once __DIR__ . '/channel-manager/booking-service.php';
require_once __DIR__ . '/channel-manager/api.php';

sendJsonHeaders('POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error'=>'Method not allowed.'], 405);

try {
    $input = readJsonRequest();
    $roomId = trim((string)($input['roomId'] ?? ''));
    $checkIn = trim((string)($input['checkIn'] ?? ''));
    $checkOut = trim((string)($input['checkOut'] ?? ''));
    $adults = max(1, (int)($input['adults'] ?? 1));
    $children = max(0, (int)($input['children'] ?? 0));
    $quote = calculateQuote($roomId, $checkIn, $checkOut, $adults, $children);
    $available = isInventoryAvailable($roomId, $checkIn, $checkOut);
    jsonResponse([
        'available'=>$available,
        'message'=>$available ? '' : 'These dates are no longer available.',
        'quote'=>$available ? $quote : null,
        'payment_configured'=>paymentIsConfigured(),
    ]);
} catch (InvalidArgumentException $e) {
    jsonResponse(['available'=>false, 'error'=>$e->getMessage()], 422);
} catch (Throwable) {
    jsonResponse(['available'=>false, 'error'=>'Availability could not be checked.'], 500);
}
