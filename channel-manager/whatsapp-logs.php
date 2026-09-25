<?php
/**
 * WhatsApp Logs - every WhatsApp template the PMS has tried to send.
 *
 *   whatsapp-logs.php                     latest sends, sent/failed/blocked
 *   ?status=failed&template=...&q=...     filters (q matches number, booking #, detail, context)
 *   ?from=2026-09-01&to=2026-09-30        IST dates
 *   ?export=csv                           the same rows as a CSV download
 *
 * "Sent" means Meta ACCEPTED the message (it returned a wamid). Whether the
 * phone received or read it needs Meta's delivery webhook, which is not
 * subscribed for this number, so the log does not claim it.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/wa-templates.php';

startSecureSession();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }
require_once __DIR__ . '/auth.php';
requirePermission('whatsapp');

require_once __DIR__ . '/whatsapp-logs-lib.php';

waBackfillLegacyLogs();
$db = getDB();
$f = waLogFilters($_GET);
[$whereSql, $args] = waLogWhere($f);
$admins = adminAlertNumbers(ADMIN_ALERT_WA_NUMBERS);

if (($_GET['export'] ?? '') === 'csv') {
    $q = $db->prepare("SELECT * FROM wa_template_log {$whereSql} ORDER BY created_at DESC, id DESC LIMIT 20000");
    $q->execute($args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="whatsapp-logs-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['When (IST)', 'Message', 'Template', 'To', 'Booking', 'Result', 'Context', 'Detail']);
    foreach ($q->fetchAll() as $r) {
        fputcsv($out, [waLogIst($r['created_at']), WA_LOG_LABELS[$r['template']] ?? $r['template'], $r['template'],
            $r['recipient'], $r['booking_id'] ? '#' . str_pad((string)$r['booking_id'], 4, '0', STR_PAD_LEFT) : '',
            $r['status'], $r['context'], $r['detail']]);
    }
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$count = $db->prepare("SELECT COUNT(*) FROM wa_template_log {$whereSql}");
$count->execute($args);
$total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / WA_LOG_PAGE_SIZE));
$page = min($page, $pages);
$rowsQ = $db->prepare("SELECT * FROM wa_template_log {$whereSql} ORDER BY created_at DESC, id DESC LIMIT " . WA_LOG_PAGE_SIZE . " OFFSET " . (($page - 1) * WA_LOG_PAGE_SIZE));
$rowsQ->execute($args);
$rows = $rowsQ->fetchAll();

$stat = fn(string $status, string $since) => (int)$db->query("SELECT COUNT(*) FROM wa_template_log WHERE status = " . $db->quote($status) . " AND created_at >= datetime('now', " . $db->quote($since) . ")")->fetchColumn();
$stats = [
    'sent24'  => $stat('sent', '-1 day'),   'failed24'  => $stat('failed', '-1 day'),
    'sent7'   => $stat('sent', '-7 days'),  'failed7'   => $stat('failed', '-7 days'),
    'blocked7' => $stat('blocked', '-7 days'),
];
$byTemplate = $db->query("SELECT template, COUNT(*) n FROM wa_template_log WHERE status='sent' AND created_at >= datetime('now', '-7 days') GROUP BY template ORDER BY n DESC")->fetchAll();

$qs = fn(array $over) => 'whatsapp-logs.php?' . http_build_query(array_filter(array_merge($f, $over), fn($v) => $v !== '' && $v !== null));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>WhatsApp Logs — Kanchi Farm Stay</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 14px; color: #111827; background: #f3f4f6; }
  a { color: #1a5c3a; }
  .wrap { max-width: 1200px; margin: 0 auto; padding: 20px 16px 60px; }
  .top { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
  .top h1 { font-size: 20px; margin: 0 auto 0 0; color: #1a5c3a; }
  .btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; color: #111827; padding: 8px 14px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
  .btn-primary { background: #1a5c3a; border-color: #1a5c3a; color: #fff; }
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 14px; }
  .stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 14px; text-decoration: none; color: inherit; }
  .stat b { display: block; font-size: 24px; font-weight: 800; }
  .stat span { font-size: 12px; color: #6b7280; }
  .stat.bad b { color: #b91c1c; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
  .filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
  .filters label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 4px; }
  .filters input, .filters select { font: inherit; font-size: 13px; padding: 7px 9px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; }
  .chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
  .chip { font-size: 12px; background: #f3f4f6; border-radius: 99px; padding: 3px 10px; color: #374151; text-decoration: none; }
  .tbl { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 8px 8px; border-top: 1px solid #f0f0f0; vertical-align: top; }
  th { font-size: 11px; color: #6b7280; text-transform: uppercase; border-top: 0; white-space: nowrap; }
  td.nowrap { white-space: nowrap; }
  .pill { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 99px; white-space: nowrap; }
  .p-sent { background: #e8f5ee; color: #1a5c3a; }
  .p-failed { background: #fee2e2; color: #991b1b; }
  .p-blocked { background: #fef3c7; color: #92400e; }
  .p-seeded, .p-claimed { background: #f3f4f6; color: #6b7280; }
  .detail { color: #6b7280; font-size: 12px; max-width: 360px; word-break: break-word; }
  .who { font-size: 11px; color: #6b7280; }
  .pager { display: flex; gap: 8px; align-items: center; justify-content: flex-end; margin-top: 12px; flex-wrap: wrap; }
  .muted { color: #6b7280; }
  .note { font-size: 12px; color: #6b7280; margin: 10px 0 0; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>📜 WhatsApp Logs</h1>
    <a class="btn" href="admin.php">← Dashboard</a>
    <a class="btn" href="<?= lh($qs(['export' => 'csv', 'page' => null])) ?>">⬇ Export CSV</a>
  </div>

  <div class="stats">
    <a class="stat" href="<?= lh($qs(['status' => 'sent', 'from' => date('Y-m-d', strtotime('-1 day')), 'to' => '', 'page' => null])) ?>"><b><?= $stats['sent24'] ?></b><span>Sent · last 24 h</span></a>
    <a class="stat <?= $stats['failed24'] ? 'bad' : '' ?>" href="<?= lh($qs(['status' => 'failed', 'from' => date('Y-m-d', strtotime('-1 day')), 'to' => '', 'page' => null])) ?>"><b><?= $stats['failed24'] ?></b><span>Failed · last 24 h</span></a>
    <a class="stat" href="<?= lh($qs(['status' => 'sent', 'from' => date('Y-m-d', strtotime('-7 days')), 'to' => '', 'page' => null])) ?>"><b><?= $stats['sent7'] ?></b><span>Sent · 7 days</span></a>
    <a class="stat <?= $stats['failed7'] ? 'bad' : '' ?>" href="<?= lh($qs(['status' => 'failed', 'from' => date('Y-m-d', strtotime('-7 days')), 'to' => '', 'page' => null])) ?>"><b><?= $stats['failed7'] ?></b><span>Failed · 7 days</span></a>
    <a class="stat" href="<?= lh($qs(['status' => 'blocked', 'from' => '', 'to' => '', 'page' => null])) ?>"><b><?= $stats['blocked7'] ?></b><span>Blocked (platform guests) · 7 days</span></a>
  </div>

  <div class="card">
    <form class="filters" method="GET">
      <div><label>Search</label><input name="q" value="<?= lh($f['q']) ?>" placeholder="Number, booking #, error…"></div>
      <div><label>Message</label>
        <select name="template"><option value="">All messages</option>
          <?php foreach (WA_LOG_LABELS as $k => $l): ?><option value="<?= lh($k) ?>" <?= $f['template'] === $k ? 'selected' : '' ?>><?= lh($l) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Result</label>
        <select name="status">
          <?php foreach (['' => 'Sent, failed & blocked', 'sent' => 'Sent', 'failed' => 'Failed', 'blocked' => 'Blocked', 'all' => 'Everything (incl. system rows)'] as $k => $l): ?>
          <option value="<?= lh($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= lh($l) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>From</label><input type="date" name="from" value="<?= lh($f['from']) ?>"></div>
      <div><label>To</label><input type="date" name="to" value="<?= lh($f['to']) ?>"></div>
      <div><button class="btn btn-primary" type="submit">Filter</button></div>
      <div><a class="btn" href="whatsapp-logs.php">Reset</a></div>
    </form>
    <?php if ($byTemplate): ?>
    <div class="chips">
      <span class="muted" style="font-size:12px;align-self:center">Sent in 7 days:</span>
      <?php foreach ($byTemplate as $t): ?><a class="chip" href="<?= lh($qs(['template' => $t['template'], 'page' => null])) ?>"><?= lh(WA_LOG_LABELS[$t['template']] ?? $t['template']) ?> · <?= (int)$t['n'] ?></a><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="tbl">
    <table>
      <thead><tr><th>When (IST)</th><th>Message</th><th>To</th><th>Booking</th><th>Result</th><th>How</th><th>Detail</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted">No WhatsApp messages match these filters.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r):
        $isAdmin = in_array($r['recipient'], $admins, true) || $r['recipient'] === 'admins';
        $detail = (string)$r['detail'];
        if (str_starts_with($detail, 'wamid.')) $detail = 'Accepted by Meta · ' . substr($detail, 0, 18) . '…'; ?>
        <tr>
          <td class="nowrap"><?= lh(waLogIst($r['created_at'])) ?></td>
          <td><?= lh(WA_LOG_LABELS[$r['template']] ?? $r['template']) ?></td>
          <td class="nowrap"><?= $r['recipient'] === 'admins' ? 'All admins' : ($r['recipient'] !== '' ? '+' . lh($r['recipient']) : '—') ?><?php if ($isAdmin && $r['recipient'] !== 'admins'): ?><div class="who">Admin</div><?php endif; ?></td>
          <td class="nowrap"><?= $r['booking_id'] ? '<a href="booking-whatsapp.php?id=' . (int)$r['booking_id'] . '">#' . str_pad((string)$r['booking_id'], 4, '0', STR_PAD_LEFT) . '</a>' : '—' ?></td>
          <td><span class="pill p-<?= lh($r['status']) ?>"><?= lh(ucfirst($r['status'])) ?></span></td>
          <td class="nowrap"><?= lh($r['context'] !== '' ? $r['context'] : '—') ?></td>
          <td class="detail"><?= lh($detail !== '' ? $detail : '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="pager">
      <span class="muted"><?= $total ?> message<?= $total === 1 ? '' : 's' ?> · page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page > 1): ?><a class="btn" href="<?= lh($qs(['page' => $page - 1])) ?>">← Newer</a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="btn" href="<?= lh($qs(['page' => $page + 1])) ?>">Older →</a><?php endif; ?>
    </div>
    <p class="note">“Sent” means Meta accepted the message. Delivery and read receipts are not tracked for this number. “Blocked” is a message the PMS refused to send because the guest booked through Airbnb, Booking.com, Agoda or MakeMyTrip.</p>
  </div>
</div>
</body>
</html>
