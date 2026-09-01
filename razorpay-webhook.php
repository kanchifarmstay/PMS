<?php
declare(strict_types=1);

require_once __DIR__ . '/channel-manager/config.php';
require_once __DIR__ . '/channel-manager/db.php';
require_once __DIR__ . '/channel-manager/payment-service.php';
require_once __DIR__ . '/channel-manager/api.php';

sendJsonHeaders('POST');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error'=>'Method not allowed.'], 405);
$raw = file_get_contents('php://input') ?: '';
$signature = trim((string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? ''));
if (!verifyRazorpayWebhookSignature($raw, $signature)) jsonResponse(['error'=>'Invalid webhook signature.'], 403);
$payload = json_decode($raw, true);
if (!is_array($payload)) jsonResponse(['error'=>'Invalid webhook payload.'], 400);
if (($payload['event'] ?? '') !== 'payment.captured') jsonResponse(['ok'=>true, 'ignored'=>true]);
$payment = $payload['payload']['payment']['entity'] ?? [];
$orderId = trim((string)($payment['order_id'] ?? ''));
$paymentId = trim((string)($payment['id'] ?? ''));
if ($orderId === '' || $paymentId === '') jsonResponse(['error'=>'Webhook is missing payment identifiers.'], 422);
try {
    jsonResponse(['ok'=>true, 'bookingId'=>finalizeVerifiedRazorpayPayment($orderId, $paymentId)]);
} catch (Throwable $e) {
    error_log('Razorpay webhook reconciliation failure: ' . $e->getMessage());
    jsonResponse(['error'=>'Payment requires reconciliation.'], 409);
}
