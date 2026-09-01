<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking-service.php';
require_once __DIR__ . '/api.php';

sendJsonHeaders('GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$rooms = [];
foreach (ROOM_IDS as $roomId=>$roomName) {
    $pricing = roomPricing($roomId);
    $rooms[$roomId] = [
        'name'=>$roomName,
        'weekday'=>(float)$pricing['weekday'], 'weekend'=>(float)$pricing['weekend'],
        'baseAdults'=>(int)$pricing['base_adults'], 'baseChildren'=>(int)$pricing['base_children'],
        'maxAdults'=>(int)$pricing['max_adults'], 'maxChildren'=>(int)$pricing['max_children'],
    ];
}
jsonResponse([
    'rooms'=>$rooms, 'extraAdult'=>EXTRA_ADULT_RATE, 'extraChild'=>EXTRA_CHILD_RATE,
    'weekendDays'=>WEEKEND_ISO_DAYS, 'paymentConfigured'=>paymentIsConfigured(),
    'razorpayKeyId'=>paymentIsConfigured() ? RAZORPAY_KEY_ID : '',
]);
