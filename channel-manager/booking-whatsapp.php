<?php
/**
 * WhatsApp messages for one booking: send any approved guest template by hand.
 *
 *   booking-whatsapp.php?id=N
 *
 * This is the only way to send kfs_booking_cancelled and kfs_refund_processed,
 * because the refund amount and reference are not stored anywhere in the PMS
 * and must be typed by whoever issued the refund. The other guest templates
 * go out automatically and can be resent from here.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/wa-templates.php';

startSecureSession();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }

function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function waManualErrorText(string $detail): string {
    return match (true) {
        str_contains($detail, '131026') => 'This number does not appear to be on WhatsApp.',
        str_contains($detail, '131042') => 'Meta refused the send: the WhatsApp account has a payment method problem.',
        str_contains($detail, '132001') => 'Meta says this template does not exist or is not approved.',
        default => 'WhatsApp send failed: ' . $detail,
    };
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$b = $id ? getBookingById($id) : null;
if (!$b) { http_response_code(404); die('Booking not found.'); }

$to = whatsAppNumber((string)(($b['whatsapp_number'] ?? '') ?: ($b['guest_phone'] ?? '')));
$isOta = isOtaSource($b['source']);
$flash = ['ok' => (string)($_GET['ok'] ?? ''), 'err' => (string)($_GET['err'] ?? '')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken($_POST['csrf_token'] ?? null);
    $kind = (string)($_POST['kind'] ?? '');
    $amount = max(0.0, (float)str_replace(',', '', (string)($_POST['amount'] ?? '0')));
    $reference = trim((string)($_POST['reference'] ?? ''));
    $method = trim((string)($_POST['method'] ?? ''));
    [$template, $params, $suffix] = match ($kind) {
        'confirmation' => ['kfs_booking_confirmed', [$b['guest_name'], waBookingNo($b), $b['room_name'], waDate($b['check_in']), waDate($b['check_out']), waMoney((float)$b['amount_paid'])],
                           'id=' . (int)$b['id'] . '&token=' . bookingPdfToken((int)$b['id'])],
        'updated'      => ['kfs_booking_updated', waGuestUpdatedParams($b), null],
        'payment'      => ['kfs_payment_received', waGuestPaymentParams($b, $amount, $method), null],
        'balance'      => ['kfs_balance_reminder', waBalanceParams($b), null],
        'checkin'      => ['kfs_checkin_reminder', waCheckinParams($b), null],
        'cancelled'    => ['kfs_booking_cancelled', waCancelledParams($b, $amount), null],
        'refund'       => ['kfs_refund_processed', waRefundParams($b, $amount, $reference), null],
        default        => ['', [], null],
    };
    $err = '';
    if ($isOta) $err = 'Guests who booked through ' . waSourceLabel($b['source']) . ' are never messaged on WhatsApp.';
    elseif ($template === '') $err = 'Unknown message.';
    elseif ($to === null) $err = 'This booking has no valid guest phone or WhatsApp number. Edit the booking first.';
    elseif ($kind === 'confirmation' && bookingPdfToken((int)$b['id']) === '') $err = 'KFS_DOCUMENT_SIGNING_SECRET is not set, so the confirmation link would not open.';
    elseif (in_array($kind, ['payment', 'refund'], true) && $amount < 0.01) $err = 'Enter the amount.';
    elseif ($kind === 'refund' && $reference === '') $err = 'Enter the refund reference (e.g. the Razorpay refund id or UPI reference).';
    if ($err === '') {
        $r = waSend($template, $to, $params, $suffix, (int)$b['id'], null);
        if ($r['status'] === 'not_configured') $err = 'WhatsApp is not configured (KFS_WA_TOKEN / KFS_WA_PHONE_ID).';
        elseif ($r['status'] !== 'sent') $err = waManualErrorText($r['detail']);
    }
    $q = $err === '' ? 'ok=' . rawurlencode(($template) . ' sent to +' . $to) : 'err=' . rawurlencode($err);
    header('Location: booking-whatsapp.php?id=' . (int)$b['id'] . '&' . $q); exit;
}

$log = waLogForBooking((int)$b['id']);
$balance = waBalance($b);
$labels = [
    'kfs_booking_confirmed' => 'Booking confirmation', 'kfs_booking_updated' => 'Booking updated',
    'kfs_payment_received' => 'Payment received', 'kfs_balance_reminder' => 'Balance reminder',
    'kfs_checkin_reminder' => 'Check-in reminder', 'kfs_booking_cancelled' => 'Booking cancelled',
    'kfs_refund_processed' => 'Refund processed', 'kfs_admin_payment_received' => 'Payment alert (admin)',
    'kfs_admin_booking_cancelled' => 'Cancellation alert (admin)',
];
$confirmSent = ($b['guest_confirm_sent_at'] ?? '') !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>WhatsApp · Booking #<?= e(waBookingNo($b)) ?> — Kanchi Farm Stay</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 14px; color: #111827; background: #f3f4f6; }
  .wrap { max-width: 860px; margin: 0 auto; padding: 20px 16px 60px; }
  .top { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
  .top h1 { font-size: 20px; margin: 0 auto 0 0; color: #1a5c3a; }
  .btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; color: #111827; padding: 8px 14px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
  .btn-primary { background: #1a5c3a; border-color: #1a5c3a; color: #fff; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; }
  .card h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .6px; color: #1a5c3a; margin: 0 0 10px; }
  .facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 8px 16px; }
  .facts b { display: block; font-size: 11px; color: #6b7280; font-weight: 600; letter-spacing: .3px; }
  .msg { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; padding: 12px 0; border-top: 1px solid #f0f0f0; }
  .msg:first-of-type { border-top: 0; }
  .msg .what { flex: 1 1 260px; }
  .msg .what strong { display: block; }
  .msg .what span { color: #6b7280; font-size: 12.5px; }
  .msg form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  input, select { font: inherit; font-size: 13px; padding: 7px 9px; border: 1px solid #d1d5db; border-radius: 8px; width: 130px; }
  .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
  .ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
  .err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
  .tbl { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  td, th { text-align: left; padding: 7px 8px; border-top: 1px solid #f0f0f0; white-space: nowrap; }
  th { font-size: 11px; color: #6b7280; text-transform: uppercase; border-top: 0; }
  .muted { color: #6b7280; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>💬 WhatsApp · Booking #<?= e(waBookingNo($b)) ?></h1>
    <a class="btn" href="admin.php?section=bookings">← Bookings</a>
  </div>

  <?php if ($flash['ok'] !== ''): ?><div class="flash ok">✓ <?= e($flash['ok']) ?></div><?php endif; ?>
  <?php if ($flash['err'] !== ''): ?><div class="flash err"><?= e($flash['err']) ?></div><?php endif; ?>
  <?php if ($to === null): ?><div class="flash err">No valid guest phone or WhatsApp number on this booking — edit the booking to add one.</div>
  <?php endif; ?>
  <?php if ($isOta): ?><div class="flash warn">This is a <?= e(waSourceLabel($b['source'])) ?> booking. Guests who book through Airbnb, Booking.com, Agoda or MakeMyTrip are never messaged on WhatsApp, so nothing can be sent from here.</div><?php endif; ?>

  <div class="card">
    <h2>Booking</h2>
    <div class="facts">
      <div><b>GUEST</b><?= e($b['guest_name']) ?></div>
      <div><b>WHATSAPP TO</b><?= $to ? '+' . e($to) : '—' ?></div>
      <div><b>ROOM</b><?= e($b['room_name']) ?></div>
      <div><b>DATES</b><?= e(waDateRange($b['check_in'], $b['check_out'])) ?></div>
      <div><b>TOTAL / PAID</b>Rs. <?= e(waMoney((float)$b['amount'])) ?> / <?= e(waMoney((float)$b['amount_paid'])) ?></div>
      <div><b>STATUS</b><?= e(ucfirst($b['status'])) ?> · <?= e(waSourceLabel($b['source'])) ?></div>
    </div>
  </div>

  <?php if (!$isOta): ?>
  <div class="card">
    <h2>Send a message</h2>
    <?php
    $row = function (string $kind, string $title, string $hint, string $fields = '') use ($b) {
        $confirmText = "Send \"{$title}\" to the guest on WhatsApp?";
        echo '<div class="msg"><div class="what"><strong>' . e($title) . '</strong><span>' . $hint . '</span></div>'
           . '<form method="POST" onsubmit="return confirm(' . e(json_encode($confirmText)) . ')">' . csrfField()
           . '<input type="hidden" name="id" value="' . (int)$b['id'] . '"><input type="hidden" name="kind" value="' . e($kind) . '">'
           . $fields . '<button class="btn btn-primary" type="submit">Send</button></form></div>';
    };
    $row('confirmation', 'Booking confirmation', 'Room, dates, amount paid and the confirmation PDF.' . ($confirmSent ? ' <em>Already sent automatically.</em>' : ''));
    $row('updated', 'Booking updated', 'Current room, dates and total. Sent automatically when you change these in Edit.');
    $row('payment', 'Payment received', 'Sent automatically when you raise “amount paid” in Edit. Balance now: Rs. ' . e(waMoney($balance)) . '.',
        '<input name="amount" inputmode="decimal" placeholder="Amount Rs." required><select name="method"><option value="upi">UPI</option><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="online">Online payment</option></select>');
    $row('balance', 'Balance reminder', 'Balance due at check-in: Rs. ' . e(waMoney($balance)) . '. Sent automatically the day before check-in.');
    $row('checkin', 'Check-in reminder', 'Date, room, 3 PM check-in, photo ID and directions. Sent automatically the day before.');
    $row('cancelled', 'Booking cancelled', 'Tell the guest the booking is cancelled and the refund amount (0 if none).',
        '<input name="amount" inputmode="decimal" placeholder="Refund Rs." value="0">');
    $row('refund', 'Refund processed', 'After the refund is issued. Needs the amount and the reference.',
        '<input name="amount" inputmode="decimal" placeholder="Amount Rs." required><input name="reference" placeholder="Reference" required>');
    ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Sent for this booking</h2>
    <?php if (!$log && !$confirmSent): ?><p class="muted" style="margin:0">Nothing sent yet.</p><?php else: ?>
    <div class="tbl">
    <table>
      <thead><tr><th>Message</th><th>To</th><th>When (IST)</th></tr></thead>
      <tbody>
      <?php if ($confirmSent): ?><tr><td>Booking confirmation (automatic)</td><td>—</td><td><?= e(date('d M Y, g:i A', kfsDbTimestamp($b['guest_confirm_sent_at']) ?? time())) ?></td></tr><?php endif; ?>
      <?php foreach ($log as $l): ?>
        <tr><td><?= e($labels[$l['template']] ?? $l['template']) ?></td><td>+<?= e($l['recipient']) ?></td><td><?= e(date('d M Y, g:i A', kfsDbTimestamp($l['created_at']) ?? time())) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
