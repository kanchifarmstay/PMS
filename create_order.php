<?php
declare(strict_types=1);

require_once __DIR__ . '/channel-manager/config.php';
require_once __DIR__ . '/channel-manager/db.php';
require_once __DIR__ . '/channel-manager/payment-service.php';
require_once __DIR__ . '/channel-manager/api.php';

sendJsonHeaders('POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error'=>'Method not allowed.'], 405);
try {
    jsonResponse(createRazorpayBookingOrder(readJsonRequest()), 201);
} catch (InvalidArgumentException $e) {
    jsonResponse(['error'=>$e->getMessage()], 422);
} catch (DomainException $e) {
    jsonResponse(['error'=>$e->getMessage()], 409);
} catch (Throwable $e) {
    error_log('Razorpay order failure: ' . $e->getMessage());
    jsonResponse(['error'=>'Online payment could not be started.'], 503);
}
