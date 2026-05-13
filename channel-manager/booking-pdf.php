<?php
/**
 * Booking Confirmation — Kanchi Farm Stay
 * Printable / "Save as PDF" booking confirmation.
 * Access: /channel-manager/booking-pdf.php?id=123
 * Requires admin session OR a signed token (?token=xxx).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

session_start();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); die('Missing booking ID.'); }

// Auth: admin session OR token param (HMAC-signed)
$isAdmin = !empty($_SESSION['admin_logged_in']);
$token   = $_GET['token'] ?? '';
$expected = hash_hmac('sha256', 'pdf-' . $id, ICAL_TOKEN);
$isToken = hash_equals($expected, $token);

if (!$isAdmin && !$isToken) {
    http_response_code(403);
    die('Access denied. Please open from the admin panel or use the link sent via WhatsApp.');
}

$b = getBookingById($id);
if (!$b) { http_response_code(404); die('Booking not found.'); }

$nights  = max(0, (int)ceil((strtotime($b['check_out']) - strtotime($b['check_in'])) / 86400));
$balance = max(0, $b['amount'] - $b['amount_paid']);
$paid    = (float)$b['amount_paid'];
$total   = (float)$b['amount'];

// Public signed URL (for WhatsApp link)
$pdfToken = hash_hmac('sha256', 'pdf-' . $id, ICAL_TOKEN);
$selfUrl  = SITE_URL . '/channel-manager/booking-pdf.php?id=' . $id . '&token=' . $pdfToken;

function fmtDate(string $d): string {
    return date('D, d M Y', strtotime($d));
}
function fmtRs(float $n): string {
    return '₹' . number_format($n, 0);
}
function pmLabel(string $pm): string {
    return match($pm) {
        'cash'          => 'Cash',
        'upi'           => 'UPI',
        'bank_transfer' => 'Bank Transfer',
        'online'        => 'Online / Card',
        default         => ucfirst($pm),
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Booking Confirmation #<?= $b['id'] ?> — Kanchi Farm Stay</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Inter', system-ui, sans-serif;
    font-size: 14px;
    color: #111827;
    background: #f3f4f6;
  }

  .page {
    max-width: 720px;
    margin: 24px auto;
    background: #fff;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 32px rgba(0,0,0,.12);
  }

  /* Header */
  .header {
    background: #1a5c3a;
    padding: 28px 36px 24px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
  }
  .header h1 { font-size: 20px; font-weight: 800; color: #fff; margin-bottom: 4px; }
  .header .sub { font-size: 12px; color: rgba(255,255,255,.7); }
  .header .date-col { text-align: right; }
  .header .date-lbl { font-size: 10px; color: rgba(255,255,255,.6); margin-bottom: 2px; }
  .header .date-val { font-size: 12px; color: #fff; }

  /* Amber strip */
  .title-strip {
    background: #f59e0b;
    padding: 10px 36px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .title-strip h2 { font-size: 14px; font-weight: 800; color: #1a2e1a; letter-spacing: .5px; }
  .booking-id {
    background: #1a2e1a;
    color: #fff;
    font-size: 13px;
    font-weight: 800;
    padding: 5px 14px;
    border-radius: 6px;
    letter-spacing: 1px;
  }

  /* Body */
  .body { padding: 24px 36px; }

  /* Stay highlight */
  .stay-row {
    background: #e8f5ee;
    border-radius: 10px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
  }
  .stay-date { text-align: center; }
  .stay-lbl { font-size: 10px; font-weight: 700; color: #5a7060; margin-bottom: 4px; letter-spacing: .5px; }
  .stay-val { font-size: 18px; font-weight: 800; color: #1a5c3a; }
  .stay-day { font-size: 11px; color: #5a7060; margin-top: 2px; }
  .nights-badge {
    background: #1a5c3a;
    color: #fff;
    border-radius: 50px;
    padding: 8px 18px;
    text-align: center;
  }
  .nights-num { font-size: 22px; font-weight: 800; line-height: 1; }
  .nights-lbl { font-size: 10px; opacity: .8; }

  /* Cards */
  .card { border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 16px; overflow: hidden; }
  .card-hd { background: #e8f5ee; padding: 8px 14px; border-bottom: 1px solid #e5e7eb; }
  .card-hd h3 { font-size: 10px; font-weight: 700; color: #1a5c3a; text-transform: uppercase; letter-spacing: .7px; }
  .card-bd { padding: 12px 14px; }

  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; }
  .grid-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 10px; }

  .field-lbl { font-size: 10px; font-weight: 600; color: #6b7280; margin-bottom: 3px; letter-spacing: .4px; }
  .field-val { font-size: 13px; font-weight: 600; color: #111827; }
  .field-val-sm { font-size: 12px; color: #374151; }

  /* Payment table */
  .pay-table { width: 100%; border-collapse: collapse; }
  .pay-table td { padding: 7px 12px; border-bottom: 1px solid #f3f4f6; font-size: 12.5px; }
  .pay-table td:last-child { text-align: right; font-weight: 600; }
  .pay-table tr:last-child td { border-bottom: none; }
  .pay-total td { background: #e8f5ee; font-weight: 700; color: #1a5c3a; font-size: 13px; }
  .pay-balance td { background: #fffbeb; font-weight: 700; color: #92400e; font-size: 13px; }
  .pay-paid td:last-child { color: #16a34a; }

  /* Bank details */
  .bank-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; }
  .upi-box { margin-top: 10px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 12px; display: flex; align-items: center; gap: 10px; }
  .upi-lbl { font-size: 11px; color: #6b7280; }
  .upi-val { font-size: 13px; font-weight: 700; color: #1a5c3a; }

  /* Policy */
  .policy-item { display: flex; gap: 8px; margin-bottom: 6px; font-size: 12px; color: #374151; line-height: 1.5; }
  .policy-bullet { color: #1a5c3a; flex-shrink: 0; }

  /* Thank you */
  .thankyou {
    background: #1a5c3a;
    margin: 0 36px 24px;
    border-radius: 10px;
    padding: 18px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
  }
  .thankyou h3 { font-size: 16px; font-weight: 800; color: #fff; margin-bottom: 4px; }
  .thankyou p { font-size: 11px; color: rgba(255,255,255,.75); }
  .contact-col { text-align: right; flex-shrink: 0; }
  .contact-lbl { font-size: 10px; color: rgba(255,255,255,.6); margin-bottom: 2px; }
  .contact-val { font-size: 12px; color: #fff; font-weight: 600; }

  /* Footer */
  .footer {
    border-top: 1px solid #e5e7eb;
    padding: 10px 36px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
    color: #9ca3af;
  }
  .footer strong { color: #1a5c3a; }

  /* Print actions (hidden when printing) */
  .print-bar {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    background: #1a2e1a;
    padding: 12px 24px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    box-shadow: 0 -4px 20px rgba(0,0,0,.2);
  }
  .print-bar button {
    background: #f59e0b;
    color: #1a2e1a;
    border: none;
    padding: 10px 22px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
  }
  .print-bar .close-btn {
    background: transparent;
    color: rgba(255,255,255,.6);
    border: 1px solid rgba(255,255,255,.2);
    font-weight: 500;
    font-size: 13px;
  }
  .print-bar p { color: rgba(255,255,255,.5); font-size: 12px; flex: 1; }

  @media print {
    body { background: #fff; }
    .page { max-width: 100%; margin: 0; border-radius: 0; box-shadow: none; }
    .print-bar { display: none; }
    .body { padding-bottom: 80px; }
  }
  @media (max-width: 600px) {
    .page { margin: 0; border-radius: 0; }
    .header, .body, .footer, .thankyou { padding-left: 18px; padding-right: 18px; }
    .title-strip { padding-left: 18px; padding-right: 18px; }
    .thankyou { margin-left: 18px; margin-right: 18px; }
    .grid-2, .grid-4 { grid-template-columns: 1fr 1fr; }
    .stay-val { font-size: 14px; }
  }
</style>
</head>
<body>

<div class="page">
  <!-- Header -->
  <div class="header">
    <div>
      <h1>🏡 Kanchi Farm Stay</h1>
      <div class="sub">Madikeri, Coorg, Karnataka</div>
    </div>
    <div class="date-col">
      <div class="date-lbl">ISSUED ON</div>
      <div class="date-val"><?= date('d M Y') ?></div>
    </div>
  </div>

  <!-- Title strip -->
  <div class="title-strip">
    <h2>BOOKING CONFIRMATION</h2>
    <div class="booking-id">#<?= str_pad($b['id'], 4, '0', STR_PAD_LEFT) ?></div>
  </div>

  <div class="body">
    <!-- Stay highlight -->
    <div class="stay-row">
      <div class="stay-date">
        <div class="stay-lbl">CHECK-IN</div>
        <div class="stay-val"><?= date('d M Y', strtotime($b['check_in'])) ?></div>
        <div class="stay-day"><?= date('l', strtotime($b['check_in'])) ?> · 3:00 PM</div>
      </div>
      <div class="nights-badge">
        <div class="nights-num"><?= $nights ?></div>
        <div class="nights-lbl"><?= $nights === 1 ? 'NIGHT' : 'NIGHTS' ?></div>
      </div>
      <div class="stay-date">
        <div class="stay-lbl">CHECK-OUT</div>
        <div class="stay-val"><?= date('d M Y', strtotime($b['check_out'])) ?></div>
        <div class="stay-day"><?= date('l', strtotime($b['check_out'])) ?> · 11:00 AM</div>
      </div>
    </div>

    <!-- Guest Details -->
    <div class="card">
      <div class="card-hd"><h3>Guest Details</h3></div>
      <div class="card-bd">
        <div class="grid-2">
          <div><div class="field-lbl">GUEST NAME</div><div class="field-val"><?= htmlspecialchars($b['guest_name']) ?></div></div>
          <div><div class="field-lbl">PHONE</div><div class="field-val"><?= htmlspecialchars($b['guest_phone'] ?: '—') ?></div></div>
          <?php if ($b['whatsapp_number'] && $b['whatsapp_number'] !== $b['guest_phone']): ?>
          <div><div class="field-lbl">WHATSAPP</div><div class="field-val"><?= htmlspecialchars($b['whatsapp_number']) ?></div></div>
          <?php endif; ?>
          <?php if ($b['guest_email']): ?>
          <div><div class="field-lbl">EMAIL</div><div class="field-val-sm"><?= htmlspecialchars($b['guest_email']) ?></div></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Room Details -->
    <div class="card">
      <div class="card-hd"><h3>Room Details</h3></div>
      <div class="card-bd">
        <div class="grid-2">
          <div><div class="field-lbl">ROOM / PROPERTY</div><div class="field-val"><?= htmlspecialchars($b['room_name']) ?></div></div>
          <div><div class="field-lbl">SOURCE</div><div class="field-val-sm"><?= htmlspecialchars(ucwords(str_replace(['.','_'], [' ', ' '], $b['source']))) ?></div></div>
          <div><div class="field-lbl">CHECK-IN TIME</div><div class="field-val-sm">3:00 PM</div></div>
          <div><div class="field-lbl">CHECK-OUT TIME</div><div class="field-val-sm">11:00 AM</div></div>
          <?php if ($b['booking_ref']): ?>
          <div style="grid-column:span 2"><div class="field-lbl">BOOKING REF</div><div class="field-val-sm"><?= htmlspecialchars($b['booking_ref']) ?></div></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Payment Summary -->
    <?php if ($total > 0): ?>
    <div class="card">
      <div class="card-hd"><h3>Payment Summary</h3></div>
      <div class="card-bd" style="padding:0">
        <table class="pay-table">
          <tr>
            <td>Room charges (<?= $nights ?> night<?= $nights !== 1 ? 's' : '' ?>)</td>
            <td><?= fmtRs($total) ?></td>
          </tr>
          <tr class="pay-total">
            <td>Total Amount</td>
            <td><?= fmtRs($total) ?></td>
          </tr>
          <?php if ($paid > 0): ?>
          <tr class="pay-paid">
            <td>Amount Paid (<?= pmLabel($b['payment_method'] ?: 'cash') ?>)</td>
            <td><?= fmtRs($paid) ?></td>
          </tr>
          <?php endif; ?>
          <?php if ($balance > 0): ?>
          <tr class="pay-balance">
            <td>Balance Due at Check-in</td>
            <td><?= fmtRs($balance) ?></td>
          </tr>
          <?php else: ?>
          <tr class="pay-total">
            <td>Payment Status</td>
            <td>✓ Fully Paid</td>
          </tr>
          <?php endif; ?>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Bank Details -->
    <?php if (BANK_ACCOUNT_NO || UPI_ID): ?>
    <div class="card">
      <div class="card-hd"><h3>Bank Transfer Details</h3></div>
      <div class="card-bd">
        <div class="bank-grid">
          <?php if (BANK_NAME): ?>
          <div><div class="field-lbl">BANK</div><div class="field-val-sm"><?= htmlspecialchars(BANK_NAME) ?></div></div>
          <?php endif; ?>
          <?php if (BANK_ACCOUNT_NAME): ?>
          <div><div class="field-lbl">ACCOUNT NAME</div><div class="field-val-sm"><?= htmlspecialchars(BANK_ACCOUNT_NAME) ?></div></div>
          <?php endif; ?>
          <?php if (BANK_ACCOUNT_NO): ?>
          <div><div class="field-lbl">ACCOUNT NUMBER</div><div class="field-val"><?= htmlspecialchars(BANK_ACCOUNT_NO) ?></div></div>
          <?php endif; ?>
          <?php if (BANK_IFSC): ?>
          <div><div class="field-lbl">IFSC CODE</div><div class="field-val"><?= htmlspecialchars(BANK_IFSC) ?></div></div>
          <?php endif; ?>
        </div>
        <?php if (UPI_ID): ?>
        <div class="upi-box">
          <div class="upi-lbl">UPI ID</div>
          <div class="upi-val"><?= htmlspecialchars(UPI_ID) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Notes -->
    <?php if (!empty($b['notes'])): ?>
    <div class="card">
      <div class="card-hd"><h3>Notes</h3></div>
      <div class="card-bd"><div class="field-val-sm"><?= nl2br(htmlspecialchars($b['notes'])) ?></div></div>
    </div>
    <?php endif; ?>

    <!-- Policies -->
    <div class="card">
      <div class="card-hd"><h3>Property Policies</h3></div>
      <div class="card-bd">
        <?php foreach ([
          'Check-in: 3:00 PM · Check-out: 11:00 AM (early/late subject to availability).',
          'Cancellations within 48 hours of check-in are non-refundable. 50% refund applies before that.',
          'Please carry a valid Government-issued photo ID for all adult guests.',
          'Pets, outside alcohol, and loud music after 10 PM are not permitted.',
          'Any property damage will be charged to the guest at actual cost.',
          'For assistance call/WhatsApp: ' . WHATSAPP_PHONE,
        ] as $policy): ?>
        <div class="policy-item"><span class="policy-bullet">•</span><span><?= htmlspecialchars($policy) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Thank you -->
  <div class="thankyou">
    <div>
      <h3>Thank you for choosing us! 🌿</h3>
      <p>We look forward to welcoming you. Have a wonderful stay at Kanchi Farm Stay.</p>
    </div>
    <div class="contact-col">
      <div class="contact-lbl">CONTACT US</div>
      <div class="contact-val">+<?= htmlspecialchars(WHATSAPP_PHONE) ?></div>
    </div>
  </div>

  <!-- Footer -->
  <div class="footer">
    <span>Computer-generated confirmation · No signature required · Booking #<?= str_pad($b['id'], 4, '0', STR_PAD_LEFT) ?></span>
    <strong>Kanchi Farm Stay</strong>
  </div>
</div>

<!-- Print / Download bar (hidden when printing) -->
<div class="print-bar">
  <p>Tip: In the print dialog, choose "Save as PDF" to download.</p>
  <button onclick="window.print()">🖨️ Save as PDF</button>
  <?php if ($isAdmin): ?>
  <button class="close-btn" onclick="window.close()">Close</button>
  <?php endif; ?>
</div>

</body>
</html>
