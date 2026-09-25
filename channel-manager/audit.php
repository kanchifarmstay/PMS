<?php
/**
 * Change history - who did what in the PMS (managers and the owner).
 *   audit.php[?user=&action=&q=][&export=csv]
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';

startSecureSession();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }
requirePermission('audit');

function uh(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

const AUDIT_LABELS = [
    'login' => 'Logged in', 'login_failed' => 'Wrong password', 'login_locked' => 'Locked out', 'logout' => 'Logged out',
    'denied' => 'Refused (no permission)', 'booking_added' => 'Booking added', 'booking_edited' => 'Booking edited',
    'booking_cancelled' => 'Booking cancelled', 'booking_deleted' => 'Booking deleted', 'payment_added' => 'Payment added',
    'refund_recorded' => 'Refund recorded', 'payment_voided' => 'Payment voided', 'bill_issued' => 'Bill issued',
    'bill_edited' => 'Bill edited', 'bill_cancelled' => 'Bill cancelled', 'bill_whatsapp_sent' => 'Bill sent on WhatsApp',
    'bill_whatsapp_failed' => 'Bill WhatsApp failed', 'frontdesk_checkin' => 'Checked in', 'frontdesk_checkout' => 'Checked out',
    'frontdesk_noshow' => 'No-show', 'frontdesk_undo' => 'Stay status undone', 'frontdesk_hk' => 'Room status', 'frontdesk_formc' => 'Form C submitted',
    'whatsapp_sent' => 'WhatsApp sent', 'whatsapp_failed' => 'WhatsApp failed', 'backup_created' => 'Backup taken',
    'backup_downloaded' => 'Backup downloaded', 'staff_created' => 'Staff added', 'staff_updated' => 'Staff changed',
    'staff_password_reset' => 'Staff password reset', 'password_changed' => 'Own password changed', 'business_details_saved' => 'Business details saved',
];

$f = ['user' => mb_substr(trim((string)($_GET['user'] ?? '')), 0, 30), 'action' => mb_substr(trim((string)($_GET['action'] ?? '')), 0, 60),
      'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60)];
$rows = auditRows($f, ($_GET['export'] ?? '') === 'csv' ? 20000 : 300);
$label = fn(string $a) => AUDIT_LABELS[$a] ?? (str_starts_with($a, 'settings_') ? 'Setting: ' . str_replace('_', ' ', substr($a, 9)) : $a);
$ist = fn(string $utc) => ($ts = kfsDbTimestamp($utc)) ? date('d M Y, g:i A', $ts) : '';

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="change-history-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['When (IST)', 'Who', 'What', 'Item', 'Detail', 'IP']);
    foreach ($rows as $r) fputcsv($out, [$ist($r['created_at']), $r['username'], $label($r['action']), trim($r['entity'] . ' ' . $r['entity_id']), $r['detail'], $r['ip']]);
    exit;
}
$people = getDB()->query("SELECT DISTINCT username FROM audit_log WHERE username <> '' ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
$actions = getDB()->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$failedToday = (int)getDB()->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('login_failed','login_locked') AND created_at >= datetime('now','-1 day')")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Change history — Kanchi Farm Stay</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 14px; color: #111827; background: #f3f4f6; }
  a { color: #1a5c3a; }
  .wrap { max-width: 1150px; margin: 0 auto; padding: 20px 16px 60px; }
  .top { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
  .top h1 { font-size: 20px; margin: 0 auto 0 0; color: #1a5c3a; }
  .btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; color: #111827; padding: 8px 14px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
  .btn-primary { background: #1a5c3a; border-color: #1a5c3a; color: #fff; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
  .filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
  label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 3px; }
  input, select { font: inherit; font-size: 13px; padding: 7px 9px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; }
  .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .tbl { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 8px; border-top: 1px solid #f0f0f0; vertical-align: top; }
  th { font-size: 11px; color: #6b7280; text-transform: uppercase; border-top: 0; white-space: nowrap; }
  td.nw { white-space: nowrap; }
  .bad td { background: #fef2f2; }
  .detail { color: #4b5563; font-size: 12.5px; max-width: 460px; word-break: break-word; }
  .muted { color: #6b7280; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>🕵️ Change history</h1>
    <a class="btn" href="admin.php">← Dashboard</a>
    <a class="btn" href="audit.php?<?= uh(http_build_query(array_filter($f) + ['export' => 'csv'])) ?>">⬇ CSV</a>
  </div>
  <?php if ($failedToday >= 3): ?><div class="flash"><?= $failedToday ?> failed or locked logins in the last 24 hours. <a href="audit.php?action=login_failed">See them</a>.</div><?php endif; ?>
  <div class="card">
    <form class="filters" method="GET">
      <div><label>Who</label><select name="user"><option value="">Everyone</option><?php foreach ($people as $p): ?><option <?= $f['user'] === $p ? 'selected' : '' ?>><?= uh($p) ?></option><?php endforeach; ?></select></div>
      <div><label>What</label><select name="action"><option value="">Everything</option><?php foreach ($actions as $a): ?><option value="<?= uh($a) ?>" <?= $f['action'] === $a ? 'selected' : '' ?>><?= uh($label($a)) ?></option><?php endforeach; ?></select></div>
      <div><label>Search</label><input name="q" value="<?= uh($f['q']) ?>" placeholder="Booking #, guest, detail…"></div>
      <div><button class="btn btn-primary" type="submit">Filter</button></div>
      <div><a class="btn" href="audit.php">Reset</a></div>
    </form>
  </div>
  <div class="card">
    <div class="tbl">
    <table>
      <thead><tr><th>When (IST)</th><th>Who</th><th>What</th><th>Item</th><th>Detail</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="muted">Nothing recorded yet.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $bad = in_array($r['action'], ['login_failed', 'login_locked', 'denied', 'booking_deleted'], true); ?>
        <tr class="<?= $bad ? 'bad' : '' ?>">
          <td class="nw"><?= uh($ist($r['created_at'])) ?></td>
          <td class="nw"><?= uh($r['username'] ?: '—') ?></td>
          <td class="nw"><?= uh($label($r['action'])) ?></td>
          <td class="nw"><?php if ($r['entity'] === 'booking' && $r['entity_id'] !== null): ?><a href="booking-payments.php?id=<?= (int)$r['entity_id'] ?>">#<?= uh(str_pad((string)$r['entity_id'], 4, '0', STR_PAD_LEFT)) ?></a>
            <?php elseif ($r['entity'] === 'bill' && $r['entity_id'] !== null): ?><a href="bill.php?id=<?= (int)$r['entity_id'] ?>">Bill <?= uh($r['entity_id']) ?></a>
            <?php else: ?><?= uh(trim($r['entity'] . ' ' . ($r['entity_id'] ?? ''))) ?: '—' ?><?php endif; ?></td>
          <td class="detail"><?= uh($r['detail'] ?: '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="muted" style="font-size:12px;margin-bottom:0">Newest 300 shown; the CSV has everything that matches.</p>
  </div>
</div>
</body>
</html>
