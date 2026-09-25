<?php
/**
 * Front Desk - the day at the gate.
 *
 *   frontdesk.php[?date=YYYY-MM-DD]   arrivals, departures, in-house, overdue,
 *                                     Form C to do, housekeeping board
 *   frontdesk.php?checkin=ID          check-in form with the guest ID register
 *
 * Rules live in frontdesk-service.php.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/frontdesk-service.php';

startSecureSession();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }
require_once __DIR__ . '/auth.php';
requirePermission('frontdesk');

function fh(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? (string)$_GET['date'] : date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken($_POST['csrf_token'] ?? null);
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $back = 'frontdesk.php?date=' . rawurlencode((string)($_POST['date'] ?? date('Y-m-d')));
    try {
        switch ($act) {
            case 'checkin':
                $files = [];
                foreach (($_FILES['id_photo']['name'] ?? []) as $i => $_) {
                    $files[$i] = ['name' => $_FILES['id_photo']['name'][$i], 'type' => $_FILES['id_photo']['type'][$i],
                        'tmp_name' => $_FILES['id_photo']['tmp_name'][$i], 'error' => $_FILES['id_photo']['error'][$i], 'size' => $_FILES['id_photo']['size'][$i]];
                }
                fdCheckIn($id, (array)($_POST['guests'] ?? []), $files);
                $msg = 'Checked in booking #' . str_pad((string)$id, 4, '0', STR_PAD_LEFT) . '.';
                break;
            case 'checkout': fdCheckOut($id); $msg = 'Checked out. Rooms marked dirty for housekeeping.'; break;
            case 'noshow':   fdMarkNoShow($id); $msg = 'Marked as no-show.'; break;
            case 'undo':     fdUndoStay($id); $msg = 'Put back to expected.'; break;
            case 'hk':       hkSetStatus((string)($_POST['room'] ?? ''), (string)($_POST['status'] ?? ''), trim((string)($_POST['note'] ?? ''))); $msg = 'Room updated.'; break;
            case 'formc':    fcMarkSubmitted((int)($_POST['guest_id'] ?? 0), (string)($_POST['reference'] ?? '')); $msg = 'Form C marked as submitted.'; break;
            default: throw new InvalidArgumentException('Unknown action.');
        }
        kfsAudit('frontdesk_' . $act, $act === 'hk' ? 'room' : ($act === 'formc' ? 'guest' : 'booking'), $act === 'hk' ? (string)($_POST['room'] ?? '') : ($act === 'formc' ? (int)($_POST['guest_id'] ?? 0) : $id), $act === 'hk' ? (string)($_POST['status'] ?? '') : '');
        header('Location: ' . $back . '&ok=' . rawurlencode($msg)); exit;
    } catch (InvalidArgumentException $e) {
        $target = $act === 'checkin' ? 'frontdesk.php?checkin=' . $id . '&date=' . rawurlencode((string)($_POST['date'] ?? '')) : $back;
        header('Location: ' . $target . '&err=' . rawurlencode($e->getMessage())); exit;
    }
}

$register = isset($_GET['register']);
if ($register) {
    $rFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : date('Y-m-01');
    $rTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : date('Y-m-d');
    $rQ = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
    $regRows = fdRegister($rFrom, $rTo, $rQ);
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="guest-register-' . $rFrom . '-to-' . $rTo . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Check-in', 'Check-out', 'Booking', 'Room', 'Guest', 'Nationality', 'ID type', 'ID number', 'Phone',
            'Passport', 'Passport expiry', 'Visa', 'Visa type', 'Visa expiry', 'Arrived in India', 'Coming from', 'Next destination', 'Form C reference']);
        foreach ($regRows as $g) {
            fputcsv($out, [$g['check_in'], $g['check_out'], '#' . str_pad((string)$g['booking_id'], 4, '0', STR_PAD_LEFT), $g['room_name'], $g['name'],
                $g['nationality'], FD_ID_TYPES[$g['id_type']] ?? $g['id_type'], $g['id_number'], $g['phone'], $g['passport_no'], $g['passport_expiry'],
                $g['visa_no'], $g['visa_type'], $g['visa_expiry'], $g['arrived_india_on'], $g['coming_from'], $g['next_destination'], $g['form_c_reference']]);
        }
        exit;
    }
}
$checkinBooking = isset($_GET['checkin']) ? getBookingById((int)$_GET['checkin']) : null;
$day = fdDay($date);
$formC = fcPending();
$rooms = hkRooms();
$stayLabel = ['expected' => 'Expected', 'checked_in' => 'Checked in', 'checked_out' => 'Checked out', 'no_show' => 'No-show'];

$bookingRow = function (array $b, string $context) use ($date, $stayLabel): void {
    $stay = fdStayStatus($b);
    $balance = waBalance($b);
    $guests = fdGuestsForBooking((int)$b['id']);
    echo '<div class="row"><div class="who"><strong>' . fh($b['guest_name']) . '</strong>'
       . '<span>#' . fh(waBookingNo($b)) . ' · ' . fh($b['room_name']) . ' · ' . fh(waDateRange($b['check_in'], $b['check_out'])) . ' · ' . fh(waSourceLabel($b['source'])) . '</span>';
    if ($balance >= 1) echo '<span class="due">Balance due Rs. ' . fh(waMoney($balance)) . '</span>';
    foreach ($guests as $g) {
        echo '<span class="muted">🪪 ' . fh($g['name']) . ' · ' . fh(FD_ID_TYPES[$g['id_type']] ?? $g['id_type']) . ' ' . fh($g['id_number'])
           . ($g['is_foreign'] ? ' · ' . fh($g['nationality']) : '')
           . ($g['id_photo'] !== '' ? ' · <a href="guest-id-file.php?f=' . rawurlencode($g['id_photo']) . '" target="_blank" rel="noopener">View ID</a>' : ' · <em>no photo</em>') . '</span>';
    }
    echo '</div><div class="acts"><span class="pill s-' . fh($stay) . '">' . fh($stayLabel[$stay] ?? $stay) . '</span>';
    $form = function (string $action, string $label, string $cls = '', string $confirm = '') use ($b, $date) {
        return '<form method="POST"' . ($confirm ? ' onsubmit="return confirm(' . fh(json_encode($confirm)) . ')"' : '') . '>' . csrfField()
             . '<input type="hidden" name="action" value="' . fh($action) . '"><input type="hidden" name="id" value="' . (int)$b['id'] . '">'
             . '<input type="hidden" name="date" value="' . fh($date) . '"><button class="btn ' . $cls . '" type="submit">' . fh($label) . '</button></form>';
    };
    if ($stay === 'expected') {
        echo '<a class="btn btn-primary" href="frontdesk.php?checkin=' . (int)$b['id'] . '&date=' . fh($date) . '">Check in</a>';
        if ($b['check_in'] <= date('Y-m-d')) echo $form('noshow', 'No-show', 'btn-warn', 'Mark ' . $b['guest_name'] . ' as a no-show?');
    } elseif ($stay === 'checked_in') {
        echo $form('checkout', 'Check out', 'btn-primary', 'Check out ' . $b['guest_name'] . '?' . ($balance >= 1 ? ' Balance due: Rs. ' . waMoney($balance) : ''));
    }
    if ($stay !== 'expected') echo $form('undo', 'Undo', 'btn-ghost', 'Put this booking back to expected?');
    echo '</div></div>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Front Desk — Kanchi Farm Stay</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 14px; color: #111827; background: #f3f4f6; }
  a { color: #1a5c3a; }
  .wrap { max-width: 980px; margin: 0 auto; padding: 20px 16px 60px; }
  .top { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
  .top h1 { font-size: 20px; margin: 0 auto 0 0; color: #1a5c3a; }
  .btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; color: #111827; padding: 7px 12px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
  .btn-primary { background: #1a5c3a; border-color: #1a5c3a; color: #fff; }
  .btn-warn { color: #92400e; border-color: #fcd34d; }
  .btn-ghost { color: #6b7280; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
  .card h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .6px; color: #1a5c3a; margin: 0 0 8px; display: flex; justify-content: space-between; }
  .card h2 small { color: #6b7280; font-weight: 600; }
  .row { display: flex; gap: 10px; justify-content: space-between; align-items: center; flex-wrap: wrap; padding: 10px 0; border-top: 1px solid #f0f0f0; }
  .row:first-of-type { border-top: 0; }
  .who { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1 1 280px; }
  .who span { font-size: 12.5px; color: #4b5563; }
  .due { color: #92400e !important; font-weight: 700; }
  .acts { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
  .acts form { display: inline; }
  .pill { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 99px; background: #f3f4f6; color: #374151; }
  .s-checked_in { background: #e8f5ee; color: #1a5c3a; } .s-checked_out { background: #e0e7ff; color: #3730a3; } .s-no_show { background: #fee2e2; color: #991b1b; }
  .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
  .ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
  .err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .warnbox { background: #fffbeb; border-color: #fde68a; }
  .muted { color: #6b7280; }
  .rooms { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 10px; }
  .room { border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; }
  .room strong { display: block; margin-bottom: 4px; }
  .room .st { font-size: 12px; font-weight: 700; }
  .h-ready { color: #1a5c3a; } .h-dirty { color: #b91c1c; } .h-cleaning { color: #92400e; } .h-maintenance { color: #6b21a8; }
  .room form { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 8px; }
  .room .btn { padding: 4px 8px; font-size: 12px; }
  label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 3px; }
  input, select { width: 100%; font: inherit; font-size: 14px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px 14px; }
  .guest { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; margin-bottom: 10px; }
  .foreign { display: none; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e5e7eb; }
  .guest.is-foreign .foreign { display: block; }
  .datebar { display: flex; gap: 6px; align-items: center; }
  .datebar input { width: auto; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>🛎️ Front Desk</h1>
    <a class="btn" href="admin.php">← Dashboard</a>
    <a class="btn" href="frontdesk.php?register=1">📒 Guest register</a>
    <form class="datebar" method="GET"><input type="date" name="date" value="<?= fh($date) ?>" onchange="this.form.submit()"><a class="btn" href="frontdesk.php">Today</a></form>
  </div>
  <?php if (isset($_GET['ok'])): ?><div class="flash ok">✓ <?= fh($_GET['ok']) ?></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="flash err"><?= fh($_GET['err']) ?></div><?php endif; ?>

<?php if ($register): ?>
  <div class="card">
    <h2>Guest register <small><?= count($regRows) ?> guest<?= count($regRows) === 1 ? '' : 's' ?></small></h2>
    <form method="GET" class="grid" style="align-items:end;margin-bottom:10px">
      <input type="hidden" name="register" value="1">
      <div><label>Staying from</label><input type="date" name="from" value="<?= fh($rFrom) ?>"></div>
      <div><label>To</label><input type="date" name="to" value="<?= fh($rTo) ?>"></div>
      <div><label>Search</label><input name="q" value="<?= fh($rQ) ?>" placeholder="Name, phone, ID, passport, booking #"></div>
      <div style="display:flex;gap:6px"><button class="btn btn-primary" type="submit">Show</button>
        <a class="btn" href="frontdesk.php?<?= fh(http_build_query(['register' => 1, 'from' => $rFrom, 'to' => $rTo, 'q' => $rQ, 'export' => 'csv'])) ?>">⬇ CSV</a></div>
    </form>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead><tr style="text-align:left;color:#6b7280;font-size:11px;text-transform:uppercase"><th>Stay</th><th>Guest</th><th>ID</th><th>Nationality</th><th>Booking</th><th>Form C</th><th></th></tr></thead>
      <tbody>
      <?php if (!$regRows): ?><tr><td colspan="7" class="muted" style="padding:8px">No guests recorded for these dates.</td></tr><?php endif; ?>
      <?php foreach ($regRows as $g): ?>
        <tr style="border-top:1px solid #f0f0f0">
          <td style="padding:7px;white-space:nowrap"><?= fh(waDateRange($g['check_in'], $g['check_out'])) ?></td>
          <td style="padding:7px"><?= fh($g['name']) ?><?= $g['phone'] !== '' ? '<div class="muted">' . fh($g['phone']) . '</div>' : '' ?></td>
          <td style="padding:7px;white-space:nowrap"><?= fh(FD_ID_TYPES[$g['id_type']] ?? $g['id_type']) ?><div class="muted"><?= fh($g['id_number']) ?></div></td>
          <td style="padding:7px"><?= fh($g['nationality']) ?><?= $g['is_foreign'] ? '<div class="muted">Passport ' . fh($g['passport_no']) . '</div>' : '' ?></td>
          <td style="padding:7px;white-space:nowrap">#<?= fh(str_pad((string)$g['booking_id'], 4, '0', STR_PAD_LEFT)) ?><div class="muted"><?= fh($g['room_name']) ?></div></td>
          <td style="padding:7px"><?= $g['is_foreign'] ? ($g['form_c_reference'] !== '' ? fh($g['form_c_reference']) : '<span class="due" style="color:#b91c1c;font-weight:700">Pending</span>') : '—' ?></td>
          <td style="padding:7px"><?= $g['id_photo'] !== '' ? '<a href="guest-id-file.php?f=' . rawurlencode($g['id_photo']) . '" target="_blank" rel="noopener">View ID</a>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php elseif ($checkinBooking): $b = $checkinBooking; ?>
  <div class="card">
    <h2>Check in · #<?= fh(waBookingNo($b)) ?></h2>
    <p style="margin:0 0 10px"><strong><?= fh($b['guest_name']) ?></strong> · <?= fh($b['room_name']) ?> · <?= fh(waDateRange($b['check_in'], $b['check_out'])) ?> · <?= fh(waSourceLabel($b['source'])) ?>
      <?php if (waBalance($b) >= 1): ?><br><span class="due" style="color:#92400e;font-weight:700">Balance due at check-in: Rs. <?= fh(waMoney(waBalance($b))) ?></span><?php endif; ?></p>
    <p class="muted" style="margin:0 0 12px;font-size:12.5px">Record photo ID for every adult. For Aadhaar only the last 4 digits are stored. Foreign nationals also need passport and visa details — they must be reported on Form C within 24 hours.</p>
    <form method="POST" enctype="multipart/form-data" id="ciForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="checkin">
      <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
      <input type="hidden" name="date" value="<?= fh($date) ?>">
      <div id="guests"></div>
      <p><button class="btn" type="button" id="addGuest">+ Add another guest</button></p>
      <p style="margin-bottom:0"><button class="btn btn-primary" type="submit">Check in</button> <a class="btn" href="frontdesk.php?date=<?= fh($date) ?>">Cancel</a></p>
    </form>
  </div>
  <template id="guestTpl">
    <div class="guest">
      <div class="grid">
        <div><label>Full name *</label><input data-f="name" required></div>
        <div><label>ID type *</label><select data-f="id_type" required><?php foreach (FD_ID_TYPES as $k => $l): ?><option value="<?= fh($k) ?>"><?= fh($l) ?></option><?php endforeach; ?></select></div>
        <div><label>ID number *</label><input data-f="id_number" required autocomplete="off"></div>
        <div><label>Nationality</label><input data-f="nationality" value="Indian"></div>
        <div><label>Phone</label><input data-f="phone" inputmode="tel"></div>
        <div><label>ID photo (JPG/PNG/PDF, max 5 MB)</label><input type="file" data-file accept="image/*,application/pdf" capture="environment"></div>
      </div>
      <div class="foreign">
        <div class="grid">
          <div><label>Passport number *</label><input data-f="passport_no"></div>
          <div><label>Passport expiry</label><input type="date" data-f="passport_expiry"></div>
          <div><label>Visa number</label><input data-f="visa_no"></div>
          <div><label>Visa type</label><input data-f="visa_type" placeholder="e.g. Tourist, e-Visa"></div>
          <div><label>Visa expiry</label><input type="date" data-f="visa_expiry"></div>
          <div><label>Arrived in India on</label><input type="date" data-f="arrived_india_on"></div>
          <div><label>Coming from</label><input data-f="coming_from"></div>
          <div><label>Next destination</label><input data-f="next_destination"></div>
        </div>
      </div>
    </div>
  </template>
  <script>
  (function () {
    var box = document.getElementById('guests'), tpl = document.getElementById('guestTpl'), n = 0;
    var first = <?= json_encode(['name' => (string)$b['guest_name'], 'phone' => (string)(($b['whatsapp_number'] ?? '') ?: ($b['guest_phone'] ?? ''))], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    function add(pre) {
      var i = n++, node = tpl.content.firstElementChild.cloneNode(true);
      node.querySelectorAll('[data-f]').forEach(function (el) { el.name = 'guests[' + i + '][' + el.getAttribute('data-f') + ']'; });
      node.querySelector('[data-file]').name = 'id_photo[' + i + ']';
      if (i > 0) node.querySelectorAll('[required]').forEach(function (el) { el.required = false; });
      if (pre) { node.querySelector('[data-f="name"]').value = pre.name || ''; node.querySelector('[data-f="phone"]').value = pre.phone || ''; }
      var nat = node.querySelector('[data-f="nationality"]');
      function sync() { var v = nat.value.trim().toLowerCase(); node.classList.toggle('is-foreign', v !== '' && ['india', 'indian', 'in', 'ind'].indexOf(v) === -1); }
      nat.addEventListener('input', sync);
      box.appendChild(node);
    }
    document.getElementById('addGuest').addEventListener('click', function () { add(null); });
    add(first);
  })();
  </script>

<?php else: ?>
  <?php if ($formC): ?>
  <div class="card warnbox">
    <h2>Form C to submit <small><?= count($formC) ?> foreign guest<?= count($formC) === 1 ? '' : 's' ?></small></h2>
    <p class="muted" style="margin:0 0 6px;font-size:12.5px">Report each foreign guest at indianfrro.gov.in (Form C) within 24 hours of check-in, then record the reference here.</p>
    <?php foreach ($formC as $g): $dl = fcDeadline($g); $late = $dl !== null && $dl < time(); ?>
      <div class="row">
        <div class="who"><strong><?= fh($g['name']) ?> · <?= fh($g['nationality']) ?></strong>
          <span>Passport <?= fh($g['passport_no']) ?> · #<?= fh(str_pad((string)$g['booking_id'], 4, '0', STR_PAD_LEFT)) ?> <?= fh($g['room_name']) ?></span>
          <span class="<?= $late ? 'due' : 'muted' ?>"><?= $dl === null ? 'Not checked in yet' : ($late ? 'OVERDUE — was due ' : 'Due by ') . fh(date('d M, g:i A', $dl)) ?></span></div>
        <form class="acts" method="POST"><?= csrfField() ?><input type="hidden" name="action" value="formc"><input type="hidden" name="guest_id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="date" value="<?= fh($date) ?>">
          <input name="reference" placeholder="FRRO reference" style="width:170px" required><button class="btn btn-primary" type="submit">Mark submitted</button></form>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($day['overdue']): ?>
  <div class="card warnbox">
    <h2>Not marked yet <small>should have arrived before <?= fh(date('d M', strtotime($date))) ?></small></h2>
    <?php foreach ($day['overdue'] as $b) $bookingRow($b, 'overdue'); ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Arrivals · <?= fh(date('D, d M Y', strtotime($date))) ?> <small><?= count($day['arrivals']) ?></small></h2>
    <?php if (!$day['arrivals']): ?><p class="muted" style="margin:0">No arrivals.</p><?php endif; ?>
    <?php foreach ($day['arrivals'] as $b) $bookingRow($b, 'arrival'); ?>
  </div>
  <div class="card">
    <h2>Departures <small><?= count($day['departures']) ?></small></h2>
    <?php if (!$day['departures']): ?><p class="muted" style="margin:0">No departures.</p><?php endif; ?>
    <?php foreach ($day['departures'] as $b) $bookingRow($b, 'departure'); ?>
  </div>
  <div class="card">
    <h2>In-house now <small><?= count($day['inhouse']) ?></small></h2>
    <?php if (!$day['inhouse']): ?><p class="muted" style="margin:0">Nobody is checked in.</p><?php endif; ?>
    <?php foreach ($day['inhouse'] as $b) $bookingRow($b, 'inhouse'); ?>
  </div>

  <div class="card">
    <h2>Housekeeping <small><?= count(array_filter($rooms, fn($r) => $r['status'] === 'ready')) ?> of <?= count($rooms) ?> ready</small></h2>
    <div class="rooms">
    <?php foreach ($rooms as $r): ?>
      <div class="room">
        <strong><?= fh($r['name']) ?></strong>
        <span class="st h-<?= fh($r['status']) ?>">● <?= fh(FD_ROOM_STATUSES[$r['status']] ?? $r['status']) ?></span>
        <?php if ($r['note'] !== ''): ?><div class="muted" style="font-size:12px"><?= fh($r['note']) ?></div><?php endif; ?>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="hk"><input type="hidden" name="room" value="<?= fh($r['room_id']) ?>"><input type="hidden" name="date" value="<?= fh($date) ?>">
          <?php foreach (FD_ROOM_STATUSES as $k => $l): if ($k === $r['status']) continue; ?>
            <button class="btn" name="status" value="<?= fh($k) ?>" type="submit"><?= fh($l) ?></button>
          <?php endforeach; ?>
        </form>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
</div>
</body>
</html>
