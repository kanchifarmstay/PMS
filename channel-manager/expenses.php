<?php
/**
 * Expenses - what was spent running the farm stay (groceries, gas, wages...).
 * Replaces the "Raw Material Expenditure" Google Sheet.
 *
 *   expenses.php?month=YYYY-MM[&export=csv]
 *
 * Anyone with 'expenses' may add; voiding needs 'accounts'. Nothing here sends
 * a message to anyone.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/charges-service.php';

startSecureSession();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }
require_once __DIR__ . '/auth.php';
requirePermission('expenses');

function eh(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? $_POST['month'] ?? '')) ? (string)($_GET['month'] ?? $_POST['month']) : date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken($_POST['csrf_token'] ?? null);
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'add') {
            $eid = expenseAdd((string)($_POST['spent_on'] ?? ''), (string)($_POST['category'] ?? ''), (string)($_POST['description'] ?? ''),
                $_POST['amount'] ?? '', (string)($_POST['method'] ?? ''), (string)($_POST['note'] ?? ''));
            kfsAudit('expense_added', 'expense', $eid, ($_POST['category'] ?? '') . ' Rs. ' . ($_POST['amount'] ?? '') . ' ' . trim((string)($_POST['description'] ?? '')));
            $month = substr((string)$_POST['spent_on'], 0, 7);
            $msg = 'Expense added.';
        } elseif ($act === 'void') {
            if (!userCan('accounts')) throw new InvalidArgumentException('Your role cannot void expenses.');
            expenseVoid((int)($_POST['expense_id'] ?? 0), (string)($_POST['reason'] ?? ''));
            kfsAudit('expense_voided', 'expense', (int)($_POST['expense_id'] ?? 0), trim((string)($_POST['reason'] ?? '')));
            $msg = 'Expense voided.';
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        header('Location: expenses.php?month=' . rawurlencode($month) . '&ok=' . rawurlencode($msg)); exit;
    } catch (InvalidArgumentException $e) {
        header('Location: expenses.php?month=' . rawurlencode($month) . '&err=' . rawurlencode($e->getMessage())); exit;
    }
}

$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));
$x = expensesBetween($from, $to);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="expenses-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Category', 'Item', 'Amount', 'Paid by', 'Note', 'Entered by', 'Voided']);
    foreach (array_reverse($x['rows']) as $r) {
        fputcsv($out, [$r['spent_on'], EXPENSE_CATEGORIES[$r['category']] ?? $r['category'], $r['description'],
            number_format((int)$r['amount_paise'] / 100, 2, '.', ''), ACCT_METHODS[$r['method']] ?? $r['method'], $r['note'], $r['created_by'],
            (int)$r['voided'] === 1 ? 'Voided: ' . $r['void_reason'] : '']);
    }
    exit;
}
$prev = date('Y-m', strtotime($from . ' -1 month'));
$next = date('Y-m', strtotime($from . ' +1 month'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Expenses — Kanchi Farm Stay</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 14px; color: #111827; background: #f3f4f6; }
  a { color: #1a5c3a; }
  .wrap { max-width: 900px; margin: 0 auto; padding: 20px 16px 60px; }
  .top { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
  .top h1 { font-size: 20px; margin: 0 auto 0 0; color: #1a5c3a; }
  .btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; color: #111827; padding: 8px 14px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
  .btn-primary { background: #1a5c3a; border-color: #1a5c3a; color: #fff; }
  .btn-sm { padding: 4px 9px; font-size: 12px; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
  .card h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .6px; color: #1a5c3a; margin: 0 0 10px; }
  .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
  .ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
  .err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px 12px; align-items: end; }
  label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 3px; }
  input, select { width: 100%; font: inherit; font-size: 14px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; }
  .month { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .month input { width: auto; }
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 14px; }
  .stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 14px; }
  .stat b { display: block; font-size: 18px; font-weight: 800; }
  .stat span { font-size: 12px; color: #6b7280; }
  .tbl { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 8px; border-top: 1px solid #f0f0f0; vertical-align: top; }
  th { font-size: 11px; color: #6b7280; text-transform: uppercase; border-top: 0; white-space: nowrap; }
  td.num { text-align: right; white-space: nowrap; font-weight: 600; }
  tr.voided td { color: #9ca3af; text-decoration: line-through; }
  tr.voided td.why { text-decoration: none; }
  .muted { color: #6b7280; }
  details summary { cursor: pointer; color: #6b7280; font-size: 12px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>🧺 Expenses</h1>
    <?php if (userCan('accounts')): ?><a class="btn" href="accounts.php?view=summary&amp;year=<?= eh(substr($month, 0, 4)) ?>">📒 Monthly summary</a><?php endif; ?>
    <a class="btn" href="admin.php">← Dashboard</a>
  </div>
  <?php if (isset($_GET['ok'])): ?><div class="flash ok">✓ <?= eh($_GET['ok']) ?></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="flash err"><?= eh($_GET['err']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Add an expense</h2>
    <form method="POST" class="grid">
      <?= csrfField() ?><input type="hidden" name="action" value="add">
      <div><label>Date *</label><input type="date" name="spent_on" value="<?= eh(date('Y-m-d')) ?>" max="<?= eh(date('Y-m-d')) ?>" required></div>
      <div><label>Category *</label><select name="category" required><?php foreach (EXPENSE_CATEGORIES as $k => $l): ?><option value="<?= eh($k) ?>"><?= eh($l) ?></option><?php endforeach; ?></select></div>
      <div><label>Item</label><input name="description" placeholder="e.g. milk, curd, vegetables"></div>
      <div><label>Amount Rs. *</label><input name="amount" inputmode="decimal" required></div>
      <div><label>Paid by *</label><select name="method"><?php foreach (ACCT_METHODS as $k => $l): if (in_array($k, ['ota', 'online'], true)) continue; ?><option value="<?= eh($k) ?>" <?= $k === 'cash' ? 'selected' : '' ?>><?= eh($l) ?></option><?php endforeach; ?></select></div>
      <div><label>Note</label><input name="note"></div>
      <div><button class="btn btn-primary" type="submit">Add expense</button></div>
    </form>
  </div>

  <div class="card">
    <form class="month" method="GET">
      <a class="btn btn-sm" href="expenses.php?month=<?= eh($prev) ?>">‹</a>
      <input type="month" name="month" value="<?= eh($month) ?>" onchange="this.form.submit()">
      <a class="btn btn-sm" href="expenses.php?month=<?= eh($next) ?>">›</a>
      <a class="btn btn-sm" href="expenses.php?month=<?= eh($month) ?>&amp;export=csv">⬇ CSV</a>
    </form>
  </div>
  <div class="stats">
    <div class="stat"><b><?= eh(fmtPaise($x['total'])) ?></b><span>Spent in <?= eh(date('F Y', strtotime($from))) ?></span></div>
    <?php foreach ($x['by_category'] as $cat => $amt): ?><div class="stat"><b><?= eh(fmtPaise($amt)) ?></b><span><?= eh(EXPENSE_CATEGORIES[$cat] ?? $cat) ?></span></div><?php endforeach; ?>
  </div>
  <div class="card">
    <div class="tbl">
    <table>
      <thead><tr><th>Date</th><th>Category</th><th>Item</th><th style="text-align:right">Amount</th><th>Paid by</th><th></th></tr></thead>
      <tbody>
      <?php if (!$x['rows']): ?><tr><td colspan="6" class="muted">Nothing recorded this month.</td></tr><?php endif; ?>
      <?php foreach ($x['rows'] as $r): $void = (int)$r['voided'] === 1; ?>
        <tr class="<?= $void ? 'voided' : '' ?>">
          <td><?= eh(date('d M', strtotime($r['spent_on']))) ?></td>
          <td><?= eh(EXPENSE_CATEGORIES[$r['category']] ?? $r['category']) ?></td>
          <td><?= eh($r['description']) ?><?php if ($r['note'] !== '' || $r['created_by'] !== ''): ?><div class="muted" style="font-size:12px"><?= eh(trim($r['note'] . ($r['note'] !== '' && $r['created_by'] !== '' ? ' · ' : '') . ($r['created_by'] !== '' ? 'by ' . $r['created_by'] : ''))) ?></div><?php endif; ?></td>
          <td class="num"><?= eh(fmtPaise((int)$r['amount_paise'])) ?></td>
          <td><?= eh(ACCT_METHODS[$r['method']] ?? $r['method']) ?></td>
          <td class="why"><?php if ($void): ?><span class="muted" style="font-size:12px">Voided: <?= eh($r['void_reason']) ?></span>
            <?php elseif (userCan('accounts')): ?><details><summary>Void</summary>
              <form method="POST" style="display:flex;gap:6px;margin-top:6px"><?= csrfField() ?><input type="hidden" name="action" value="void"><input type="hidden" name="month" value="<?= eh($month) ?>"><input type="hidden" name="expense_id" value="<?= (int)$r['id'] ?>">
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
