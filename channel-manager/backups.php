<?php
/**
 * Backups - the daily copies of the PMS database, a download for an off-site
 * copy, and "Back up now". Admin only. A file is served only when its name
 * matches the backup pattern exactly, so this page cannot be used to read any
 * other file on the server.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/backup-service.php';

startSecureSession();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }
require_once __DIR__ . '/auth.php';
requirePermission('backups');

function bh(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function bsize(int $b): string { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB'; }

if (isset($_GET['download'])) {
    $path = backupPathFor((string)$_GET['download']);
    if ($path === null) { http_response_code(404); exit('Backup not found.'); }
    kfsAudit('backup_downloaded', 'backup', basename($path));
    header('Content-Type: application/vnd.sqlite3');
    header('Content-Disposition: attachment; filename="kanchifarmstay-' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup_now') {
    requireValidCsrfToken($_POST['csrf_token'] ?? null);
    $r = createBackup();
    kfsAudit('backup_created', 'backup', $r['name'] ?? null, $r['ok'] ? '' : ($r['error'] ?? ''));
    header('Location: backups.php?' . ($r['ok'] ? 'ok=' . rawurlencode($r['name']) : 'err=' . rawurlencode($r['error'])));
    exit;
}

$backups = listBackups();
$latest = $backups[0] ?? null;
$ageHours = $latest ? (time() - $latest['time']) / 3600 : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Backups — Kanchi Farm Stay</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 14px; color: #111827; background: #f3f4f6; }
  .wrap { max-width: 860px; margin: 0 auto; padding: 20px 16px 60px; }
  .top { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
  .top h1 { font-size: 20px; margin: 0 auto 0 0; color: #1a5c3a; }
  .btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; color: #111827; padding: 8px 14px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
  .btn-primary { background: #1a5c3a; border-color: #1a5c3a; color: #fff; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; }
  .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
  .ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
  .err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .tbl { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 8px; border-top: 1px solid #f0f0f0; white-space: nowrap; }
  th { font-size: 11px; color: #6b7280; text-transform: uppercase; border-top: 0; }
  .muted { color: #6b7280; }
  .big { font-size: 18px; font-weight: 800; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>🗄️ Backups</h1>
    <a class="btn" href="admin.php">← Dashboard</a>
    <form method="POST" style="display:inline">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="backup_now">
      <button class="btn btn-primary" type="submit">Back up now</button>
    </form>
  </div>

  <?php if (isset($_GET['ok'])): ?><div class="flash ok">✓ Backup created and verified: <?= bh($_GET['ok']) ?></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="flash err">Backup failed: <?= bh($_GET['err']) ?></div><?php endif; ?>
  <?php if ($latest === null): ?>
    <div class="flash err">No backup exists yet. Press “Back up now”, or wait for tonight's automatic backup.</div>
  <?php elseif ($ageHours > 36): ?>
    <div class="flash err">The newest backup is <?= (int)round($ageHours) ?> hours old — the daily backup may not be running.</div>
  <?php endif; ?>

  <div class="card">
    <div class="muted">Newest backup</div>
    <div class="big"><?= $latest ? bh(date('D, d M Y, g:i A', $latest['time'])) : '—' ?></div>
    <p class="muted" style="margin-bottom:0">A verified copy of every booking, bill, payment and WhatsApp log is taken automatically once a day and the last <?= KFS_BACKUP_KEEP ?> are kept.
      Download one now and then and keep it somewhere other than this server (Google Drive, a laptop) — that is what protects you if the hosting itself fails.</p>
  </div>

  <div class="card">
    <div class="tbl">
    <table>
      <thead><tr><th>Taken (IST)</th><th>Size</th><th></th></tr></thead>
      <tbody>
      <?php if (!$backups): ?><tr><td colspan="3" class="muted">No backups yet.</td></tr><?php endif; ?>
      <?php foreach ($backups as $b): ?>
        <tr>
          <td><?= bh(date('D, d M Y, g:i A', $b['time'])) ?></td>
          <td><?= bh(bsize($b['size'])) ?></td>
          <td><a class="btn" href="backups.php?download=<?= rawurlencode($b['name']) ?>">⬇ Download</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
</body>
</html>
