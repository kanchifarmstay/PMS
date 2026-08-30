<?php
declare(strict_types=1);

require_once __DIR__ . '/channel-manager/config.php';
require_once __DIR__ . '/channel-manager/db.php';
require_once __DIR__ . '/channel-manager/payment-service.php';
require_once __DIR__ . '/channel-manager/whatsapp.php';
require_once __DIR__ . '/channel-manager/api.php';

sendJsonHeaders('POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error'=>'Method not allowed.'], 405);
try {
    $input = readJsonRequest();
    $orderId = trim((string)($input['razorpay_order_id'] ?? ''));
    $paymentId = trim((string)($input['razorpay_payment_id'] ?? ''));
    $signature = trim((string)($input['razorpay_signature'] ?? ''));
    if ($orderId === '' || $paymentId === '' || $signature === '') {
        throw new InvalidArgumentException('Missing payment verification fields.');
    }
    $before = getDB()->prepare('SELECT booking_id FROM payment_orders WHERE order_id=?');
    $before->execute([$orderId]);
    $existingId = (int)($before->fetchColumn() ?: 0);
    $bookingId = confirmRazorpayPayment($orderId, $paymentId, $signature);
    if ($existingId === 0) {
        $booking = getBookingById($bookingId);
        if ($booking) sendWhatsAppNotification(buildBookingMessage($booking));
    }
    jsonResponse(['success'=>true, 'bookingId'=>$bookingId]);
} catch (InvalidArgumentException $e) {
    jsonResponse(['error'=>$e->getMessage()], 422);
} catch (DomainException $e) {
    jsonResponse(['error'=>$e->getMessage()], 409);
} catch (Throwable $e) {
    error_log('Booking confirmation failure: ' . $e->getMessage());
    jsonResponse(['error'=>'Payment was received but confirmation needs attention. Please contact the property.'], 500);
}
