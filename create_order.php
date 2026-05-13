<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Essential if frontend and backend domain differ, though they should be same on hostinger
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Razorpay Live API Credentials
$keyId = 'rzp_live_SImDeaehZI93nG';
$keySecret = '2HSD20TrjbZSB2PI4l2L6zYk';

// Read incoming JSON payload
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (!isset($input['amount'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Amount is required']);
    exit;
}

$amount = $input['amount']; // Amount should come in paise

$guestName = $input['guestName'] ?? 'Not provided';
$guestEmail = $input['guestEmail'] ?? 'Not provided';
$guestPhone = $input['guestPhone'] ?? 'Not provided';
$checkin = $input['checkin'] ?? 'Not provided';
$checkout = $input['checkout'] ?? 'Not provided';
$roomName = $input['roomName'] ?? 'Not provided';
$days = $input['days'] ?? 'Not provided';

// Send alert email when a Razorpay order is created (payment not yet complete)
$from       = 'ops@kanchifarmstay.com';
$amountRs   = number_format($amount / 100);
$htmlAlert  = "
<html><body style='font-family:Arial,sans-serif;color:#2d3748;padding:24px'>
<h2 style='color:#c05621'>⏳ Booking Initiated — Payment Pending</h2>
<p>A guest has started the payment process on Razorpay. <strong>This is not yet a confirmed booking.</strong></p>
<table style='border-collapse:collapse;font-size:14px;margin-top:16px'>
  <tr><td style='padding:8px 16px 8px 0;color:#718096'><strong>Guest</strong></td><td>{$guestName}</td></tr>
  <tr><td style='padding:8px 16px 8px 0;color:#718096'><strong>Email</strong></td><td>{$guestEmail}</td></tr>
  <tr><td style='padding:8px 16px 8px 0;color:#718096'><strong>Phone</strong></td><td>{$guestPhone}</td></tr>
  <tr><td style='padding:8px 16px 8px 0;color:#718096'><strong>Room</strong></td><td>{$roomName}</td></tr>
  <tr><td style='padding:8px 16px 8px 0;color:#718096'><strong>Check-in</strong></td><td>{$checkin}</td></tr>
  <tr><td style='padding:8px 16px 8px 0;color:#718096'><strong>Check-out</strong></td><td>{$checkout}</td></tr>
  <tr><td style='padding:8px 16px 8px 0;color:#718096'><strong>Nights</strong></td><td>{$days}</td></tr>
  <tr><td style='padding:8px 16px 8px 0;color:#718096'><strong>Amount</strong></td><td><strong>₹{$amountRs}</strong></td></tr>
</table>
<p style='margin-top:20px;padding:12px 16px;background:#fff8f0;border:1px solid #fcd9a0;border-radius:6px;font-size:13px;color:#7b341e'>
  You will receive a second email (✅ Booking Confirmed) only after the payment succeeds.
</p>
</body></html>";

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: Kanchi Farm Stay <{$from}>\r\n";
$headers .= "Reply-To: {$guestEmail}\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

@mail('ops@kanchifarmstay.com', "⏳ Booking Started: {$guestName} — {$roomName}", $htmlAlert, $headers, "-f{$from}");

// Razorpay Order creation API payload
$postData = [
    'amount' => $amount,
    'currency' => 'INR',
    'payment_capture' => 1 // Auto capture
];

// Initialize cURL securely
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.razorpay.com/v1/orders");
curl_setopt($ch, CURLOPT_USERPWD, $keyId . ':' . $keySecret);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Return response to frontend
http_response_code($httpCode);
echo $response;
?>