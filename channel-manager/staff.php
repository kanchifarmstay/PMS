<?php
/**
 * Staff & roles (owner only) and "My password" (everyone, ?me=1).
 * Rules live in auth.php. Passwords are stored as password_hash() only.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';

startSecureSession();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }
$me = currentUser();
$mine = isset($_GET['me']) || ($_POST['action'] ?? '') === 'own_password';
if (!$mine) requirePermission('staff');

function sh(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken($_POST['csrf_token'] ?? null);
    $act = (string)($_POST['action'] ?? '');
    $back = $mine ? 'staff.php?me=1' : 'staff.php';
    try {
        switch ($act) {
            case 'own_password':
                if ((string)($_POST['new'] ?? '') !== (string)($_POST['confirm'] ?? '')) throw new InvalidArgumentException('The two new passwords do not match.');
                changeOwnPassword($me, (string)($_POST['current'] ?? ''), (string)($_POST['new'] ?? ''));
                kfsAudit('password_changed', 'user', $me['id']);
                $msg = 'Your password has been changed.';
                break;
            case 'create':
                $id = createUser((string)($_POST['name'] ?? ''), (string)($_POST['username'] ?? ''), (string)($_POST['role'] ?? ''), (string)($_POST['password'] ?? ''));
                kfsAudit('staff_created', 'user', $id, strtolower(trim((string)$_POST['username'])) . ' as ' . $_POST['role']);
                $msg = 'Account created. Share the username and password with them in person.';
                break;
            case 'update':
                $id = (int)($_POST['id'] ?? 0);
                updateUser($id, (string)($_POST['role'] ?? ''), !empty($_POST['active']));
                kfsAudit('staff_updated', 'user', $id, ($_POST['role'] ?? '') . (!empty($_POST['active']) ? ', active' : ', deactivated'));
                $msg = 'Account updated.';
                break;
            case 'reset':
                $id = (int)($_POST['id'] ?? 0);
                setUserPassword($id, (string)($_POST['password'] ?? ''));
                kfsAudit('staff_password_reset', 'user', $id);
                $msg = 'Password reset.';
                break;
            default: throw new InvalidArgumentException('Unknown action.');
        }
        header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'ok=' . rawurlencode($msg)); exit;
    } catch (InvalidArgumentException $e) {
        header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'err=' . rawurlencode($e->getMessage())); exit;
    }
}
$users = $mine ? [] : listUsers();
$fmt = fn(?string $utc) => ($ts = kfsDbTimestamp($utc)) ? date('d M Y, g:i A', $ts) : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $mine ? 'My password' : 'Staff & roles' ?> — Kanchi Farm Stay</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 14px; color: #111827; background: #f3f4f6; }
  .wrap { max-width: 900px; margin: 0 auto; padding: 20px 16px 60px; }
  .top { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
  .top h1 { font-size: 20px; margin: 0 auto 0 0; color: #1a5c3a; }
  .btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; color: #111827; padding: 8px 14px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
  .btn-primary { background: #1a5c3a; border-color: #1a5c3a; color: #fff; }
  .btn-sm { padding: 5px 10px; font-size: 12px; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
  .card h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .6px; color: #1a5c3a; margin: 0 0 10px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px 12px; align-items: end; }
  label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 3px; }
  input, select { width: 100%; font: inherit; font-size: 14px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; }
  .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
  .ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
  .err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .user { border-top: 1px solid #f0f0f0; padding: 12px 0; display: flex; gap: 12px; flex-wrap: wrap; justify-content: space-between; align-items: center; }
  .user:first-of-type { border-top: 0; }
  .user form { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
  .user form select, .user form input { width: auto; }
  .muted { color: #6b7280; font-size: 12.5px; }
  .off { opacity: .55; }
  table.roles { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.roles td, table.roles th { border-top: 1px solid #f0f0f0; padding: 6px 8px; text-align: left; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1><?= $mine ? '🔑 My password' : '👥 Staff & roles' ?></h1>
    <a class="btn" href="admin.php">← Dashboard</a>
  </div>
  <?php if (isset($_GET['ok'])): ?><div class="flash ok">✓ <?= sh($_GET['ok']) ?></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="flash err"><?= sh($_GET['err']) ?></div><?php endif; ?>

<?php if ($mine): ?>
  <div class="card">
    <p style="margin-top:0">Signed in as <strong><?= sh($me['name']) ?></strong> (<?= sh($me['username']) ?>, <?= sh(KFS_ROLES[$me['role']] ?? $me['role']) ?>).</p>
    <?php if ((int)$me['id'] === 0): ?>
      <p class="muted" style="margin-bottom:0">This is the built-in owner login. Its password is <code>KFS_ADMIN_PASSWORD_HASH</code> in kfs.env on the server and is changed there.</p>
    <?php else: ?>
    <form method="POST" class="grid">
      <?= csrfField() ?><input type="hidden" name="action" value="own_password">
      <div><label>Current password</label><input type="password" name="current" autocomplete="current-password" required></div>
      <div><label>New password (min <?= KFS_MIN_PASSWORD ?>)</label><input type="password" name="new" autocomplete="new-password" minlength="<?= KFS_MIN_PASSWORD ?>" required></div>
      <div><label>New password again</label><input type="password" name="confirm" autocomplete="new-password" required></div>
      <div><button class="btn btn-primary" type="submit">Change password</button></div>
    </form>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card">
    <h2>Add a staff account</h2>
    <form method="POST" class="grid">
      <?= csrfField() ?><input type="hidden" name="action" value="create">
      <div><label>Name</label><input name="name" required></div>
      <div><label>Username</label><input name="username" required autocapitalize="none" pattern="[A-Za-z0-9._-]{3,30}" title="3-30 letters, digits, dot, dash or underscore"></div>
      <div><label>Role</label><select name="role"><?php foreach (KFS_ROLES as $k => $l): ?><option value="<?= sh($k) ?>" <?= $k === 'frontdesk' ? 'selected' : '' ?>><?= sh($l) ?></option><?php endforeach; ?></select></div>
      <div><label>Password (min <?= KFS_MIN_PASSWORD ?>)</label><input type="text" name="password" minlength="<?= KFS_MIN_PASSWORD ?>" required autocomplete="off"></div>
      <div><button class="btn btn-primary" type="submit">Create</button></div>
    </form>
  </div>

  <div class="card">
    <h2>Accounts</h2>
    <div class="user"><div><strong>Owner</strong> <span class="muted">admin · built-in owner login (password in kfs.env) — cannot be removed</span></div></div>
    <?php if (!$users): ?><p class="muted">No staff accounts yet.</p><?php endif; ?>
    <?php foreach ($users as $u): ?>
      <div class="user <?= (int)$u['active'] === 1 ? '' : 'off' ?>">
        <div><strong><?= sh($u['name']) ?></strong> <span class="muted"><?= sh($u['username']) ?> · last login <?= sh($fmt($u['last_login_at'])) ?><?= (int)$u['active'] === 1 ? '' : ' · deactivated' ?></span></div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <select name="role"><?php foreach (KFS_ROLES as $k => $l): ?><option value="<?= sh($k) ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= sh($l) ?></option><?php endforeach; ?></select>
          <label style="display:flex;gap:4px;align-items:center;margin:0;font-size:12px"><input type="checkbox" name="active" value="1" <?= (int)$u['active'] === 1 ? 'checked' : '' ?> style="width:auto"> Active</label>
          <button class="btn btn-sm" type="submit">Save</button></form>
        <form method="POST" onsubmit="return confirm('Reset this password?')"><?= csrfField() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <input name="password" placeholder="New password" minlength="<?= KFS_MIN_PASSWORD ?>" required autocomplete="off" style="width:140px"><button class="btn btn-sm" type="submit">Reset</button></form>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h2>What each role can do</h2>
    <table class="roles">
      <tr><th></th><th>Owner</th><th>Manager</th><th>Front desk</th></tr>
      <tr><td>Bookings: view, add, edit, cancel · Front Desk · bills · WhatsApp · take payments</td><td>✓</td><td>✓</td><td>✓</td></tr>
      <tr><td>Delete bookings · refunds & voids · Accounts & GST · backups · change history</td><td>✓</td><td>✓</td><td>—</td></tr>
      <tr><td>Pricing · channels · analytics · iCal export · business details</td><td>✓</td><td>✓</td><td>—</td></tr>
      <tr><td>Staff accounts</td><td>✓</td><td>—</td><td>—</td></tr>
    </table>
  </div>
<?php endif; ?>
</div>
</body>
</html>
