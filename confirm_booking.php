<?php
/**
 * Booking Confirmation Endpoint
 * Called from script.js after a successful Razorpay payment.
 * 1. Saves booking to PMS database
 * 2. Sends WhatsApp notification to ops
 * 3. Sends HTML email to ops@kanchifarmstay.com
 * 4. Sends HTML confirmation email to guest
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); exit; }

require_once __DIR__ . '/channel-manager/config.php';
require_once __DIR__ . '/channel-manager/db.php';
require_once __DIR__ . '/channel-manager/whatsapp.php';

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!$input) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }

$required = ['paymentId', 'orderId', 'roomId', 'roomName', 'checkIn', 'checkOut', 'guestName', 'amount'];
foreach ($required as $f) {
    if (empty($input[$f])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing field: {$f}"]);
        exit;
    }
}

$nights = max(1, (int)ceil(
    (strtotime($input['checkOut']) - strtotime($input['checkIn'])) / 86400
));

$booking = [
    'room_id'     => $input['roomId'],
    'room_name'   => $input['roomName'],
    'check_in'    => $input['checkIn'],
    'check_out'   => $input['checkOut'],
    'guest_name'  => $input['guestName'],
    'guest_email' => $input['guestEmail'] ?? '',
    'guest_phone' => $input['guestPhone'] ?? '',
    'source'      => 'direct',
    'booking_ref' => $input['paymentId'],
    'amount'      => (float)$input['amount'],
    'status'      => 'confirmed',
    'uid'         => 'rzp-' . $input['paymentId'] . '@kanchifarmstay.com',
    'notes'       => 'Razorpay Order: ' . $input['orderId'],
];

$id = addBooking($booking);

if ($id === false) {
    echo json_encode(['success' => true, 'message' => 'Booking already recorded']);
    exit;
}

// ── 1. WhatsApp to ops ───────────────────────────────────────
sendWhatsAppNotification(buildBookingMessage(array_merge($booking, ['id' => $id])));

// ── 2. Email helper ──────────────────────────────────────────
function sendHtmlMail(string $to, string $subject, string $htmlBody, string $replyTo = ''): bool {
    $boundary = md5(uniqid());
    $from     = 'ops@kanchifarmstay.com';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Kanchi Farm Stay <{$from}>\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    if ($replyTo) $headers .= "Reply-To: {$replyTo}\r\n";

    return mail($to, $subject, $htmlBody, $headers, "-f{$from}");
}

function emailTemplate(string $title, string $bodyContent): string {
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0f4f0;font-family:'Segoe UI',Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f0;padding:32px 16px">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">
      <!-- Header -->
      <tr><td style="background:#2e5c3a;border-radius:12px 12px 0 0;padding:28px 32px;text-align:center">
        <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700">🌿 Kanchi Farm Stay</h1>
        <p style="color:#a8d5b5;margin:6px 0 0;font-size:14px">kanchifarmstay.com</p>
      </td></tr>
      <!-- Title bar -->
      <tr><td style="background:#4a7c59;padding:16px 32px">
        <h2 style="color:#fff;margin:0;font-size:16px;font-weight:600">{$title}</h2>
      </td></tr>
      <!-- Body -->
      <tr><td style="background:#fff;padding:32px;border-radius:0 0 12px 12px">
        {$bodyContent}
      </td></tr>
      <!-- Footer -->
      <tr><td style="padding:20px 0;text-align:center">
        <p style="color:#718096;font-size:12px;margin:0">
          Kanchi Farm Stay · Kanchipuram, Tamil Nadu<br>
          <a href="tel:+916383726094" style="color:#4a7c59">+91 63837 26094</a> ·
          <a href="mailto:ops@kanchifarmstay.com" style="color:#4a7c59">ops@kanchifarmstay.com</a>
        </p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

// ── 3. Ops notification email ────────────────────────────────
$opsBody = <<<HTML
<p style="color:#2d3748;font-size:15px;margin:0 0 20px">A new <strong>direct booking</strong> has been confirmed via Razorpay.</p>
<table width="100%" cellpadding="10" cellspacing="0" style="border-collapse:collapse;font-size:14px">
  <tr style="background:#f0f4f0"><td style="color:#718096;width:140px;border-radius:6px 0 0 6px"><strong>Guest</strong></td><td style="color:#1a202c">{$booking['guest_name']}</td></tr>
  <tr><td style="color:#718096"><strong>Phone</strong></td><td style="color:#1a202c">{$booking['guest_phone']}</td></tr>
  <tr style="background:#f0f4f0"><td style="color:#718096"><strong>Email</strong></td><td style="color:#1a202c">{$booking['guest_email']}</td></tr>
  <tr><td style="color:#718096"><strong>Room</strong></td><td style="color:#1a202c">{$booking['room_name']}</td></tr>
  <tr style="background:#f0f4f0"><td style="color:#718096"><strong>Check-in</strong></td><td style="color:#1a202c">{$booking['check_in']}</td></tr>
  <tr><td style="color:#718096"><strong>Check-out</strong></td><td style="color:#1a202c">{$booking['check_out']} ({$nights} night{$nights !== 1 ? 's' : ''})</td></tr>
  <tr style="background:#f0f4f0"><td style="color:#718096"><strong>Amount</strong></td><td style="color:#1a202c;font-weight:700;font-size:16px">₹{$booking['amount']}</td></tr>
  <tr><td style="color:#718096"><strong>Payment ID</strong></td><td style="color:#1a202c;font-family:monospace">{$booking['booking_ref']}</td></tr>
  <tr style="background:#f0f4f0"><td style="color:#718096"><strong>Order ID</strong></td><td style="color:#1a202c;font-family:monospace">{$input['orderId']}</td></tr>
  <tr><td style="color:#718096"><strong>Booking #</strong></td><td style="color:#1a202c">{$id}</td></tr>
</table>
<p style="margin:20px 0 0;padding:16px;background:#f0fff4;border:1px solid #9ae6b4;border-radius:8px;color:#276749;font-size:13px">
  ✅ This booking has been automatically saved to your PMS dashboard.
</p>
HTML;

sendHtmlMail(
    'ops@kanchifarmstay.com',
    "✅ New Booking: {$booking['guest_name']} — {$booking['room_name']}",
    emailTemplate("New Booking Confirmed", $opsBody),
    $booking['guest_email']
);

// ── 4. Guest confirmation email ──────────────────────────────
if (!empty($booking['guest_email'])) {
    $guestBody = <<<HTML
<p style="color:#2d3748;font-size:15px;margin:0 0 20px">Dear <strong>{$booking['guest_name']}</strong>,</p>
<p style="color:#4a5568;font-size:14px;line-height:1.7;margin:0 0 24px">
  Thank you for booking with us! Your payment has been received and your stay is confirmed.
  We look forward to welcoming you to Kanchi Farm Stay.
</p>
<div style="background:#f7fafc;border:1px solid #e2e8f0;border-radius:10px;padding:20px 24px;margin-bottom:24px">
  <h3 style="color:#2e5c3a;margin:0 0 16px;font-size:15px">📋 Booking Details</h3>
  <table width="100%" cellpadding="8" cellspacing="0" style="font-size:14px;border-collapse:collapse">
    <tr><td style="color:#718096;width:120px"><strong>Room</strong></td><td style="color:#1a202c">{$booking['room_name']}</td></tr>
    <tr style="background:#fff"><td style="color:#718096"><strong>Check-in</strong></td><td style="color:#1a202c">{$booking['check_in']}</td></tr>
    <tr><td style="color:#718096"><strong>Check-out</strong></td><td style="color:#1a202c">{$booking['check_out']}</td></tr>
    <tr style="background:#fff"><td style="color:#718096"><strong>Duration</strong></td><td style="color:#1a202c">{$nights} night{$nights !== 1 ? 's' : ''}</td></tr>
    <tr><td style="color:#718096"><strong>Amount Paid</strong></td><td style="color:#2e5c3a;font-weight:700">₹{$booking['amount']}</td></tr>
    <tr style="background:#fff"><td style="color:#718096"><strong>Reference</strong></td><td style="color:#718096;font-family:monospace;font-size:12px">{$booking['booking_ref']}</td></tr>
  </table>
</div>
<div style="background:#fff8f0;border:1px solid #fcd9a0;border-radius:10px;padding:16px 20px;margin-bottom:24px">
  <h3 style="color:#c05621;margin:0 0 10px;font-size:14px">🕐 Check-in Information</h3>
  <p style="color:#7b341e;font-size:13px;margin:0;line-height:1.7">
    Check-in: After 12:00 PM &nbsp;|&nbsp; Check-out: Before 11:00 AM<br>
    Please call us when you are on your way so we can prepare your room.
  </p>
</div>
<p style="color:#4a5568;font-size:14px;line-height:1.7;margin:0 0 8px">
  📍 <a href="https://maps.app.goo.gl/cV2gsfgTa6bsDpaW9" style="color:#4a7c59">View on Google Maps</a>
</p>
<p style="color:#4a5568;font-size:14px;margin:0">
  📞 <a href="tel:+916383726094" style="color:#4a7c59">+91 63837 26094</a> &nbsp;|&nbsp;
  📞 <a href="tel:+918825775747" style="color:#4a7c59">+91 88257 75747</a>
</p>
HTML;

    sendHtmlMail(
        $booking['guest_email'],
        "Your booking is confirmed — Kanchi Farm Stay 🌿",
        emailTemplate("Booking Confirmed!", $guestBody),
        'ops@kanchifarmstay.com'
    );
}

echo json_encode(['success' => true, 'bookingId' => $id]);
