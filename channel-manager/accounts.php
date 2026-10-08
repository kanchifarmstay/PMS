<?php
/**
 * Accounts - collections, refunds and the monthly GST report.
 *
 *   accounts.php?view=collections&from=&to=[&kind=refund]   money in/out by method
 *   accounts.php?view=gst&month=YYYY-MM                     invoices + tax by rate
 *   accounts.php?view=gstr&period=YYYY-MM[:3]               GSTR-1 / GSTR-3B figures from every stay and food charge
 *   accounts.php?view=summary&year=YYYY                     revenue, food, expenses, profit by month
 *   &export=csv on either view                              the same rows for the accountant
 *
 * Read-only. The numbers come from accounts-service.php.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/accounts-service.php';
require_once __DIR__ . '/charges-service.php';
require_once __DIR__ . '/gst-return-service.php';
require_once __DIR__ . '/wa-templates.php';

startSecureSession();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }
require_once __DIR__ . '/auth.php';
requirePermission('accounts');

function ah(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function arupee(int $paise): string { return fmtPaise($paise); }

acctBackfillOpeningBalances();
$view = in_array($_GET['view'] ?? '', ['gst', 'gstr', 'summary'], true) ? (string)$_GET['view'] : 'collections';
$year = preg_match('/^20\d{2}$/', (string)($_GET['year'] ?? '')) ? (int)$_GET['year'] : (int)date('Y');
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
} elseif ($view === 'summary') {
    $s = monthlySummary($year);
    if ($csv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="monthly-summary-' . $year . '.csv"');
        $out = fopen('php://output', 'w');
        $r2 = fn(int $p) => number_format($p / 100, 2, '.', '');
        fputcsv($out, ['Month', 'Bookings', 'Room revenue', 'Room received', 'Room pending', 'Food & extras', 'Food not yet paid', 'Total revenue', 'Expenses', 'Net profit', 'Margin %']);
        foreach ($s['months'] + ['Total' => $s['total']] as $ym => $m) {
            fputcsv($out, [$ym, $m['bookings'], $r2($m['room']), $r2($m['received']), $r2($m['pending']), $r2($m['food']), $r2($m['food_unpaid']),
                $r2($m['revenue']), $r2($m['expenses']), $r2($m['net']), $m['margin'] === null ? '' : number_format($m['margin'] * 100, 1)]);
        }
        exit;
    }
} elseif ($view === 'gstr') {
    // Default: the quarter that has just ended - the one whose return is due next.
    $period = preg_match('/^\d{4}-\d{2}(:[13])?$/', (string)($_GET['period'] ?? '')) ? (string)$_GET['period']
        : gstQuarterSpec(date('Y-m-d', strtotime(date('Y-m-01') . ' -3 months')));
    $inclusive = ($_GET['excl'] ?? '') !== '1';
    $ecoError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireValidCsrfToken($_POST['csrf_token'] ?? null);
        try {
            saveGstEcoGstins((array)($_POST['eco'] ?? []));
            kfsAudit('gst_eco_gstins_saved', 'settings', null, json_encode(gstEcoGstins()));
            header('Location: accounts.php?view=gstr&period=' . urlencode($period) . ($inclusive ? '' : '&excl=1') . '#eco');
            exit;
        } catch (InvalidArgumentException $e) {
            $ecoError = $e->getMessage();
        }
    }
    try {
        $r = gstReturn($period, $inclusive);
    } catch (InvalidArgumentException $e) {
        $period = gstQuarterSpec(date('Y-m-d'));
        $r = gstReturn($period, $inclusive);
    }
    $ecoGstins = gstEcoGstins();
    if ($csv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="gst-register-' . $r['period']['from'] . '-to-' . $r['period']['to'] . '.csv"');
        $out = fopen('php://output', 'w');
        $r2 = fn(int $p) => number_format($p / 100, 2, '.', '');
        fputcsv($out, ['Date', 'Booking', 'Guest', 'Room', 'Booked via', 'Line', 'Invoice no', 'Customer GSTIN', 'SAC', 'GST %', 'Taxable value', 'CGST', 'SGST', 'Total']);
        foreach ($r['lines'] as $l) {
            fputcsv($out, [$l['date'], $l['booking_id'] ? '#' . str_pad((string)$l['booking_id'], 4, '0', STR_PAD_LEFT) : '', $l['guest'], $l['room'],
                $l['source'] !== '' ? waSourceLabel($l['source']) : '', $l['desc'], $l['invoice_no'], $l['customer_gstin'], $l['sac'], $l['gst'],
                $r2($l['taxable']), $r2($l['cgst']), $r2($l['sgst']), $r2($l['taxable'] + $l['cgst'] + $l['sgst'])]);
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
$qs = fn(array $over) => 'accounts.php?' . http_build_query(array_filter(array_merge(['view' => $view, 'from' => $from, 'to' => $to, 'kind' => $kind, 'month' => $month, 'year' => $view === 'summary' ? $year : ''], $over), fn($v) => $v !== '' && $v !== null));
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
  .card h3 { font-size: 14px; margin: 16px 0 6px; color: #111827; }
  .card h3:first-of-type { margin-top: 4px; }
  .card h3 small { font-weight: 500; color: #6b7280; }
  .warn { background: #fffbeb; border-color: #fcd34d; }
  .warn li { margin: 6px 0; font-size: 13px; }
  .due { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 8px; }
  .due div { border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px; font-size: 13px; }
  .due b { display: block; color: #1a5c3a; }
  .err { color: #b91c1c; font-weight: 600; }
  details summary { cursor: pointer; font-weight: 600; color: #1a5c3a; }
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
    <a class="btn tab <?= $view === 'gstr' ? 'on' : '' ?>" href="accounts.php?view=gstr">GST return</a>
    <a class="btn tab <?= $view === 'gst' ? 'on' : '' ?>" href="accounts.php?view=gst">GST invoices</a>
    <a class="btn tab <?= $view === 'summary' ? 'on' : '' ?>" href="accounts.php?view=summary">Monthly summary</a>
    <a class="btn" href="expenses.php">🧺 Expenses</a>
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

<?php elseif ($view === 'summary'): $t = $s['total']; $pct = fn($m) => $m === null ? '—' : number_format($m * 100, 1) . '%'; ?>
  <div class="card">
    <form class="filters" method="GET">
      <input type="hidden" name="view" value="summary">
      <div><label>Year</label><select name="year"><?php for ($yy = (int)date('Y'); $yy >= 2025; $yy--): ?><option <?= $yy === $year ? 'selected' : '' ?>><?= $yy ?></option><?php endfor; ?></select></div>
      <div><button class="btn btn-primary" type="submit">Show</button></div>
      <div><a class="btn" href="<?= ah($qs(['export' => 'csv'])) ?>">⬇ CSV</a></div>
    </form>
  </div>
  <div class="stats">
    <div class="stat"><b><?= ah(arupee($t['revenue'])) ?></b><span>Total revenue (room + food)</span></div>
    <div class="stat"><b><?= ah(arupee($t['food'])) ?></b><span>Food &amp; extras</span></div>
    <div class="stat <?= $t['pending'] + $t['food_unpaid'] > 0 ? 'neg' : '' ?>"><b><?= ah(arupee($t['pending'] + $t['food_unpaid'])) ?></b><span>Still to collect</span></div>
    <div class="stat"><b><?= ah(arupee($t['expenses'])) ?></b><span>Expenses</span></div>
    <div class="stat <?= $t['net'] < 0 ? 'neg' : '' ?>"><b><?= ah(arupee($t['net'])) ?></b><span>Net profit · <?= ah($pct($t['margin'])) ?> margin</span></div>
  </div>
  <div class="card">
    <h2><?= (int)$year ?> by month</h2>
    <div class="tbl">
    <table>
      <thead><tr><th>Month</th><th class="num">Bookings</th><th class="num">Room revenue</th><th class="num">Pending</th><th class="num">Food &amp; extras</th><th class="num">Total revenue</th><th class="num">Expenses</th><th class="num">Net profit</th><th class="num">Margin</th></tr></thead>
      <tbody>
      <?php foreach ($s['months'] as $ym => $m): if ($ym > date('Y-m') && $m['bookings'] === 0) continue; ?>
        <tr>
          <td><?= ah(date('M Y', strtotime($ym . '-01'))) ?></td>
          <td class="num"><?= (int)$m['bookings'] ?></td>
          <td class="num"><?= ah(arupee($m['room'])) ?></td>
          <td class="num" style="<?= $m['pending'] > 0 ? 'color:#92400e' : '' ?>"><?= ah(arupee($m['pending'])) ?></td>
          <td class="num"><?= ah(arupee($m['food'])) ?></td>
          <td class="num"><?= ah(arupee($m['revenue'])) ?></td>
          <td class="num"><?= ah(arupee($m['expenses'])) ?></td>
          <td class="num" style="<?= $m['net'] < 0 ? 'color:#b91c1c' : '' ?>"><?= ah(arupee($m['net'])) ?></td>
          <td class="num"><?= ah($pct($m['margin'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td>Total</td><td class="num"><?= (int)$t['bookings'] ?></td><td class="num"><?= ah(arupee($t['room'])) ?></td><td class="num"><?= ah(arupee($t['pending'])) ?></td><td class="num"><?= ah(arupee($t['food'])) ?></td><td class="num"><?= ah(arupee($t['revenue'])) ?></td><td class="num"><?= ah(arupee($t['expenses'])) ?></td><td class="num"><?= ah(arupee($t['net'])) ?></td><td class="num"><?= ah($pct($t['margin'])) ?></td></tr></tfoot>
    </table>
    </div>
    <p class="note">Room revenue and pending are counted in the month of check-in; food on the day it was charged; expenses on the day they were spent. Cancelled bookings and blocked dates are left out.</p>
  </div>
  <div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
    <div class="card"><h2>By source</h2><div class="tbl"><table><tbody>
      <?php foreach ($s['by_source'] as $src => $r): ?><tr><td><?= ah(waSourceLabel($src)) ?></td><td class="num"><?= (int)$r['bookings'] ?></td><td class="num"><?= ah(arupee($r['room'])) ?></td></tr><?php endforeach; ?>
      <?php if (!$s['by_source']): ?><tr><td class="muted">No bookings.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <div class="card"><h2>By room</h2><div class="tbl"><table><tbody>
      <?php foreach ($s['by_room'] as $r): ?><tr><td><?= ah($r['name']) ?></td><td class="num"><?= (int)$r['bookings'] ?></td><td class="num"><?= ah(arupee($r['room'])) ?></td></tr><?php endforeach; ?>
      <?php if (!$s['by_room']): ?><tr><td class="muted">No bookings.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <div class="card"><h2>Expenses by category</h2><div class="tbl"><table><tbody>
      <?php foreach ($s['by_category'] as $cat => $amt): ?><tr><td><?= ah(EXPENSE_CATEGORIES[$cat] ?? $cat) ?></td><td class="num"><?= ah(arupee($amt)) ?></td></tr><?php endforeach; ?>
      <?php if (!$s['by_category']): ?><tr><td class="muted">No expenses recorded.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  </div>

<?php elseif ($view === 'gstr'):
  $t = $r['total']; $pr = $r['period']; $biz = billProfile();
  $pos = $biz['state_code'] . '-' . $biz['state'];
  $isQuarter = count($pr['months']) === 3;
  $after = date('M Y', strtotime($pr['to'] . ' +1 day'));
  $rr = fn(int $p) => ah(arupee($p));
  $has18 = (bool)array_filter($r['lines'], fn($l) => $l['gst'] === 18);
  // Period choices: the last eight quarters, then the last twelve months.
  $choices = [];
  for ($i = 0; $i < 8; $i++) { $sp = gstQuarterSpec(date('Y-m-d', strtotime(date('Y-m-01') . ' -' . (3 * $i) . ' months'))); $choices[$sp] = gstPeriod($sp)['label']; }
  for ($i = 0; $i < 12; $i++) { $sp = date('Y-m', strtotime(date('Y-m-01') . " -{$i} months")); $choices[$sp] = gstPeriod($sp)['label']; }
?>
  <div class="card">
    <form class="filters" method="GET">
      <input type="hidden" name="view" value="gstr">
      <div><label>Return period</label><select name="period">
        <optgroup label="Quarter (QRMP)"><?php foreach ($choices as $sp => $lbl) if (str_contains($sp, ':')): ?><option value="<?= ah($sp) ?>" <?= $sp === $period ? 'selected' : '' ?>><?= ah($lbl) ?></option><?php endif; ?></optgroup>
        <optgroup label="Month"><?php foreach ($choices as $sp => $lbl) if (!str_contains($sp, ':')): ?><option value="<?= ah($sp) ?>" <?= $sp === $period ? 'selected' : '' ?>><?= ah($lbl) ?></option><?php endif; ?></optgroup>
      </select></div>
      <div><label>Booking amounts</label><select name="excl"><option value="">include GST</option><option value="1" <?= $inclusive ? '' : 'selected' ?>>are before GST</option></select></div>
      <div><button class="btn btn-primary" type="submit">Show</button></div>
      <div><a class="btn" href="<?= ah('accounts.php?view=gstr&period=' . urlencode($period) . ($inclusive ? '' : '&excl=1') . '&export=csv') ?>">⬇ Register CSV</a></div>
    </form>
    <p class="note">Every confirmed stay is counted in the month it checks out, every food charge on its date. A stay with a bill linked to it uses the bill instead. GSTIN <?= ah($biz['gstin']) ?> · place of supply <?= ah($pos) ?> (CGST + SGST for every guest).</p>
  </div>

  <div class="stats">
    <div class="stat"><b><?= (int)$r['stays'] ?></b><span>Stays in <?= ah($pr['label']) ?></span></div>
    <div class="stat"><b><?= $rr($t['taxable']) ?></b><span>Taxable value</span></div>
    <div class="stat"><b><?= $rr($t['cgst']) ?></b><span>CGST</span></div>
    <div class="stat"><b><?= $rr($t['sgst']) ?></b><span>SGST</span></div>
    <div class="stat"><b><?= $rr($t['tax']) ?></b><span>Total GST</span></div>
  </div>

  <?php if ($r['checks']): ?>
  <div class="card warn">
    <h2>⚠️ Check <?= count($r['checks']) ?> thing<?= count($r['checks']) === 1 ? '' : 's' ?> before you file</h2>
    <ul style="margin:0;padding-left:18px">
      <?php foreach ($r['checks'] as $c): ?>
      <li><?php if ($c['booking_id']): ?><a href="booking-payments.php?id=<?= (int)$c['booking_id'] ?>">#<?= (int)$c['booking_id'] ?></a> <?php endif; ?><b><?= ah($c['guest']) ?></b><?= $c['room'] !== '' ? ' · ' . ah($c['room']) : '' ?> — <?= ah($c['why']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Pay and file</h2>
    <div class="tbl"><table>
      <thead><tr><th>Month</th><th class="num">Taxable</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">GST</th><th>What to do</th></tr></thead>
      <tbody>
      <?php $mi = 0; foreach ($r['by_month'] as $ym => $m): $mi++; $next = date('M Y', strtotime($ym . '-01 +1 month')); ?>
        <tr><td><?= ah(date('F Y', strtotime($ym . '-01'))) ?></td><td class="num"><?= $rr($m['taxable']) ?></td><td class="num"><?= $rr($m['cgst']) ?></td><td class="num"><?= $rr($m['sgst']) ?></td><td class="num"><?= $rr($m['cgst'] + $m['sgst']) ?></td>
          <td><?= $isQuarter && $mi < 3 ? 'Pay by challan PMT-06 by <b>25 ' . ah($next) . '</b> (self-assessment)' : 'Settled in GSTR-3B by <b>' . ($isQuarter ? '22' : '20') . ' ' . ah($next) . '</b>' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="due" style="margin-top:10px">
      <div><b>GSTR-1 by <?= $isQuarter ? '13' : '11' ?> <?= ah($after) ?></b>Sales: the tables below</div>
      <div><b>GSTR-3B by <?= $isQuarter ? '22' : '20' ?> <?= ah($after) ?></b>Pay the balance after PMT-06 and TCS</div>
    </div>
    <p class="note">Dates are for a Tamil Nadu GSTIN<?= $isQuarter ? ' filing quarterly (QRMP)' : ' filing monthly' ?>. A late return costs ₹50 a day (₹20 if nil). File even when a period has no sales.</p>
  </div>

  <div class="card">
    <h2>GSTR-1 <small class="muted" style="text-transform:none;letter-spacing:0">· <?= ah($pr['label']) ?></small></h2>

    <h3>4A · B2B invoices <small>(guests who gave their own GSTIN)</small></h3>
    <div class="tbl"><table>
      <thead><tr><th>Customer GSTIN</th><th>Name</th><th>Invoice no</th><th>Date</th><th>Rate</th><th class="num">Taxable</th><th class="num">CGST</th><th class="num">SGST</th><th>POS</th></tr></thead>
      <tbody>
      <?php foreach ($r['b2b'] as $i): ?>
        <tr><td><?= ah($i['gstin']) ?></td><td><?= ah($i['guest']) ?></td><td><?= ah($i['invoice_no']) ?></td><td><?= ah($i['date']) ?></td><td><?= ah(implode(', ', array_map(fn($g) => $g . '%', array_keys($i['rates'])))) ?></td><td class="num"><?= $rr($i['taxable']) ?></td><td class="num"><?= $rr($i['cgst']) ?></td><td class="num"><?= $rr($i['sgst']) ?></td><td><?= ah($pos) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$r['b2b']): ?><tr><td colspan="9" class="muted">None — leave table 4A empty.</td></tr><?php endif; ?>
      </tbody>
    </table></div>

    <h3>7 · B2C (Others) <small>(everyone else, one line per rate)</small></h3>
    <div class="tbl"><table>
      <thead><tr><th>Place of supply</th><th>Rate</th><th class="num">Taxable value</th><th class="num">CGST</th><th class="num">SGST</th></tr></thead>
      <tbody>
      <?php foreach ($r['b2cs'] as $b): ?>
        <tr><td><?= ah($pos) ?></td><td><?= (int)$b['gst'] ?>%</td><td class="num"><?= $rr($b['taxable']) ?></td><td class="num"><?= $rr($b['cgst']) ?></td><td class="num"><?= $rr($b['sgst']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$r['b2cs']): ?><tr><td colspan="5" class="muted">No B2C sales.</td></tr><?php endif; ?>
      </tbody>
    </table></div>

    <h3>12 · HSN/SAC summary <small>(UQC "NA", quantity 0 for services)</small></h3>
    <div class="tbl"><table>
      <thead><tr><th>Tab</th><th>SAC</th><th>Description</th><th>Rate</th><th class="num">Taxable value</th><th class="num">CGST</th><th class="num">SGST</th></tr></thead>
      <tbody>
      <?php foreach (['b2c' => 'B2C', 'b2b' => 'B2B'] as $tab => $tabName) foreach ($r['hsn'][$tab] as $h): ?>
        <tr><td><?= $tabName ?></td><td><?= ah($h['sac']) ?></td><td><?= ah($h['name']) ?></td><td><?= (int)$h['gst'] ?>%</td><td class="num"><?= $rr($h['taxable']) ?></td><td class="num"><?= $rr($h['cgst']) ?></td><td class="num"><?= $rr($h['sgst']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$r['hsn']['b2c'] && !$r['hsn']['b2b']): ?><tr><td colspan="7" class="muted">Nothing to report.</td></tr><?php endif; ?>
      </tbody>
    </table></div>

    <h3>13 · Documents issued <small>(Invoices for outward supply)</small></h3>
    <?php $d = $r['docs']; if ($d['total']): ?>
    <div class="tbl"><table>
      <thead><tr><th>From</th><th>To</th><th class="num">Total number</th><th class="num">Cancelled</th><th class="num">Net issued</th></tr></thead>
      <tbody><tr><td><?= ah($d['from']) ?></td><td><?= ah($d['to']) ?></td><td class="num"><?= (int)$d['total'] ?></td><td class="num"><?= (int)$d['cancelled'] ?></td><td class="num"><?= (int)($d['total'] - $d['cancelled']) ?></td></tr></tbody>
    </table></div>
    <?php else: ?><p class="muted" style="margin:0">No invoices were raised in this period.</p><?php endif; ?>
    <?php if ($r['stays'] > $d['total'] - $d['cancelled']): ?><p class="note">GST expects a tax invoice for every stay: <?= (int)$r['stays'] ?> stays, <?= (int)($d['total'] - $d['cancelled']) ?> invoices. Raise them from 🧾 Bill on each booking at check-out.</p><?php endif; ?>

    <h3 id="eco">14 · Supplies through e-commerce operators <small>(OTAs that collect TCS — reported here in addition to table 7)</small></h3>
    <div class="tbl"><table>
      <thead><tr><th>Operator</th><th>Operator GSTIN</th><th class="num">Stays</th><th class="num">Taxable value</th><th class="num">CGST</th><th class="num">SGST</th></tr></thead>
      <tbody>
      <?php foreach ($r['eco'] as $e): ?>
        <tr><td><?= ah($e['name']) ?></td><td><?= $ecoGstins[$e['key']] !== '' ? ah($ecoGstins[$e['key']]) : '<span class="err">enter below</span>' ?></td><td class="num"><?= (int)$e['count'] ?></td><td class="num"><?= $rr($e['taxable']) ?></td><td class="num"><?= $rr($e['cgst']) ?></td><td class="num"><?= $rr($e['sgst']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$r['eco']): ?><tr><td colspan="6" class="muted">No OTA stays this period.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <details style="margin-top:8px" <?= $ecoError || ($r['eco'] && in_array('', array_intersect_key($ecoGstins, array_flip(array_column($r['eco'], 'key'))), true)) ? 'open' : '' ?>>
      <summary>OTA GSTINs (Tamil Nadu registration — printed on each OTA's TCS / tax statement)</summary>
      <?php if ($ecoError): ?><p class="err"><?= ah($ecoError) ?></p><?php endif; ?>
      <form method="POST" class="filters" style="margin-top:8px">
        <?= csrfField() ?>
        <?php foreach (GST_ECO_OPERATORS as $key => $name): ?>
        <div><label><?= ah($name) ?></label><input name="eco[<?= ah($key) ?>]" value="<?= ah($ecoGstins[$key]) ?>" maxlength="15" style="text-transform:uppercase;width:170px"></div>
        <?php endforeach; ?>
        <div><button class="btn btn-primary" type="submit">Save</button></div>
      </form>
    </details>
  </div>

  <div class="card">
    <h2>GSTR-3B</h2>
    <div class="tbl"><table>
      <thead><tr><th>Table</th><th class="num">Taxable value</th><th class="num">IGST</th><th class="num">CGST</th><th class="num">SGST</th></tr></thead>
      <tbody>
        <tr><td>3.1(a) Outward taxable supplies</td><td class="num"><?= $rr($t['taxable']) ?></td><td class="num">₹0</td><td class="num"><?= $rr($t['cgst']) ?></td><td class="num"><?= $rr($t['sgst']) ?></td></tr>
        <tr><td>4 Eligible ITC</td><td class="num">—</td><td class="num">₹0</td><td class="num">₹0</td><td class="num">₹0</td></tr>
      </tbody>
    </table></div>
    <ol class="note" style="padding-left:18px;line-height:1.6">
      <li>Before 3B, accept the OTAs' TCS under <b>Returns → TDS/TCS credit received</b>. It lands in your cash ledger and reduces what you pay.</li>
      <li>Table 3.1(a) fills from GSTR-1 — check it matches the figures above.</li>
      <li>ITC is ₹0: rooms up to ₹7,500 and food are 5% <i>without</i> input credit.<?= $has18 ? ' This period has 18% stays, which do allow input credit on purchases used for them — ask your CA before claiming any.' : '' ?></li>
      <li>Set off the tax against the PMT-06 payments and TCS, pay any shortfall by challan, then file with EVC.</li>
    </ol>
  </div>

  <div class="card">
    <details>
      <summary>Register — <?= count($r['lines']) ?> lines behind these figures</summary>
      <div class="tbl" style="margin-top:8px"><table>
        <thead><tr><th>Date</th><th>Booking</th><th>Guest</th><th>Line</th><th>Via</th><th>SAC</th><th>Rate</th><th class="num">Taxable</th><th class="num">GST</th></tr></thead>
        <tbody>
        <?php foreach ($r['lines'] as $l): ?>
          <tr><td><?= ah($l['date']) ?></td><td><?= $l['booking_id'] ? '<a href="booking-payments.php?id=' . (int)$l['booking_id'] . '">#' . (int)$l['booking_id'] . '</a>' : '' ?></td><td><?= ah($l['guest']) ?></td><td><?= ah($l['desc']) ?><?= $l['invoice_no'] !== '' ? ' <span class="muted">' . ah($l['invoice_no']) . '</span>' : '' ?></td><td><?= $l['source'] !== '' ? ah(waSourceLabel($l['source'])) : '' ?></td><td><?= ah($l['sac']) ?></td><td><?= (int)$l['gst'] ?>%</td><td class="num"><?= $rr($l['taxable']) ?></td><td class="num"><?= $rr($l['cgst'] + $l['sgst']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </details>
    <p class="note">These figures are prepared from the PMS for you to copy into the GST portal. Check them against your OTA statements; have a CA review your first filing.</p>
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
