<?php
/**
 * Payments for one booking: the ledger, add a payment, record a refund, void a
 * mistaken entry.   booking-payments.php?id=N
 *
 * A payment sends the usual "payment received" WhatsApp (guest for our own
 * bookings, and the admins), exactly as raising amount paid in Edit does. A
 * refund can optionally send kfs_refund_processed; platform guests are refused
 * by waSend() whatever is ticked.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/accounts-service.php';
require_once __DIR__ . '/wa-templates.php';

startSecureSession();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }
require_once __DIR__ . '/auth.php';
requirePermission('payments.add');

function ph(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$b = $id ? getBookingById($id) : null;
if (!$b) { http_response_code(404); die('Booking not found.'); }
acctBackfillOpeningBalances($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken($_POST['csrf_token'] ?? null);
    $act = (string)($_POST['action'] ?? '');
    try {
        if (in_array($act, ['refund', 'void'], true) && !userCan('payments.refund')) throw new InvalidArgumentException('Your role cannot record refunds or void entries.');
        $before = getBookingById($id);
        if ($act === 'payment' || $act === 'refund') {
            acctRecord($id, $act, $_POST['amount'] ?? '', (string)($_POST['method'] ?? ''), (string)($_POST['reference'] ?? ''),
                (string)($_POST['paid_on'] ?? ''), (string)($_POST['note'] ?? ''));
            $after = getBookingById($id);
            kfsAudit($act === 'payment' ? 'payment_added' : 'refund_recorded', 'booking', $id, 'Rs. ' . ($_POST['amount'] ?? '') . ' ' . ($_POST['method'] ?? '') . ' ' . trim((string)($_POST['reference'] ?? '')));
            if ($act === 'payment') {
                waDefer(fn() => waOnBookingEdited($before, $after));
                $msg = 'Payment recorded.';
            } else {
                $msg = 'Refund recorded.';
                if (!empty($_POST['notify'])) {
                    $to = waGuestNumber($after);
                    $amount = rupeesToPaise($_POST['amount']) / 100;
                    $ref = trim((string)($_POST['reference'] ?? ''));
                    if ($to !== null) {
                        waDefer(fn() => waSend('kfs_refund_processed', $to, waRefundParams($after, $amount, $ref), null, $id, null));
                        $msg .= ' Refund WhatsApp is being sent to the guest.';
                    } else {
                        $msg .= ' No WhatsApp sent (platform booking, or no guest number).';
                    }
                }
            }
        } elseif ($act === 'void') {
            acctVoid((int)($_POST['entry_id'] ?? 0), (string)($_POST['reason'] ?? ''));
            kfsAudit('payment_voided', 'booking', $id, 'Entry ' . (int)($_POST['entry_id'] ?? 0) . ': ' . trim((string)($_POST['reason'] ?? '')));
            $msg = 'Entry voided.';
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        header('Location: booking-payments.php?id=' . $id . '&ok=' . rawurlencode($msg)); exit;
    } catch (InvalidArgumentException $e) {
        header('Location: booking-payments.php?id=' . $id . '&err=' . rawurlencode($e->getMessage())); exit;
    }
}

$b = getBookingById($id);
$ledger = acctLedger($id);
$net = acctNetPaise($id);
$total = (int)round((float)$b['amount'] * 100);
$balance = $total - $net;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Payments · Booking #<?= ph(waBookingNo($b)) ?> — Kanchi Farm Stay</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 14px; color: #111827; background: #f3f4f6; }
  a { color: #1a5c3a; }
  .wrap { max-width: 900px; margin: 0 auto; padding: 20px 16px 60px; }
  .top { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
  .top h1 { font-size: 20px; margin: 0 auto 0 0; color: #1a5c3a; }
  .btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; color: #111827; padding: 8px 14px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
  .btn-primary { background: #1a5c3a; border-color: #1a5c3a; color: #fff; }
  .btn-warn { color: #92400e; border-color: #fcd34d; }
  .btn-sm { padding: 4px 9px; font-size: 12px; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
  .card h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .6px; color: #1a5c3a; margin: 0 0 10px; }
  .sum { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
  .sum div b { display: block; font-size: 20px; font-weight: 800; }
  .sum div span { font-size: 12px; color: #6b7280; }
  .due b { color: #92400e; }
  .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
  .ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
  .err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .tbl { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 8px; border-top: 1px solid #f0f0f0; vertical-align: top; }
  th { font-size: 11px; color: #6b7280; text-transform: uppercase; border-top: 0; white-space: nowrap; }
  td.num { text-align: right; white-space: nowrap; font-weight: 600; }
  tr.voided td { color: #9ca3af; text-decoration: line-through; }
  tr.voided td.why { text-decoration: none; }
  .k-refund { color: #b91c1c; } .k-correction { color: #6b21a8; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px 12px; align-items: end; }
  label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 3px; }
  input, select { width: 100%; font: inherit; font-size: 14px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; }
  .check { display: flex; gap: 8px; align-items: center; font-size: 13px; }
  .check input { width: auto; }
  .muted { color: #6b7280; }
  details summary { cursor: pointer; color: #6b7280; font-size: 12px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>💰 Payments · #<?= ph(waBookingNo($b)) ?></h1>
    <a class="btn" href="admin.php?section=bookings">← Bookings</a>
    <a class="btn" href="bill.php?new=1&amp;booking=<?= (int)$b['id'] ?>" target="_blank">🧾 Bill</a>
  </div>
  <?php if (isset($_GET['ok'])): ?><div class="flash ok">✓ <?= ph($_GET['ok']) ?></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="flash err"><?= ph($_GET['err']) ?></div><?php endif; ?>

  <div class="card">
    <p style="margin:0 0 10px"><strong><?= ph($b['guest_name']) ?></strong> · <?= ph($b['room_name']) ?> · <?= ph(waDateRange($b['check_in'], $b['check_out'])) ?> · <?= ph(waSourceLabel($b['source'])) ?> · <?= ph(ucfirst($b['status'])) ?></p>
    <div class="sum">
      <div><b>Rs. <?= ph(waMoneyFromPaise($total)) ?></b><span>Booking total</span></div>
      <div><b>Rs. <?= ph(waMoneyFromPaise($net)) ?></b><span>Received (net of refunds)</span></div>
      <div class="<?= $balance > 0 ? 'due' : '' ?>"><b>Rs. <?= ph(waMoneyFromPaise(max(0, $balance))) ?></b><span><?= $balance < 0 ? 'Over-paid by Rs. ' . ph(waMoneyFromPaise(-$balance)) : 'Balance due' ?></span></div>
    </div>
  </div>

  <div class="card">
    <h2>Add a payment</h2>
    <form method="POST" class="grid">
      <?= csrfField() ?><input type="hidden" name="action" value="payment"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
      <div><label>Amount Rs. *</label><input name="amount" inputmode="decimal" required value="<?= $balance > 0 ? ph(waMoneyFromPaise($balance)) : '' ?>"></div>
      <div><label>Method *</label><select name="method"><?php foreach (ACCT_METHODS as $k => $l): ?><option value="<?= ph($k) ?>" <?= $k === 'upi' ? 'selected' : '' ?>><?= ph($l) ?></option><?php endforeach; ?></select></div>
      <div><label>Date *</label><input type="date" name="paid_on" value="<?= ph(date('Y-m-d')) ?>" max="<?= ph(date('Y-m-d')) ?>" required></div>
      <div><label>Reference (UPI / txn id)</label><input name="reference"></div>
      <div><label>Note</label><input name="note"></div>
      <div><button class="btn btn-primary" type="submit">Add payment</button></div>
    </form>
  </div>

  <?php if (userCan('payments.refund')): ?>
  <div class="card">
    <h2>Record a refund</h2>
    <form method="POST" class="grid" onsubmit="return confirm('Record this refund?')">
      <?= csrfField() ?><input type="hidden" name="action" value="refund"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
      <div><label>Refund Rs. *</label><input name="amount" inputmode="decimal" required></div>
      <div><label>Paid back by *</label><select name="method"><?php foreach (ACCT_METHODS as $k => $l): ?><option value="<?= ph($k) ?>" <?= $k === 'upi' ? 'selected' : '' ?>><?= ph($l) ?></option><?php endforeach; ?></select></div>
      <div><label>Date *</label><input type="date" name="paid_on" value="<?= ph(date('Y-m-d')) ?>" max="<?= ph(date('Y-m-d')) ?>" required></div>
      <div><label>Refund reference</label><input name="reference" placeholder="Razorpay refund id / UPI ref"></div>
      <div><label>Reason</label><input name="note" placeholder="e.g. cancelled 5 days before"></div>
      <div><label class="check"><input type="checkbox" name="notify" value="1" <?= isOtaSource($b['source']) ? 'disabled' : '' ?>> WhatsApp the guest</label>
        <?php if (isOtaSource($b['source'])): ?><span class="muted" style="font-size:11px">Off for platform bookings</span><?php endif; ?></div>
      <div><button class="btn btn-warn" type="submit">Record refund</button></div>
    </form>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>History</h2>
    <div class="tbl">
    <table>
      <thead><tr><th>Date</th><th>Type</th><th>Method</th><th style="text-align:right">Amount</th><th>Reference / note</th><th></th></tr></thead>
      <tbody>
      <?php if (!$ledger): ?><tr><td colspan="6" class="muted">No money recorded for this booking.</td></tr><?php endif; ?>
      <?php foreach ($ledger as $e): $void = (int)$e['voided'] === 1; $sign = $e['kind'] === 'refund' ? '−' : ((int)$e['amount_paise'] < 0 ? '−' : ''); ?>
        <tr class="<?= $void ? 'voided' : '' ?>">
          <td><?= ph(date('d M Y', strtotime($e['paid_on']))) ?></td>
          <td class="k-<?= ph($e['kind']) ?>"><?= ph(ACCT_KINDS[$e['kind']] ?? $e['kind']) ?></td>
          <td><?= ph(ACCT_METHODS[$e['method']] ?? $e['method']) ?></td>
          <td class="num"><?= $sign ?>Rs. <?= ph(waMoneyFromPaise(abs((int)$e['amount_paise']))) ?></td>
          <td><?= ph(trim($e['reference'] . ($e['reference'] !== '' && $e['note'] !== '' ? ' · ' : '') . $e['note'])) ?: '<span class="muted">—</span>' ?></td>
          <td class="why"><?php if ($void): ?><span class="muted" style="font-size:12px">Voided: <?= ph($e['void_reason']) ?></span>
            <?php elseif (userCan('payments.refund')): ?><details><summary>Void</summary>
              <form method="POST" style="display:flex;gap:6px;margin-top:6px"><?= csrfField() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="entry_id" value="<?= (int)$e['id'] ?>">
                <input name="reason" placeholder="Why?" required style="width:140px"><button class="btn btn-sm" type="submit">Void</button></form></details><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
</body>
</html>
