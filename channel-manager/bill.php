<?php
/**
 * Bill generator — GST tax invoices for Kanchi Farm Stay.
 *
 *   bill.php                    list of issued bills
 *   bill.php?new=1[&booking=N]  new bill, pre-filled from booking N
 *   bill.php?edit=ID            edit a bill (keeps its invoice number)
 *   bill.php?id=ID              printable invoice (admin, or ?token= for the guest)
 *   bill.php?profile=1          business details printed on every bill
 *
 * The arithmetic lives in bill-service.php. The JS preview on the form is a
 * convenience only; what is saved and printed is recomputed on the server.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/bill-service.php';

startSecureSession();
$isAdmin = !empty($_SESSION['admin_logged_in']);

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function paiseInput(int $p): string { return $p % 100 === 0 ? (string)intdiv($p, 100) : number_format($p / 100, 2, '.', ''); }
function fmtQty(float $q): string { return rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.'); }
const BILL_PAYMENT_METHODS = ['' => '—', 'cash' => 'Cash', 'upi' => 'UPI', 'bank_transfer' => 'Bank transfer', 'online' => 'Online / Card', 'ota' => 'Paid via OTA'];

// ── Guest view by signed token ───────────────────────────────
$viewId = (int)($_GET['id'] ?? 0);
if (!$isAdmin) {
    if (!$viewId) { header('Location: admin.php'); exit; }
    $token = (string)($_GET['token'] ?? '');
    $expected = billToken($viewId);
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        die('Access denied. Please open this bill from the admin panel or the link you were sent.');
    }
}

// ── POST actions (admin only) ────────────────────────────────
$error = '';
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken($_POST['csrf_token'] ?? null);
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'save_bill') {
            $editId = (int)($_POST['bill_id'] ?? 0) ?: null;
            $id = saveBill(billFromInput($_POST), $editId);
            header('Location: bill.php?id=' . $id . '&saved=1'); exit;
        }
        if ($act === 'cancel_bill') {
            cancelBill((int)$_POST['bill_id']);
            header('Location: bill.php?id=' . (int)$_POST['bill_id']); exit;
        }
        if ($act === 'send_whatsapp') {
            $id = (int)$_POST['bill_id'];
            $r = sendBillOnWhatsApp($id);
            header('Location: bill.php?id=' . $id . '&' . ($r['ok'] ? 'wa=sent' : 'wa_err=' . rawurlencode($r['message']))); exit;
        }
        if ($act === 'save_profile') {
            saveBillProfile($_POST);
            header('Location: bill.php?profile=1&saved=1'); exit;
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

$profile = billProfile();

// ── Decide the view ──────────────────────────────────────────
$mode = 'list';
$draft = null;
$billId = null;
if ($isAdmin && isset($_GET['profile'])) {
    $mode = 'profile';
} elseif ($isAdmin && ($_POST['action'] ?? '') === 'save_bill' && $error) {
    // Re-show the form with what was typed, not a blank one.
    $mode = 'form';
    $billId = (int)($_POST['bill_id'] ?? 0) ?: null;
    $draft = [
        'booking_id' => (int)($_POST['booking_id'] ?? 0) ?: null,
        'invoice_date' => (string)($_POST['invoice_date'] ?? date('Y-m-d')),
        'guest' => ['name' => $_POST['guest_name'] ?? '', 'phone' => $_POST['guest_phone'] ?? '', 'email' => $_POST['guest_email'] ?? '',
                    'address' => $_POST['guest_address'] ?? '', 'gstin' => $_POST['guest_gstin'] ?? ''],
        'stay' => ['room_name' => $_POST['room_name'] ?? '', 'check_in' => $_POST['check_in'] ?? '',
                   'check_out' => $_POST['check_out'] ?? '', 'ref' => $_POST['booking_ref'] ?? ''],
        'inclusive' => !empty($_POST['inclusive']),
        'items' => array_map(fn($r) => ['desc' => (string)($r['desc'] ?? ''), 'sac' => (string)($r['sac'] ?? ''), 'qty' => (string)($r['qty'] ?? '1'),
            'rate' => rupeesToPaise($r['rate'] ?? 0), 'gst' => (int)($r['gst'] ?? 5)], array_values((array)($_POST['items'] ?? []))),
        'paid' => rupeesToPaise($_POST['paid'] ?? 0),
        'payment_method' => (string)($_POST['payment_method'] ?? ''),
        'notes' => (string)($_POST['notes'] ?? ''),
    ];
} elseif ($isAdmin && isset($_GET['new'])) {
    $mode = 'form';
    $bookingId = (int)($_GET['booking'] ?? 0);
    $booking = $bookingId ? getBookingById($bookingId) : null;
    $draft = $booking ? billDraftFromBooking($booking) : emptyBillDraft();
} elseif ($isAdmin && isset($_GET['edit'])) {
    $existing = getBill((int)$_GET['edit']);
    if (!$existing) { http_response_code(404); die('Bill not found.'); }
    if ($existing['status'] === 'cancelled') { header('Location: bill.php?id=' . $existing['id']); exit; }
    $mode = 'form';
    $billId = (int)$existing['id'];
    $draft = $existing['data'];
} elseif ($viewId) {
    $mode = 'invoice';
}

if ($mode === 'invoice') {
    $row = getBill($viewId);
    if (!$row) { http_response_code(404); die('Bill not found.'); }
    $bill = $row['data'];
    $biz = ($bill['business'] ?? []) + $profile;
    $calc = computeBill($bill['items'], (bool)$bill['inclusive']);
    $balance = max(0, $calc['grand'] - (int)$bill['paid']);
    $guestUrl = billToken($viewId) === '' ? '' : SITE_URL . '/channel-manager/bill.php?id=' . $viewId . '&token=' . billToken($viewId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $mode === 'invoice' ? 'Invoice ' . h($row['invoice_no']) : 'Bills' ?> — <?= h($profile['trade_name']) ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 14px; color: #111827; background: #f3f4f6; }
  a { color: #1a5c3a; }
  .wrap { max-width: 1000px; margin: 0 auto; padding: 20px 16px 110px; }
  .topnav { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 18px; }
  .topnav h1 { font-size: 20px; margin: 0 auto 0 0; color: #1a5c3a; }
  .btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; color: #111827; padding: 8px 14px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; }
  .btn-primary { background: #1a5c3a; border-color: #1a5c3a; color: #fff; }
  .btn-amber { background: #f59e0b; border-color: #f59e0b; color: #1a2e1a; }
  .btn-danger { color: #b91c1c; border-color: #fca5a5; }
  .btn-sm { padding: 5px 10px; font-size: 12px; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; margin-bottom: 16px; }
  .card h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .6px; color: #1a5c3a; margin: 0 0 14px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 16px; }
  label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 4px; letter-spacing: .3px; }
  input, select, textarea { width: 100%; font: inherit; font-size: 14px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; }
  textarea { min-height: 64px; resize: vertical; }
  .span-2 { grid-column: span 2; }
  .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
  .flash-err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .flash-ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
  .muted { color: #6b7280; }
  .tbl-wrap { overflow-x: auto; }
  table.list { width: 100%; border-collapse: collapse; }
  table.list th, table.list td { padding: 9px 10px; border-bottom: 1px solid #f0f0f0; text-align: left; white-space: nowrap; }
  table.list th { font-size: 11px; text-transform: uppercase; color: #6b7280; }
  .pill { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 99px; background: #e8f5ee; color: #1a5c3a; }
  .pill-cancelled { background: #fee2e2; color: #991b1b; }
  /* Item editor */
  .items { width: 100%; border-collapse: collapse; min-width: 720px; }
  .items th { font-size: 11px; color: #6b7280; text-align: left; padding: 0 6px 6px; }
  .items td { padding: 4px 6px; vertical-align: top; }
  .items .c-desc { width: 38%; } .items .c-sac { width: 11%; } .items .c-qty { width: 8%; }
  .items .c-rate { width: 13%; } .items .c-gst { width: 10%; } .items .c-amt { width: 13%; text-align: right; font-weight: 600; padding-top: 12px; }
  .presets { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
  .preview { margin-top: 14px; margin-left: auto; max-width: 340px; }
  .preview div { display: flex; justify-content: space-between; padding: 3px 0; }
  .preview .grand { border-top: 2px solid #1a5c3a; margin-top: 4px; padding-top: 6px; font-weight: 800; font-size: 16px; color: #1a5c3a; }
  .check { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #111827; }
  .check input { width: auto; }
  .actionbar { position: fixed; left: 0; right: 0; bottom: 0; background: #1a2e1a; padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px)); display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; box-shadow: 0 -4px 20px rgba(0,0,0,.2); z-index: 10; }
  .actionbar .hint { color: rgba(255,255,255,.55); font-size: 12px; margin-right: auto; align-self: center; }

  /* ── Invoice ── */
  .invoice { position: relative; max-width: 820px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 32px rgba(0,0,0,.12); overflow: hidden; }
  .inv-head { display: flex; gap: 20px; align-items: center; padding: 24px 32px; border-bottom: 4px solid #1a5c3a; }
  .inv-logo { width: 92px; height: 92px; border-radius: 10px; object-fit: cover; flex-shrink: 0; }
  .inv-biz { flex: 1; min-width: 0; }
  .inv-biz .name { font-size: 22px; font-weight: 800; color: #1a5c3a; }
  .inv-biz .legal { font-size: 12px; color: #374151; }
  .inv-biz .line { font-size: 12px; color: #374151; margin-top: 2px; }
  .inv-biz .gst { margin-top: 6px; display: inline-block; font-size: 12px; font-weight: 700; background: #e8f5ee; color: #1a5c3a; padding: 3px 10px; border-radius: 6px; letter-spacing: .4px; }
  .inv-title { text-align: right; flex-shrink: 0; }
  .inv-title .t { font-size: 20px; font-weight: 800; letter-spacing: 1px; color: #1a2e1a; }
  .inv-title .meta { font-size: 12px; margin-top: 6px; line-height: 1.7; }
  .inv-title .meta b { color: #1a5c3a; }
  .inv-body { padding: 20px 32px; }
  .inv-parties { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px; }
  .party { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; }
  .party .lbl { font-size: 10px; font-weight: 700; color: #1a5c3a; text-transform: uppercase; letter-spacing: .7px; margin-bottom: 6px; }
  .party .v { font-size: 13px; line-height: 1.55; }
  .party .v b { font-size: 14px; }
  table.inv { width: 100%; border-collapse: collapse; font-size: 12px; }
  table.inv th { background: #1a5c3a; color: #fff; font-weight: 600; padding: 7px 6px; text-align: right; font-size: 11px; }
  table.inv th:nth-child(-n+3), table.inv td:nth-child(-n+3) { text-align: left; }
  table.inv td { padding: 7px 6px; border-bottom: 1px solid #eef0ee; text-align: right; vertical-align: top; }
  table.inv td.desc { white-space: normal; }
  .inv-totals { display: flex; gap: 20px; justify-content: space-between; margin-top: 14px; align-items: flex-start; }
  .words { flex: 1; font-size: 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 12px; }
  .words b { display: block; font-size: 10px; color: #6b7280; letter-spacing: .5px; text-transform: uppercase; margin-bottom: 3px; }
  .sum { width: 290px; font-size: 13px; }
  .sum div { display: flex; justify-content: space-between; padding: 4px 0; }
  .sum .grand { background: #1a5c3a; color: #fff; font-weight: 800; font-size: 15px; padding: 8px 10px; border-radius: 6px; margin: 4px 0; }
  .sum .bal { color: #92400e; font-weight: 700; }
  .sum .paid { color: #16a34a; font-weight: 600; }
  .taxsum { margin-top: 18px; }
  .taxsum h3, .inv-notes h3 { font-size: 10px; text-transform: uppercase; letter-spacing: .7px; color: #1a5c3a; margin: 0 0 6px; }
  .inv-notes { margin-top: 16px; font-size: 12px; color: #374151; line-height: 1.55; }
  .inv-sign { display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; margin-top: 26px; }
  .inv-sign .box { text-align: right; font-size: 12px; }
  .inv-sign .box .space { height: 44px; }
  .inv-foot { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; border-top: 1px solid #e5e7eb; padding: 10px 32px; font-size: 11px; color: #6b7280; }
  .stamp { position: absolute; top: 40%; left: 50%; transform: translate(-50%,-50%) rotate(-18deg); font-size: 72px; font-weight: 900; color: rgba(185,28,28,.16); border: 8px solid rgba(185,28,28,.16); padding: 4px 24px; border-radius: 16px; pointer-events: none; }

  @media (max-width: 640px) {
    .span-2 { grid-column: auto; }
    .inv-head { flex-wrap: wrap; padding: 18px; }
    .inv-title { text-align: left; width: 100%; }
    .inv-body { padding: 16px 18px; }
    .inv-parties { grid-template-columns: 1fr; }
    .inv-totals { flex-direction: column-reverse; }
    .sum { width: 100%; }
    .inv-foot { padding: 10px 18px; }
  }
  @media print {
    @page { size: A4; margin: 10mm; }
    body { background: #fff; }
    .wrap { padding: 0; max-width: none; }
    .actionbar, .no-print { display: none !important; }
    .invoice { box-shadow: none; border-radius: 0; max-width: none; }
    table.inv th, .sum .grand, .inv-biz .gst { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>
<div class="wrap">

<?php if ($mode === 'list'): $bills = listBills(); ?>
  <div class="topnav">
    <h1>🧾 Bills &amp; GST Invoices</h1>
    <a class="btn" href="admin.php?section=bookings">← Bookings</a>
    <a class="btn" href="bill.php?profile=1">⚙️ Business details</a>
    <a class="btn btn-primary" href="bill.php?new=1">+ New bill</a>
  </div>
  <?php if (billAddressNeedsPin($profile['address'])): ?>
  <div class="flash flash-err">The address on your bills has no PIN code. A GST invoice needs the full postal address — <a href="bill.php?profile=1">add the 6-digit PIN in Business details</a>.</div>
  <?php endif; ?>
  <div class="card">
    <p class="muted" style="margin-top:0">To bill a stay, open <a href="admin.php?section=bookings">Bookings</a> and press <b>🧾 Bill</b> on the row — guest, room, dates and amount are filled in for you. Use <b>+ New bill</b> for walk-ins, food or anything without a booking.</p>
    <?php if (!$bills): ?>
      <p class="muted">No bills yet.</p>
    <?php else: ?>
    <div class="tbl-wrap">
    <table class="list">
      <thead><tr><th>Invoice</th><th>Date</th><th>Guest</th><th>Booking</th><th style="text-align:right">Total</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($bills as $b): ?>
        <tr>
          <td><a href="bill.php?id=<?= (int)$b['id'] ?>"><b><?= h($b['invoice_no']) ?></b></a></td>
          <td><?= h(date('d M Y', strtotime($b['invoice_date']))) ?></td>
          <td><?= h($b['guest_name']) ?></td>
          <td class="muted"><?= $b['booking_id'] ? '#' . str_pad((string)$b['booking_id'], 4, '0', STR_PAD_LEFT) : '—' ?></td>
          <td style="text-align:right"><?= fmtPaise((int)$b['total_paise']) ?></td>
          <td><span class="pill <?= $b['status'] === 'cancelled' ? 'pill-cancelled' : '' ?>"><?= h(ucfirst($b['status'])) ?></span></td>
          <td>
            <a class="btn btn-sm" href="bill.php?id=<?= (int)$b['id'] ?>">View</a>
            <?php if ($b['status'] !== 'cancelled'): ?><a class="btn btn-sm" href="bill.php?edit=<?= (int)$b['id'] ?>">Edit</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

<?php elseif ($mode === 'profile'): ?>
  <div class="topnav">
    <h1>⚙️ Business details on bills</h1>
    <a class="btn" href="bill.php">← Bills</a>
  </div>
  <?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>
  <?php if (isset($_GET['saved'])): ?><div class="flash flash-ok">Saved. New bills use these details; bills already issued keep the details they were issued with.</div><?php endif; ?>
  <form method="POST" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_profile">
    <div style="display:flex;gap:14px;align-items:center;margin-bottom:16px">
      <img src="../assets/images/logo.png" alt="Logo" style="width:64px;height:64px;border-radius:8px;object-fit:cover">
      <span class="muted">The logo is the website logo (<code>assets/images/logo.png</code>).</span>
    </div>
    <div class="grid">
      <div><label>Trade name</label><input name="trade_name" value="<?= h($profile['trade_name']) ?>" required></div>
      <div><label>Legal name (as on GST certificate)</label><input name="legal_name" value="<?= h($profile['legal_name']) ?>" placeholder="Proprietor's name"></div>
      <div class="span-2"><label>Full postal address</label><textarea name="address" required><?= h($profile['address']) ?></textarea></div>
      <div><label>GSTIN</label><input name="gstin" value="<?= h($profile['gstin']) ?>" maxlength="15" style="text-transform:uppercase"></div>
      <div><label>State</label><input name="state" value="<?= h($profile['state']) ?>"></div>
      <div><label>State code</label><input name="state_code" value="<?= h($profile['state_code']) ?>" maxlength="2"></div>
      <div><label>Invoice prefix</label><input name="prefix" value="<?= h($profile['prefix']) ?>" maxlength="6"></div>
      <div><label>Phone 1</label><input name="phone1" value="<?= h($profile['phone1']) ?>"></div>
      <div><label>Phone 2</label><input name="phone2" value="<?= h($profile['phone2']) ?>"></div>
      <div><label>Email</label><input name="email" value="<?= h($profile['email']) ?>"></div>
      <div><label>Website</label><input name="website" value="<?= h($profile['website']) ?>"></div>
      <div class="span-2"><label>Footer message</label><input name="footer" value="<?= h($profile['footer']) ?>"></div>
    </div>
    <p style="margin-bottom:0"><button class="btn btn-primary" type="submit">Save details</button></p>
  </form>

<?php elseif ($mode === 'form'): ?>
  <div class="topnav">
    <h1><?= $billId ? '✏️ Edit bill' : '🧾 New bill' ?></h1>
    <a class="btn" href="bill.php">← Bills</a>
  </div>
  <?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>
  <form method="POST" id="billForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_bill">
    <input type="hidden" name="bill_id" value="<?= (int)$billId ?>">
    <input type="hidden" name="booking_id" value="<?= (int)($draft['booking_id'] ?? 0) ?>">

    <div class="card">
      <h2>Bill to</h2>
      <div class="grid">
        <div><label>Guest / customer name *</label><input name="guest_name" value="<?= h($draft['guest']['name']) ?>" required></div>
        <div><label>Phone</label><input name="guest_phone" value="<?= h($draft['guest']['phone']) ?>"></div>
        <div><label>Email</label><input name="guest_email" value="<?= h($draft['guest']['email']) ?>"></div>
        <div><label>Guest GSTIN (business guests only)</label><input name="guest_gstin" value="<?= h($draft['guest']['gstin']) ?>" maxlength="15" style="text-transform:uppercase"></div>
        <div class="span-2"><label>Address</label><input name="guest_address" value="<?= h($draft['guest']['address']) ?>"></div>
      </div>
    </div>

    <div class="card">
      <h2>Invoice &amp; stay</h2>
      <div class="grid">
        <div><label>Invoice date *</label><input type="date" name="invoice_date" value="<?= h($draft['invoice_date']) ?>" required></div>
        <div><label>Room / property</label><input name="room_name" value="<?= h($draft['stay']['room_name']) ?>"></div>
        <div><label>Check-in</label><input type="date" name="check_in" value="<?= h($draft['stay']['check_in']) ?>"></div>
        <div><label>Check-out</label><input type="date" name="check_out" value="<?= h($draft['stay']['check_out']) ?>"></div>
        <div><label>Booking ref</label><input name="booking_ref" value="<?= h($draft['stay']['ref']) ?>"></div>
      </div>
    </div>

    <div class="card">
      <h2>Items</h2>
      <label class="check" style="margin-bottom:12px"><input type="checkbox" name="inclusive" value="1" id="inclusive" <?= $draft['inclusive'] ? 'checked' : '' ?>> Rates already include GST (the guest pays exactly the rate)</label>
      <div class="tbl-wrap">
      <table class="items">
        <thead><tr><th class="c-desc">Description</th><th class="c-sac">SAC</th><th class="c-qty">Qty</th><th class="c-rate">Rate ₹</th><th class="c-gst">GST</th><th class="c-amt">Amount</th><th></th></tr></thead>
        <tbody id="itemRows"></tbody>
      </table>
      </div>
      <div class="presets">
        <?php foreach (BILL_ITEM_PRESETS as $key => $p): ?>
        <button type="button" class="btn btn-sm" data-preset="<?= h($key) ?>">+ <?= h($p['label']) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="preview" id="preview"></div>
      <p class="muted" style="font-size:12px;margin-bottom:0">Default rates: rooms up to ₹7,500 a night before tax 5%, above that 18%; food 5%. Confirm with your accountant — every line can be changed.</p>
    </div>

    <div class="card">
      <h2>Payment &amp; notes</h2>
      <div class="grid">
        <div><label>Amount received ₹</label><input name="paid" id="paid" inputmode="decimal" value="<?= (int)$draft['paid'] > 0 ? h(paiseInput((int)$draft['paid'])) : '' ?>" placeholder="0"></div>
        <div><label>Payment method</label>
          <select name="payment_method">
            <?php foreach (BILL_PAYMENT_METHODS as $v => $l): ?>
            <option value="<?= h($v) ?>" <?= $draft['payment_method'] === $v ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="span-2"><label>Notes printed on the bill</label><textarea name="notes"><?= h($draft['notes']) ?></textarea></div>
      </div>
    </div>

    <div class="actionbar">
      <span class="hint"><?= $billId ? 'Saving keeps the same invoice number.' : 'Saving issues the next invoice number.' ?></span>
      <a class="btn" href="bill.php">Cancel</a>
      <button class="btn btn-amber" type="submit"><?= $billId ? 'Save changes' : 'Save &amp; issue bill' ?></button>
    </div>
  </form>
  <script>
  (function () {
    var PRESETS = <?= json_encode(BILL_ITEM_PRESETS, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var RATES = <?= json_encode(BILL_GST_RATES) ?>;
    var initial = <?= json_encode(array_map(fn($i) => ['desc' => (string)$i['desc'], 'sac' => (string)$i['sac'], 'qty' => (string)$i['qty'],
        'rate' => paiseInput((int)$i['rate']), 'gst' => (int)$i['gst']], $draft['items']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var body = document.getElementById('itemRows');
    var n = 0;

    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function toPaise(v) { var x = parseFloat(String(v).replace(/[,₹\s]/g, '')); return isNaN(x) ? 0 : Math.round(x * 100); }
    function rs(p) { return '₹' + (p / 100).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

    function addRow(item) {
      var i = n++;
      var opts = RATES.map(function (r) { return '<option value="' + r + '"' + (r === item.gst ? ' selected' : '') + '>' + r + '%</option>'; }).join('');
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td><input name="items[' + i + '][desc]" value="' + esc(item.desc) + '" placeholder="Description"></td>' +
        '<td><input name="items[' + i + '][sac]" value="' + esc(item.sac) + '" inputmode="numeric"></td>' +
        '<td><input name="items[' + i + '][qty]" value="' + esc(item.qty) + '" inputmode="decimal"></td>' +
        '<td><input name="items[' + i + '][rate]" value="' + esc(item.rate) + '" inputmode="decimal"></td>' +
        '<td><select name="items[' + i + '][gst]">' + opts + '</select></td>' +
        '<td class="c-amt" data-amt>—</td>' +
        '<td><button type="button" class="btn btn-sm btn-danger" title="Remove line" data-remove>✕</button></td>';
      body.appendChild(tr);
      recalc();
    }

    // Mirrors computeBill() in bill-service.php. Preview only.
    function recalc() {
      var inclusive = document.getElementById('inclusive').checked;
      var taxable = 0, cgst = 0, sgst = 0;
      Array.prototype.forEach.call(body.rows, function (tr) {
        var f = tr.querySelectorAll('input,select');
        var qty = parseFloat(f[2].value) || 0, rate = toPaise(f[3].value), r = parseInt(f[4].value, 10) || 0;
        var gross = Math.round(qty * rate), t, tax;
        if (inclusive) { t = Math.round(gross * 100 / (100 + r)); tax = gross - t; }
        else { t = gross; tax = Math.round(gross * r / 100); }
        var c = Math.floor(tax / 2);
        taxable += t; cgst += c; sgst += tax - c;
        tr.querySelector('[data-amt]').textContent = rs(t + tax);
      });
      var exact = taxable + cgst + sgst, grand = Math.round(exact / 100) * 100;
      var paid = toPaise(document.getElementById('paid').value);
      var html = '<div><span>Taxable value</span><span>' + rs(taxable) + '</span></div>' +
        '<div><span>CGST</span><span>' + rs(cgst) + '</span></div>' +
        '<div><span>SGST</span><span>' + rs(sgst) + '</span></div>';
      if (grand !== exact) html += '<div><span>Round off</span><span>' + rs(grand - exact) + '</span></div>';
      html += '<div class="grand"><span>Total</span><span>' + rs(grand) + '</span></div>';
      if (paid > 0) html += '<div><span>Received</span><span>' + rs(paid) + '</span></div><div><span>Balance</span><span>' + rs(Math.max(0, grand - paid)) + '</span></div>';
      document.getElementById('preview').innerHTML = html;
    }

    body.addEventListener('input', recalc);
    body.addEventListener('change', recalc);
    body.addEventListener('click', function (e) {
      if (e.target.hasAttribute('data-remove')) { e.target.closest('tr').remove(); recalc(); }
    });
    document.getElementById('inclusive').addEventListener('change', recalc);
    document.getElementById('paid').addEventListener('input', recalc);
    document.querySelectorAll('[data-preset]').forEach(function (b) {
      b.addEventListener('click', function () {
        var p = PRESETS[b.getAttribute('data-preset')];
        addRow({desc: p.label, sac: p.sac, qty: 1, rate: '', gst: p.gst});
      });
    });

    if (initial.length) initial.forEach(addRow);
    else addRow({desc: PRESETS.room.label, sac: PRESETS.room.sac, qty: 1, rate: '', gst: PRESETS.room.gst});
  })();
  </script>

<?php else: /* invoice */ ?>
  <?php if (isset($_GET['saved'])): ?><div class="flash flash-ok no-print">Bill <?= h($row['invoice_no']) ?> saved.</div><?php endif; ?>
  <?php if ($isAdmin && isset($_GET['wa_err'])): ?><div class="flash flash-err no-print"><?= h($_GET['wa_err']) ?></div><?php endif; ?>
  <?php if ($isAdmin && ($row['wa_sent_at'] ?? '') !== ''): ?><div class="flash flash-ok no-print">💬 <?= isset($_GET['wa']) ? 'Sent' : 'Last sent' ?> on WhatsApp to +<?= h($row['wa_sent_to']) ?> on <?= h(date('d M Y, g:i A', (kfsDbTimestamp($row['wa_sent_at']) ?? time()))) ?>.</div><?php endif; ?>
  <div class="invoice">
    <?php if ($row['status'] === 'cancelled'): ?><div class="stamp">CANCELLED</div><?php endif; ?>
    <div class="inv-head">
      <img class="inv-logo" src="../assets/images/logo.png" alt="<?= h($biz['trade_name']) ?> logo">
      <div class="inv-biz">
        <div class="name"><?= h($biz['trade_name']) ?></div>
        <?php if ($biz['legal_name'] !== ''): ?><div class="legal"><?= h($biz['legal_name']) ?></div><?php endif; ?>
        <div class="line"><?= nl2br(h($biz['address'])) ?></div>
        <div class="line">📞 <?= h(implode(' · ', array_filter([$biz['phone1'], $biz['phone2']]))) ?></div>
        <div class="line"><?= h(implode(' · ', array_filter([$biz['email'], $biz['website']]))) ?></div>
        <?php if ($biz['gstin'] !== ''): ?><div class="gst">GSTIN <?= h($biz['gstin']) ?></div><?php endif; ?>
      </div>
      <div class="inv-title">
        <div class="t"><?= $biz['gstin'] !== '' ? 'TAX INVOICE' : 'INVOICE' ?></div>
        <div class="meta">
          Invoice No: <b><?= h($row['invoice_no']) ?></b><br>
          Date: <b><?= h(date('d M Y', strtotime($row['invoice_date']))) ?></b>
        </div>
      </div>
    </div>

    <div class="inv-body">
      <div class="inv-parties">
        <div class="party">
          <div class="lbl">Bill to</div>
          <div class="v">
            <b><?= h($bill['guest']['name']) ?></b><br>
            <?php if ($bill['guest']['address'] !== ''): ?><?= h($bill['guest']['address']) ?><br><?php endif; ?>
            <?php if ($bill['guest']['phone'] !== ''): ?>📞 <?= h($bill['guest']['phone']) ?><br><?php endif; ?>
            <?php if ($bill['guest']['email'] !== ''): ?><?= h($bill['guest']['email']) ?><br><?php endif; ?>
            <?php if ($bill['guest']['gstin'] !== ''): ?>GSTIN: <b style="font-size:13px"><?= h($bill['guest']['gstin']) ?></b><?php endif; ?>
          </div>
        </div>
        <div class="party">
          <div class="lbl">Stay details</div>
          <div class="v">
            <?php if ($bill['stay']['room_name'] !== ''): ?><b><?= h($bill['stay']['room_name']) ?></b><br><?php endif; ?>
            <?php if ($bill['stay']['check_in'] !== ''): ?>Check-in: <?= h(date('d M Y', strtotime($bill['stay']['check_in']))) ?><br><?php endif; ?>
            <?php if ($bill['stay']['check_out'] !== ''): ?>Check-out: <?= h(date('d M Y', strtotime($bill['stay']['check_out']))) ?><br><?php endif; ?>
            <?php if (!empty($bill['booking_id'])): ?>Booking #<?= str_pad((string)$bill['booking_id'], 4, '0', STR_PAD_LEFT) ?><?= $bill['stay']['ref'] !== '' ? ' · Ref ' . h($bill['stay']['ref']) : '' ?><br>
            <?php elseif ($bill['stay']['ref'] !== ''): ?>Ref: <?= h($bill['stay']['ref']) ?><br><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="tbl-wrap">
      <table class="inv">
        <thead><tr><th>#</th><th>Description</th><th>SAC</th><th>Qty</th><th>Rate</th><th>Taxable</th><th>GST</th><th>CGST</th><th>SGST</th><th>Amount</th></tr></thead>
        <tbody>
        <?php foreach ($calc['lines'] as $i => $l): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td class="desc"><?= h($l['desc']) ?></td>
            <td><?= h($l['sac']) ?></td>
            <td><?= h(fmtQty((float)$l['qty'])) ?></td>
            <td><?= fmtPaise((int)$l['rate'], false) ?></td>
            <td><?= fmtPaise($l['taxable'], false) ?></td>
            <td><?= (int)$l['gst'] ?>%</td>
            <td><?= fmtPaise($l['cgst'], false) ?></td>
            <td><?= fmtPaise($l['sgst'], false) ?></td>
            <td><b><?= fmtPaise($l['total'], false) ?></b></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <div class="inv-totals">
        <div class="words"><b>Amount in words</b><?= h(amountInWords($calc['grand'])) ?>
          <?php if ($bill['inclusive']): ?><div class="muted" style="margin-top:6px">Rates are inclusive of GST.</div><?php endif; ?>
        </div>
        <div class="sum">
          <div><span>Taxable value</span><span><?= fmtPaise($calc['taxable']) ?></span></div>
          <div><span>CGST</span><span><?= fmtPaise($calc['cgst']) ?></span></div>
          <div><span>SGST</span><span><?= fmtPaise($calc['sgst']) ?></span></div>
          <?php if ($calc['round_off'] !== 0): ?><div><span>Round off</span><span><?= fmtPaise($calc['round_off']) ?></span></div><?php endif; ?>
          <div class="grand"><span>Total</span><span><?= fmtPaise($calc['grand']) ?></span></div>
          <?php if ((int)$bill['paid'] > 0): ?>
          <div class="paid"><span>Received<?= $bill['payment_method'] !== '' ? ' (' . h(BILL_PAYMENT_METHODS[$bill['payment_method']] ?? $bill['payment_method']) . ')' : '' ?></span><span><?= fmtPaise((int)$bill['paid']) ?></span></div>
          <div class="<?= $balance > 0 ? 'bal' : 'paid' ?>"><span>Balance due</span><span><?= $balance > 0 ? fmtPaise($balance) : '✓ Nil' ?></span></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="taxsum tbl-wrap">
        <h3>Tax summary</h3>
        <table class="inv">
          <thead><tr><th>SAC</th><th>GST rate</th><th></th><th>Taxable value</th><th>CGST</th><th>SGST</th><th>Total tax</th></tr></thead>
          <tbody>
          <?php foreach ($calc['tax_summary'] as $s): $half = rtrim(rtrim(number_format($s['gst'] / 2, 1), '0'), '.'); ?>
            <tr>
              <td><?= h($s['sac'] ?: '—') ?></td>
              <td><?= (int)$s['gst'] ?>% (<?= $half ?>% + <?= $half ?>%)</td>
              <td></td>
              <td><?= fmtPaise($s['taxable'], false) ?></td>
              <td><?= fmtPaise($s['cgst'], false) ?></td>
              <td><?= fmtPaise($s['sgst'], false) ?></td>
              <td><?= fmtPaise($s['cgst'] + $s['sgst'], false) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if (($bill['notes'] ?? '') !== ''): ?>
      <div class="inv-notes"><h3>Notes</h3><?= nl2br(h($bill['notes'])) ?></div>
      <?php endif; ?>

      <div class="inv-sign">
        <div class="muted" style="font-size:12px"><?= h($biz['footer']) ?></div>
        <div class="box">
          For <b><?= h($biz['trade_name']) ?></b>
          <div class="space"></div>
          Authorised signatory
        </div>
      </div>
    </div>
    <div class="inv-foot">
      <span>This is a computer-generated invoice.</span>
      <span><?= h($biz['trade_name']) ?><?= $biz['gstin'] !== '' ? ' · GSTIN ' . h($biz['gstin']) : '' ?> · <?= h($biz['phone1']) ?></span>
    </div>
  </div>

  <div class="actionbar">
    <span class="hint">In the print dialog choose “Save as PDF” to download.</span>
    <?php if ($isAdmin): ?>
      <a class="btn" href="bill.php">← Bills</a>
      <?php if ($row['status'] !== 'cancelled'): ?>
        <a class="btn" href="bill.php?edit=<?= (int)$row['id'] ?>">✏️ Edit</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Cancel invoice <?= h($row['invoice_no']) ?>? The number stays used and the bill is marked CANCELLED.')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="cancel_bill">
          <input type="hidden" name="bill_id" value="<?= (int)$row['id'] ?>">
          <button class="btn btn-danger" type="submit">Cancel invoice</button>
        </form>
        <?php if ($guestUrl !== ''):
          $gPhone = preg_replace('/\D/', '', $bill['guest']['phone']);
          if (strlen($gPhone) === 10) $gPhone = '91' . $gPhone;
          $waText = 'Hello ' . $bill['guest']['name'] . ', here is your invoice ' . $row['invoice_no'] . ' from ' . $biz['trade_name'] . ': ' . $guestUrl; ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('Send invoice <?= h($row['invoice_no']) ?> on WhatsApp to <?= h($bill['guest']['phone'] ?: 'the guest') ?>?<?= ($row['wa_sent_at'] ?? '') !== '' ? ' It was already sent once.' : '' ?>')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send_whatsapp">
            <input type="hidden" name="bill_id" value="<?= (int)$row['id'] ?>">
            <button class="btn" type="submit" title="Send the approved invoice template from the property's WhatsApp number">💬 Send on WhatsApp</button>
          </form>
          <a class="btn" target="_blank" rel="noopener" href="https://wa.me/<?= h($gPhone) ?>?text=<?= h(rawurlencode($waText)) ?>" title="Open a chat on this device with the link typed in">↗ Open chat</a>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
    <button class="btn btn-amber" type="button" onclick="window.print()">🖨️ Print / Save PDF</button>
  </div>
<?php endif; ?>

</div>
</body>
</html>
