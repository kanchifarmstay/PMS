<?php
/**
 * Accounts - collections, refunds and the monthly GST report.
 *
 *   accounts.php?view=collections&from=&to=[&kind=refund]   money in/out by method
 *   accounts.php?view=gst&month=YYYY-MM                     invoices + tax by rate
 *   &export=csv on either view                              the same rows for the accountant
 *
 * Read-only. The numbers come from accounts-service.php.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/accounts-service.php';
require_once __DIR__ . '/wa-templates.php';

startSecureSession();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }
require_once __DIR__ . '/auth.php';
requirePermission('accounts');

function ah(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function arupee(int $paise): string { return fmtPaise($paise); }

acctBackfillOpeningBalances();
$view = ($_GET['view'] ?? 'collections') === 'gst' ? 'gst' : 'collections';
$isDate = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) === 1;
$from = $isDate($_GET['from'] ?? '') ? (string)$_GET['from'] : date('Y-m-01');
$to = $isDate($_GET['to'] ?? '') ? (string)$_GET['to'] : date('Y-m-d');
$kind = in_array($_GET['kind'] ?? '', ['payment', 'refund', 'correction'], true) ? (string)$_GET['kind'] : '';
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? (string)$_GET['month'] : date('Y-m');
$csv = ($_GET['export'] ?? '') === 'csv';

if ($view === 'collections') {
    $c = acctCollections($from, $to);
    $rows = $kind === '' ? $c['rows'] : array_values(array_filter($c['rows'], fn($r) => $r['kind'] === $kind));
    if ($csv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="collections-' . $from . '-to-' . $to . ($kind ? '-' . $kind : '') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Booking', 'Guest', 'Room', 'Source', 'Type', 'Method', 'Amount', 'Reference', 'Note']);
        foreach ($rows as $r) {
            $amt = (int)$r['amount_paise'] * ($r['kind'] === 'refund' ? -1 : 1);
            fputcsv($out, [$r['paid_on'], '#' . str_pad((string)$r['booking_id'], 4, '0', STR_PAD_LEFT), $r['guest_name'], $r['room_name'],
                waSourceLabel((string)$r['source']), ACCT_KINDS[$r['kind']] ?? $r['kind'], ACCT_METHODS[$r['method']] ?? $r['method'],
                number_format($amt / 100, 2, '.', ''), $r['reference'], $r['note']]);
        }
        exit;
    }
} else {
    $g = acctGstReport($month);
    if ($csv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="gst-invoices-' . $month . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Invoice no', 'Invoice date', 'Customer', 'Customer GSTIN', 'Place of supply', 'GST rates', 'Taxable value', 'CGST', 'SGST', 'Invoice total', 'Status']);
        $biz = billProfile();
        foreach ($g['invoices'] as $i) {
            fputcsv($out, [$i['invoice_no'], $i['date'], $i['customer'], $i['gstin'], $biz['state_code'] . '-' . $biz['state'], $i['rates'],
                number_format($i['taxable'] / 100, 2, '.', ''), number_format($i['cgst'] / 100, 2, '.', ''), number_format($i['sgst'] / 100, 2, '.', ''),
                number_format($i['total'] / 100, 2, '.', ''), $i['status']]);
        }
        exit;
    }
}
$qs = fn(array $over) => 'accounts.php?' . http_build_query(array_filter(array_merge(['view' => $view, 'from' => $from, 'to' => $to, 'kind' => $kind, 'month' => $month], $over), fn($v) => $v !== '' && $v !== null));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Accounts — Kanchi Farm Stay</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 14px; color: #111827; background: #f3f4f6; }
  a { color: #1a5c3a; }
  .wrap { max-width: 1100px; margin: 0 auto; padding: 20px 16px 60px; }
  .top { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
  .top h1 { font-size: 20px; margin: 0 auto 0 0; color: #1a5c3a; }
  .btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; color: #111827; padding: 8px 14px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
  .btn-primary, .tab.on { background: #1a5c3a; border-color: #1a5c3a; color: #fff; }
  .tabs { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
  .card h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .6px; color: #1a5c3a; margin: 0 0 10px; }
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 14px; }
  .stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 14px; }
  .stat b { display: block; font-size: 20px; font-weight: 800; }
  .stat span { font-size: 12px; color: #6b7280; }
  .neg b { color: #b91c1c; }
  .filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
  label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 3px; }
  input, select { font: inherit; font-size: 13px; padding: 7px 9px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; }
  .tbl { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 8px; border-top: 1px solid #f0f0f0; white-space: nowrap; }
  th { font-size: 11px; color: #6b7280; text-transform: uppercase; border-top: 0; }
  td.num, th.num { text-align: right; }
  tfoot td { font-weight: 800; border-top: 2px solid #1a5c3a; }
  tr.cancelled td { color: #9ca3af; text-decoration: line-through; }
  .muted { color: #6b7280; }
  .note { font-size: 12px; color: #6b7280; margin: 10px 0 0; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>📒 Accounts</h1>
    <a class="btn" href="admin.php">← Dashboard</a>
  </div>
  <div class="tabs">
    <a class="btn tab <?= $view === 'collections' ? 'on' : '' ?>" href="accounts.php?view=collections">Collections &amp; refunds</a>
    <a class="btn tab <?= $view === 'gst' ? 'on' : '' ?>" href="accounts.php?view=gst">GST report</a>
  </div>

<?php if ($view === 'collections'): ?>
  <div class="card">
    <form class="filters" method="GET">
      <input type="hidden" name="view" value="collections">
      <div><label>From</label><input type="date" name="from" value="<?= ah($from) ?>"></div>
      <div><label>To</label><input type="date" name="to" value="<?= ah($to) ?>"></div>
      <div><label>Show</label><select name="kind"><option value="">Everything</option><?php foreach (ACCT_KINDS as $k => $l): ?><option value="<?= ah($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= ah($l) ?>s</option><?php endforeach; ?></select></div>
      <div><button class="btn btn-primary" type="submit">Show</button></div>
      <div><a class="btn" href="<?= ah($qs(['export' => 'csv'])) ?>">⬇ CSV</a></div>
    </form>
  </div>
  <div class="stats">
    <div class="stat"><b><?= ah(arupee($c['received'])) ?></b><span>Received</span></div>
    <div class="stat neg"><b><?= ah(arupee($c['refunded'])) ?></b><span>Refunded</span></div>
    <?php if ($c['corrections'] !== 0): ?><div class="stat"><b><?= ah(arupee($c['corrections'])) ?></b><span>Corrections</span></div><?php endif; ?>
    <div class="stat"><b><?= ah(arupee($c['net'])) ?></b><span>Net collected</span></div>
    <?php foreach ($c['by_method'] as $m => $amt): ?><div class="stat"><b><?= ah(arupee($amt)) ?></b><span><?= ah(ACCT_METHODS[$m] ?? $m) ?> (net)</span></div><?php endforeach; ?>
  </div>
  <div class="card">
    <div class="tbl">
    <table>
      <thead><tr><th>Date</th><th>Booking</th><th>Guest</th><th>Type</th><th>Method</th><th class="num">Amount</th><th>Reference / note</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted">Nothing recorded in these dates.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $amt = (int)$r['amount_paise'] * ($r['kind'] === 'refund' ? -1 : 1); ?>
        <tr>
          <td><?= ah(date('d M Y', strtotime($r['paid_on']))) ?></td>
          <td><a href="booking-payments.php?id=<?= (int)$r['booking_id'] ?>">#<?= ah(str_pad((string)$r['booking_id'], 4, '0', STR_PAD_LEFT)) ?></a></td>
          <td><?= ah($r['guest_name']) ?><div class="muted" style="font-size:12px"><?= ah($r['room_name']) ?></div></td>
          <td><?= ah(ACCT_KINDS[$r['kind']] ?? $r['kind']) ?></td>
          <td><?= ah(ACCT_METHODS[$r['method']] ?? $r['method']) ?></td>
          <td class="num" style="<?= $amt < 0 ? 'color:#b91c1c' : '' ?>"><?= ah(arupee($amt)) ?></td>
          <td style="white-space:normal"><?= ah(trim($r['reference'] . ($r['reference'] !== '' && $r['note'] !== '' ? ' · ' : '') . $r['note'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="note">Voided entries are excluded. “Collected by OTA” is money the platform took and pays out to you, not cash in hand.</p>
  </div>

<?php else: $t = $g['totals']; ?>
  <div class="card">
    <form class="filters" method="GET">
      <input type="hidden" name="view" value="gst">
      <div><label>Month</label><input type="month" name="month" value="<?= ah($month) ?>"></div>
      <div><button class="btn btn-primary" type="submit">Show</button></div>
      <div><a class="btn" href="<?= ah($qs(['export' => 'csv'])) ?>">⬇ CSV for accountant</a></div>
    </form>
  </div>
  <div class="stats">
    <div class="stat"><b><?= (int)$t['count'] ?></b><span>Invoices issued<?= $t['cancelled'] ? ' · ' . (int)$t['cancelled'] . ' cancelled' : '' ?></span></div>
    <div class="stat"><b><?= ah(arupee($t['taxable'])) ?></b><span>Taxable value</span></div>
    <div class="stat"><b><?= ah(arupee($t['cgst'])) ?></b><span>CGST</span></div>
    <div class="stat"><b><?= ah(arupee($t['sgst'])) ?></b><span>SGST</span></div>
    <div class="stat"><b><?= ah(arupee($t['total'])) ?></b><span>Invoice value</span></div>
  </div>
  <div class="card">
    <h2>Tax by rate</h2>
    <div class="tbl">
    <table>
      <thead><tr><th>GST rate</th><th>SAC</th><th class="num">Taxable value</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">Total tax</th></tr></thead>
      <tbody>
      <?php if (!$g['by_rate']): ?><tr><td colspan="6" class="muted">No invoices this month.</td></tr><?php endif; ?>
      <?php foreach ($g['by_rate'] as $r): ?>
        <tr><td><?= (int)$r['gst'] ?>%</td><td><?= ah($r['sac'] ?: '—') ?></td><td class="num"><?= ah(arupee($r['taxable'])) ?></td><td class="num"><?= ah(arupee($r['cgst'])) ?></td><td class="num"><?= ah(arupee($r['sgst'])) ?></td><td class="num"><?= ah(arupee($r['cgst'] + $r['sgst'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($g['by_rate']): ?><tfoot><tr><td colspan="2">Total</td><td class="num"><?= ah(arupee($t['taxable'])) ?></td><td class="num"><?= ah(arupee($t['cgst'])) ?></td><td class="num"><?= ah(arupee($t['sgst'])) ?></td><td class="num"><?= ah(arupee($t['cgst'] + $t['sgst'])) ?></td></tr></tfoot><?php endif; ?>
    </table>
    </div>
  </div>
  <div class="card">
    <h2>Invoices</h2>
    <div class="tbl">
    <table>
      <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>GSTIN</th><th class="num">Taxable</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">Total</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$g['invoices']): ?><tr><td colspan="9" class="muted">No invoices this month.</td></tr><?php endif; ?>
      <?php foreach ($g['invoices'] as $i): ?>
        <tr class="<?= $i['status'] === 'cancelled' ? 'cancelled' : '' ?>">
          <td><a href="bill.php?id=<?= (int)$i['id'] ?>"><?= ah($i['invoice_no']) ?></a></td><td><?= ah(date('d M Y', strtotime($i['date']))) ?></td>
          <td><?= ah($i['customer']) ?></td><td><?= ah($i['gstin'] ?: '—') ?></td>
          <td class="num"><?= ah(arupee($i['taxable'])) ?></td><td class="num"><?= ah(arupee($i['cgst'])) ?></td><td class="num"><?= ah(arupee($i['sgst'])) ?></td><td class="num"><?= ah(arupee($i['total'])) ?></td>
          <td><?= ah(ucfirst($i['status'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="note">Cancelled invoices are listed (a GST return must account for every number in the series) but excluded from the totals. Invoices with a customer GSTIN are B2B; the rest are B2C.</p>
  </div>
<?php endif; ?>
</div>
</body>
</html>
