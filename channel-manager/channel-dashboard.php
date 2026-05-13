<?php
/**
 * Channel Availability Dashboard — Double Booking Prevention
 * Shows 2-week grid + upcoming bookings list from ALL sources.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: admin.php'); exit;
}

// ── Handle manual booking save ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_manual') {
    $rid = trim($_POST['room_id'] ?? '');
    addBooking([
        'room_id'        => $rid,
        'room_name'      => ROOM_IDS[$rid] ?? $rid,
        'check_in'       => $_POST['check_in']    ?? '',
        'check_out'      => $_POST['check_out']   ?? '',
        'guest_name'     => trim($_POST['guest_name']  ?? 'Manual Booking'),
        'guest_phone'    => trim($_POST['guest_phone'] ?? ''),
        'guest_email'    => trim($_POST['guest_email'] ?? ''),
        'source'         => $_POST['source']      ?? 'manual',
        'amount'         => (float)($_POST['amount'] ?? 0),
        'payment_status' => $_POST['payment_status'] ?? 'unpaid',
        'notes'          => trim($_POST['notes']  ?? ''),
        'status'         => 'confirmed',
        'uid'            => 'manual-'.uniqid(),
        'booking_ref'    => '',
    ]);
    header('Location: channel-dashboard.php?saved=1'); exit;
}

// ── Date range: today → today+13 (2 weeks) ────────────────────
$today    = new DateTime('today');
$rangeEnd = clone $today; $rangeEnd->modify('+13 days');
$days = [];
for ($i = 0; $i < 14; $i++) {
    $d = clone $today; $d->modify("+{$i} days");
    $days[] = $d;
}
$rangeStart = $today->format('Y-m-d');
$rangeEndStr = $rangeEnd->format('Y-m-d');
$saved = !empty($_GET['saved']);

// ── Source config ──────────────────────────────────────────────
$SRC = [
    'airbnb'      => ['label'=>'Airbnb',          'bg'=>'#e03131', 'text'=>'#fff', 'border'=>'#c92a2a'],
    'booking.com' => ['label'=>'Booking.com',     'bg'=>'#1971c2', 'text'=>'#fff', 'border'=>'#1864ab'],
    'booking'     => ['label'=>'Booking.com',     'bg'=>'#1971c2', 'text'=>'#fff', 'border'=>'#1864ab'],
    'agoda'       => ['label'=>'Agoda',            'bg'=>'#c2255c', 'text'=>'#fff', 'border'=>'#a61e4d'],
    'makemytrip'  => ['label'=>'MakeMyTrip',      'bg'=>'#e8590c', 'text'=>'#fff', 'border'=>'#d9480f'],
    'direct'      => ['label'=>'Direct / Website','bg'=>'#2f9e44', 'text'=>'#fff', 'border'=>'#2b8a3e'],
    'razorpay'    => ['label'=>'Direct / Website','bg'=>'#2f9e44', 'text'=>'#fff', 'border'=>'#2b8a3e'],
    'manual'      => ['label'=>'Phone/WhatsApp',  'bg'=>'#7048e8', 'text'=>'#fff', 'border'=>'#5f3dc4'],
    'phone'       => ['label'=>'Phone/WhatsApp',  'bg'=>'#7048e8', 'text'=>'#fff', 'border'=>'#5f3dc4'],
    'whatsapp'    => ['label'=>'Phone/WhatsApp',  'bg'=>'#7048e8', 'text'=>'#fff', 'border'=>'#5f3dc4'],
    'blocked'     => ['label'=>'Blocked / Hold',  'bg'=>'#495057', 'text'=>'#fff', 'border'=>'#343a40'],
];
function sc(string $src, array $map): array {
    return $map[strtolower(trim($src))] ?? ['label'=>ucfirst(trim($src)),'bg'=>'#4a7c59','text'=>'#fff','border'=>'#2f7a4a'];
}

// ── Query: all bookings overlapping next 14 days ───────────────
$db = getDB();
$stmt = $db->prepare("
    SELECT * FROM bookings
    WHERE status != 'cancelled'
      AND check_in  <= :e
      AND check_out > :s
    ORDER BY check_in, room_id
");
$stmt->execute([':s' => $rangeStart, ':e' => $rangeEndStr]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Query: ALL upcoming bookings (for the list below the grid) ─
$upcomingStmt = $db->prepare("
    SELECT * FROM bookings
    WHERE status != 'cancelled'
      AND check_out > :today
    ORDER BY check_in ASC
    LIMIT 60
");
$upcomingStmt->execute([':today' => $rangeStart]);
$upcomingAll = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Build cell map [room_id][Y-m-d] = booking ─────────────────
$cellMap = [];
foreach (ROOM_IDS as $rid => $_) {
    foreach ($days as $day) {
        $cellMap[$rid][$day->format('Y-m-d')] = null;
    }
}
foreach ($bookings as $b) {
    $ci = new DateTime($b['check_in']);
    $co = new DateTime($b['check_out']);
    foreach ($days as $day) {
        $ds = $day->format('Y-m-d');
        if ($day >= $ci && $day < $co) {
            if (empty($cellMap[$b['room_id']][$ds])) {
                $cellMap[$b['room_id']][$ds] = $b;
            }
        }
    }
}

// ── OTA connection status ──────────────────────────────────────
$chRows  = $db->query("SELECT platform, COUNT(*) c FROM external_calendars GROUP BY platform")->fetchAll(PDO::FETCH_ASSOC);
$connected = [];
foreach ($chRows as $r) $connected[strtolower($r['platform'])] = true;

// ── Last sync time ─────────────────────────────────────────────
$lastSync = 'Never';
$logFile  = __DIR__ . '/cron.log';
if (file_exists($logFile)) {
    $lines = array_filter(explode("\n", file_get_contents($logFile)));
    $last  = end($lines);
    if (preg_match('/\[(.+?)\]/', $last, $m)) $lastSync = $m[1];
}

// ── Count by source for this window ───────────────────────────
$srcStats = [];
foreach ($bookings as $b) {
    $lbl = sc($b['source'], $SRC)['label'];
    $srcStats[$lbl] = ($srcStats[$lbl] ?? 0) + 1;
}

$DAYS_OF_WEEK = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Channel Dashboard — Availability</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f2f5;color:#212529;font-size:13px}

/* ── Top bar ── */
.topbar{background:#1b3a2d;color:#fff;padding:13px 22px;display:flex;align-items:center;gap:12px}
.topbar h1{font-size:1rem;font-weight:800;flex:1;letter-spacing:-.2px}
.topbar a.back{font-size:.78rem;color:#74c69d;text-decoration:none;font-weight:600}

/* ── Channel status ── */
.ch-status{background:#fff;border-bottom:2px solid #dee2e6;padding:10px 22px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.ch-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:20px;font-size:.73rem;font-weight:700;white-space:nowrap}
.pill-ok{background:#d3f9d8;color:#1a6626;border:1.5px solid #74c69d}
.pill-no{background:#ffe3e3;color:#c92a2a;border:1.5px solid #ffa8a8}
.pill-live{background:#e7f5ff;color:#1864ab;border:1.5px solid #74c0fc}
.sync-info{margin-left:auto;font-size:.73rem;color:#868e96;white-space:nowrap}
.sync-info a{color:#2b8a3e;font-weight:700;text-decoration:none}

/* ── Alerts ── */
.alert{padding:9px 22px;font-size:.82rem;display:flex;align-items:center;gap:8px;border-bottom:1px solid}
.a-ok{background:#d3f9d8;color:#1a6626;border-color:#8ce99a}
.a-warn{background:#fff3bf;color:#6b4c00;border-color:#ffd43b}
.a-warn a{color:#6b4c00;font-weight:700}

/* ── Legend + actions bar ── */
.toolbar{background:#fff;border-bottom:2px solid #dee2e6;padding:10px 22px;display:flex;gap:14px;flex-wrap:wrap;align-items:center}
.leg{display:flex;align-items:center;gap:5px;font-size:.75rem;font-weight:700;color:#495057}
.ls{width:16px;height:16px;border-radius:4px;flex-shrink:0}
.ls.avail{background:#fff;border:2px solid #ced4da}
.toolbar-right{margin-left:auto;display:flex;gap:8px;align-items:center}
.add-btn{background:#7048e8;color:#fff;border:none;border-radius:7px;padding:8px 16px;font-size:.8rem;font-weight:700;cursor:pointer;white-space:nowrap}
.add-btn:hover{background:#5f3dc4}
.refresh-btn{background:#f1f3f5;color:#495057;border:1.5px solid #ced4da;border-radius:7px;padding:7px 13px;font-size:.78rem;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap}

/* ── Stats chips ── */
.stats-bar{background:#f8f9fa;border-bottom:1px solid #dee2e6;padding:9px 22px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.s-chip{display:flex;align-items:center;gap:6px;padding:5px 12px;border-radius:8px;font-size:.77rem;font-weight:700}
.s-num{font-size:1rem;font-weight:800}

/* ── Grid ── */
.grid-wrap{padding:18px 22px 6px;overflow-x:auto}
table.av{border-collapse:collapse;width:100%}

/* Room label */
table.av td.rl,table.av th.rl-h{
    position:sticky;left:0;z-index:2;
    min-width:150px;max-width:150px;width:150px;
    padding:0 12px;font-weight:700;font-size:.76rem;
    border-right:3px solid #1b3a2d;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
table.av th.rl-h{
    background:#1b3a2d;color:#fff;z-index:3;
    height:60px;vertical-align:middle;text-align:left;font-size:.74rem;
}
table.av td.rl{
    background:#f8f9fa;color:#1b3a2d;
    border-bottom:1px solid #dee2e6;
    height:56px;vertical-align:middle;
}

/* Day headers */
table.av th.dh{
    background:#1b3a2d;text-align:center;
    min-width:82px;height:60px;vertical-align:middle;
    border-left:1px solid rgba(255,255,255,.1);padding:4px 2px;
}
table.av th.dh.today-hdr{background:#2b8a3e}
table.av th.dh.weekend{background:#17332a}
table.av th.dh .dw{font-size:.68rem;color:#a8d5b5;text-transform:uppercase;letter-spacing:.4px;display:block}
table.av th.dh .dd{font-size:1.15rem;font-weight:800;color:#fff;display:block;line-height:1.1}
table.av th.dh .dm{font-size:.63rem;color:#74c69d;display:block}
table.av th.dh.today-hdr .dw,table.av th.dh.today-hdr .dm{color:#d3f9d8}

/* Day cells */
table.av td.dc{
    border-left:1px solid #e9ecef;
    border-bottom:1px solid #e9ecef;
    height:56px;vertical-align:middle;
    padding:3px;min-width:82px;
}
table.av td.dc.today-col{border-left:3px solid #2b8a3e;border-right:1px solid #2b8a3e}
table.av td.dc.weekend{background:#f8f9fa}

/* Cells — available */
.c-avail{
    display:flex;height:50px;border-radius:6px;
    align-items:center;justify-content:center;
    background:#ffffff;border:1.5px solid #e9ecef;
    color:#ced4da;font-size:.68rem;font-weight:800;letter-spacing:.5px;
}

/* Cells — booked */
.c-book{
    display:flex;flex-direction:column;justify-content:space-between;
    height:50px;border-radius:6px;padding:5px 7px;
    cursor:default;position:relative;overflow:hidden;
    transition:filter .12s;
}
.c-book:hover{filter:brightness(.88)}
.c-book .cb-ch{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.3px;opacity:.9;line-height:1}
.c-book .cb-g {font-size:.75rem;font-weight:700;line-height:1.15;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.c-book .cb-d {font-size:.62rem;opacity:.75;line-height:1}

/* ── Upcoming bookings list ── */
.list-section{padding:18px 22px 28px}
.list-section h2{font-size:.9rem;font-weight:800;color:#1b3a2d;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.bk-table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.bk-table thead th{background:#1b3a2d;color:#fff;padding:10px 12px;font-size:.76rem;font-weight:700;text-align:left}
.bk-table tbody tr{border-bottom:1px solid #f1f3f5;transition:background .1s}
.bk-table tbody tr:hover{background:#f8f9fa}
.bk-table td{padding:9px 12px;font-size:.8rem;vertical-align:middle}
.bk-table .src-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:800;white-space:nowrap}
.bk-table .room-tag{font-weight:700;color:#1b3a2d}
.bk-table .guest-name{font-weight:600;color:#212529}
.bk-table .dates{color:#495057;font-size:.76rem}
.bk-table .nights{color:#868e96;font-size:.72rem}
.bk-table .amount{font-weight:700;color:#2b8a3e}
.no-bookings{text-align:center;padding:32px;color:#868e96;font-size:.88rem}

/* ── Tooltip ── */
#tip{
    position:fixed;z-index:9999;display:none;pointer-events:none;
    background:#0d1b2a;color:#fff;padding:12px 16px;border-radius:10px;
    font-size:.8rem;line-height:1.7;min-width:200px;max-width:240px;
    box-shadow:0 8px 32px rgba(0,0,0,.4);
}
#tip .t-h{font-weight:800;font-size:.88rem;margin-bottom:4px}
#tip .t-g{color:#69db7c}
#tip .t-d{color:#74c6ff;font-size:.72rem}
#tip .t-a{color:#ffd43b;font-weight:700;margin-top:4px}
#tip .t-n{color:#868e96;font-size:.7rem;margin-top:2px}

/* ── Modal ── */
.ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center}
.ov.open{display:flex}
.modal{background:#fff;border-radius:14px;width:100%;max-width:460px;padding:26px;position:relative;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.modal h3{font-size:.95rem;font-weight:800;margin-bottom:18px;color:#1b3a2d}
.modal .x{position:absolute;top:14px;right:14px;border:none;background:none;font-size:1.2rem;cursor:pointer;color:#868e96}
.fg{margin-top:12px}
.fg label{display:block;font-size:.77rem;font-weight:700;color:#495057;margin-bottom:4px}
.fg input,.fg select,.fg textarea{width:100%;padding:9px 11px;border:1.5px solid #dee2e6;border-radius:7px;font-size:.87rem;background:#f8f9fa;color:#212529}
.fg textarea{resize:vertical;min-height:56px}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.brow{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}
.btn{padding:9px 20px;border:none;border-radius:7px;font-size:.84rem;font-weight:700;cursor:pointer}
.btn.p{background:#7048e8;color:#fff}.btn.p:hover{background:#5f3dc4}
.btn.c{background:#f1f3f5;color:#495057}

@media(max-width:640px){
    .grid-wrap,.list-section{padding:10px 12px}
    .topbar,.ch-status,.toolbar,.stats-bar{padding:9px 12px}
}
</style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
    <h1>📅 Channel Availability Dashboard — Double Booking Prevention</h1>
    <a href="admin.php" class="back">← Admin</a>
</div>

<?php if ($saved): ?><div class="alert a-ok">✅ Booking saved and dates now blocked on grid.</div><?php endif; ?>

<!-- Channel Status -->
<div class="ch-status">
    <strong style="font-size:.72rem;color:#868e96;text-transform:uppercase;letter-spacing:.5px;margin-right:4px">OTAs:</strong>
    <span class="ch-pill <?= isset($connected['airbnb'])     ? 'pill-ok' : 'pill-no' ?>">
        <?= isset($connected['airbnb'])     ? '✅' : '❌' ?> Airbnb iCal
    </span>
    <span class="ch-pill <?= (isset($connected['booking.com'])||isset($connected['booking'])) ? 'pill-ok' : 'pill-no' ?>">
        <?= (isset($connected['booking.com'])||isset($connected['booking'])) ? '✅' : '❌' ?> Booking.com iCal
    </span>
    <span class="ch-pill <?= isset($connected['agoda']) ? 'pill-ok' : 'pill-no' ?>">
        <?= isset($connected['agoda']) ? '✅' : '❌' ?> Agoda
    </span>
    <span class="ch-pill pill-live">🌐 Website Direct — Live</span>
    <span class="ch-pill pill-live">📞 Phone/WhatsApp — Live</span>
    <div class="sync-info">
        🔄 OTA last sync: <strong><?= htmlspecialchars($lastSync) ?></strong>
        &nbsp;·&nbsp;<a href="admin.php?section=channels">Manage →</a>
    </div>
</div>

<?php
$missing = [];
if (!isset($connected['airbnb'])) $missing[] = 'Airbnb';
if (!isset($connected['booking.com']) && !isset($connected['booking'])) $missing[] = 'Booking.com';
if ($missing):
?>
<div class="alert a-warn">
    ⚠️ <strong><?= implode(' and ', $missing) ?></strong> iCal not connected — bookings from these platforms are invisible and risk double booking.
    <a href="admin.php?section=channels" style="margin-left:8px">Connect iCal now →</a>
</div>
<?php endif; ?>

<!-- Legend + Actions -->
<div class="toolbar">
    <div class="leg"><div class="ls avail"></div><span style="color:#ced4da">■</span> OPEN — available to book</div>
    <?php
    $shown = [];
    foreach ($SRC as $cfg):
        if (isset($shown[$cfg['label']])) continue; $shown[$cfg['label']] = true;
    ?>
    <div class="leg"><div class="ls" style="background:<?= $cfg['bg'] ?>"></div><?= htmlspecialchars($cfg['label']) ?></div>
    <?php endforeach; ?>
    <div class="toolbar-right">
        <a href="channel-dashboard.php" class="refresh-btn">↻ Refresh</a>
        <button class="add-btn" onclick="document.getElementById('addOv').classList.add('open')">
            + Add Phone / WhatsApp Booking
        </button>
    </div>
</div>

<!-- Stats -->
<?php if (!empty($srcStats)): ?>
<div class="stats-bar">
    <span style="font-size:.75rem;font-weight:700;color:#868e96;margin-right:4px">NEXT 14 DAYS:</span>
    <?php foreach ($srcStats as $lbl => $cnt):
        $bg = '#4a7c59';
        foreach ($SRC as $s => $c) { if ($c['label'] === $lbl) { $bg=$c['bg']; break; } }
    ?>
    <div class="s-chip" style="background:<?=$bg?>18;border:1.5px solid <?=$bg?>44">
        <span class="s-num" style="color:<?=$bg?>"><?=$cnt?></span>
        <span style="color:<?=$bg?>"><?=htmlspecialchars($lbl)?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     14-DAY AVAILABILITY GRID
═══════════════════════════════════════════════════════════ -->
<div class="grid-wrap">
<table class="av">
<thead>
<tr>
    <th class="rl-h">Room / Property</th>
    <?php foreach ($days as $day):
        $ds  = $day->format('Y-m-d');
        $dow = (int)$day->format('w');
        $isT = ($ds === $today->format('Y-m-d'));
        $isW = ($dow === 0 || $dow === 6);
    ?>
    <th class="dh <?= $isT?'today-hdr':($isW?'weekend':'') ?>">
        <span class="dw"><?= $DAYS_OF_WEEK[$dow] ?></span>
        <span class="dd"><?= $day->format('j') ?></span>
        <span class="dm"><?= $day->format('d M') ?></span>
    </th>
    <?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach (ROOM_IDS as $rid => $rname):
    $cells = $cellMap[$rid] ?? [];
?>
<tr>
    <td class="rl" title="<?= htmlspecialchars($rname) ?>"><?= htmlspecialchars($rname) ?></td>
    <?php foreach ($days as $day):
        $ds  = $day->format('Y-m-d');
        $b   = $cells[$ds] ?? null;
        $dow = (int)$day->format('w');
        $isT = ($ds === $today->format('Y-m-d'));
        $isW = ($dow === 0 || $dow === 6);
        $tdCls = 'dc'.($isT?' today-col':'').($isW?' weekend':'');
    ?>
    <td class="<?= $tdCls ?>">
    <?php if ($b):
        $cfg    = sc($b['source'], $SRC);
        $ci     = new DateTime($b['check_in']);
        $co     = new DateTime($b['check_out']);
        $nights = $ci->diff($co)->days;
        $esc    = fn($v)=>htmlspecialchars($v??'',ENT_QUOTES);
        $guest  = ($b['guest_name']==='Blocked'||$b['guest_name']==='')?'(Hold)':$b['guest_name'];
    ?>
        <div class="c-book"
            style="background:<?=$cfg['bg']?>;color:<?=$cfg['text']?>;border:1.5px solid <?=$cfg['border']?>"
            data-src="<?=$esc($cfg['label'])?>"
            data-guest="<?=$esc($b['guest_name'])?>"
            data-ci="<?=$esc($b['check_in'])?>"
            data-co="<?=$esc($b['check_out'])?>"
            data-nights="<?=$nights?>"
            data-amt="<?=$b['amount']>0?'₹'.number_format($b['amount']):''?>"
            data-ref="<?=$esc($b['booking_ref'])?>"
            data-notes="<?=$esc($b['notes'])?>"
            onmouseenter="showTip(event,this)" onmouseleave="hideTip()">
            <span class="cb-ch"><?=htmlspecialchars($cfg['label'])?></span>
            <span class="cb-g"><?=htmlspecialchars($guest)?></span>
            <span class="cb-d"><?=$ci->format('d M')?>→<?=$co->format('d M')?></span>
        </div>
    <?php else: ?>
        <div class="c-avail">OPEN</div>
    <?php endif; ?>
    </td>
    <?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- ═══════════════════════════════════════════════════════════
     UPCOMING BOOKINGS LIST — All sources, all rooms
═══════════════════════════════════════════════════════════ -->
<div class="list-section">
    <h2>
        📋 All Upcoming Bookings
        <span style="font-size:.75rem;font-weight:400;color:#868e96">(from every channel — direct, OTA, phone)</span>
    </h2>

    <?php if (empty($upcomingAll)): ?>
    <div class="no-bookings">No upcoming bookings found in the database.</div>
    <?php else: ?>
    <table class="bk-table">
        <thead>
            <tr>
                <th>Room</th>
                <th>Guest</th>
                <th>Check-in</th>
                <th>Check-out</th>
                <th>Nights</th>
                <th>Source</th>
                <th>Amount</th>
                <th>Notes / Ref</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($upcomingAll as $b):
            $cfg    = sc($b['source'], $SRC);
            $ci     = new DateTime($b['check_in']);
            $co     = new DateTime($b['check_out']);
            $nights = $ci->diff($co)->days;
            $isPast = $b['check_in'] < $today->format('Y-m-d');
            $isNow  = $b['check_in'] <= $today->format('Y-m-d') && $b['check_out'] > $today->format('Y-m-d');
        ?>
        <tr style="<?= $isNow ? 'background:#f0fff4' : ($isPast?'opacity:.6':'') ?>">
            <td class="room-tag"><?= htmlspecialchars($b['room_name'] ?: (ROOM_IDS[$b['room_id']] ?? $b['room_id'])) ?></td>
            <td class="guest-name">
                <?= htmlspecialchars($b['guest_name']) ?>
                <?php if ($b['guest_phone']): ?><br><span style="color:#868e96;font-size:.7rem">📞 <?= htmlspecialchars($b['guest_phone']) ?></span><?php endif; ?>
            </td>
            <td class="dates">
                <?= $ci->format('d M Y') ?>
                <?php if ($isNow): ?><br><span style="color:#2b8a3e;font-size:.7rem;font-weight:700">● IN HOUSE</span><?php endif; ?>
            </td>
            <td class="dates"><?= $co->format('d M Y') ?></td>
            <td class="nights" style="text-align:center"><?= $nights ?></td>
            <td>
                <span class="src-badge" style="background:<?=$cfg['bg']?>22;color:<?=$cfg['bg']?>;border:1.5px solid <?=$cfg['bg']?>55">
                    <?= htmlspecialchars($cfg['label']) ?>
                </span>
            </td>
            <td class="amount"><?= $b['amount']>0 ? '₹'.number_format($b['amount']) : '<span style="color:#ced4da">—</span>' ?></td>
            <td style="color:#868e96;font-size:.73rem;max-width:160px">
                <?php
                $ref   = $b['booking_ref'] ?? '';
                $notes = $b['notes'] ?? '';
                if ($ref)   echo '<div>Ref: '.htmlspecialchars(substr($ref,0,30)).'</div>';
                if ($notes) echo '<div>'.htmlspecialchars(substr($notes,0,60)).'</div>';
                ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Tooltip -->
<div id="tip"></div>

<!-- Add Manual Booking Modal -->
<div class="ov" id="addOv">
<div class="modal">
    <button class="x" onclick="document.getElementById('addOv').classList.remove('open')">✕</button>
    <h3>📞 Add Phone / WhatsApp Booking</h3>
    <form method="POST">
    <input type="hidden" name="action" value="add_manual">
    <div class="fg">
        <label>Guest Name *</label>
        <input type="text" name="guest_name" required placeholder="e.g. Priya Sharma">
    </div>
    <div class="frow">
        <div class="fg"><label>Phone</label><input type="tel" name="guest_phone" placeholder="+91 98765 43210"></div>
        <div class="fg"><label>Email</label><input type="email" name="guest_email" placeholder="optional"></div>
    </div>
    <div class="frow">
        <div class="fg"><label>Check-in *</label><input type="date" name="check_in" id="ci_in" required></div>
        <div class="fg"><label>Check-out *</label><input type="date" name="check_out" id="ci_out" required></div>
    </div>
    <div class="fg">
        <label>Room *</label>
        <select name="room_id" required>
            <option value="">— Select room —</option>
            <?php foreach (ROOM_IDS as $rid=>$rn): ?>
            <option value="<?=htmlspecialchars($rid)?>"><?=htmlspecialchars($rn)?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fg">
        <label>Booking Source</label>
        <select name="source">
            <option value="manual">📞 Phone / WhatsApp</option>
            <option value="direct">🌐 Direct / Walk-in</option>
            <option value="blocked">🚫 Block dates (hold / maintenance)</option>
        </select>
    </div>
    <div class="frow">
        <div class="fg"><label>Amount (₹)</label><input type="number" name="amount" placeholder="0" min="0"></div>
        <div class="fg"><label>Payment</label>
            <select name="payment_status">
                <option value="unpaid">Unpaid</option>
                <option value="partial">Partial</option>
                <option value="paid">Paid ✅</option>
            </select>
        </div>
    </div>
    <div class="fg"><label>Notes</label><textarea name="notes" placeholder="Confirmed via WhatsApp, special request…"></textarea></div>
    <div class="brow">
        <button type="button" class="btn c" onclick="document.getElementById('addOv').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn p">💾 Save &amp; Block Dates</button>
    </div>
    </form>
</div>
</div>

<script>
// ── Tooltip ──────────────────────────────────────────────────
const tip = document.getElementById('tip');
function showTip(e, el) {
    const d = el.dataset;
    const n = parseInt(d.nights)||0;
    let h = `<div class="t-h">${d.src}</div>`;
    if (d.guest && d.guest!=='Blocked') h += `<div class="t-g">👤 ${d.guest}</div>`;
    h += `<div class="t-d">📅 ${d.ci} → ${d.co}</div>`;
    h += `<div class="t-d">🌙 ${n} night${n!==1?'s':''}</div>`;
    if (d.amt) h += `<div class="t-a">💰 ${d.amt}</div>`;
    if (d.ref) h += `<div class="t-n">Ref: ${d.ref}</div>`;
    if (d.notes) h += `<div class="t-n">📝 ${d.notes.substring(0,70)}</div>`;
    tip.innerHTML = h; tip.style.display='block'; moveTip(e);
}
function moveTip(e){
    const x=e.clientX+14,y=e.clientY+14;
    tip.style.left=(x+250>innerWidth?x-260:x)+'px';
    tip.style.top=(y+170>innerHeight?y-180:y)+'px';
}
document.addEventListener('mousemove',e=>{if(tip.style.display==='block')moveTip(e);});
function hideTip(){tip.style.display='none';}

// ── Close overlay ────────────────────────────────────────────
document.querySelectorAll('.ov').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)o.classList.remove('open');}));

// ── Pre-fill today / tomorrow ────────────────────────────────
(function(){
    const t=new Date(),t1=new Date(t);t1.setDate(t.getDate()+1);
    const f=d=>d.toISOString().slice(0,10);
    document.getElementById('ci_in').value=f(t);
    document.getElementById('ci_out').value=f(t1);
})();
</script>
</body>
</html>
