<?php
/**
 * cron.php — called by Hostinger cron every 30 minutes.
 * Syncs all connected OTA calendars and seeds demand events.
 *
 * Cron command (set in Hostinger hPanel → Advanced → Cron Jobs):
 *   php /home/USERNAME/domains/kanchifarmstay.com/public_html/channel-manager/cron.php
 *
 * Interval: every 30 minutes  →  set schedule: *\/30 * * * *
 */

// Safety: only run from CLI or with a secret token (prevents casual web access)
$isCli = php_sapi_name() === 'cli';
$token = $_GET['token'] ?? '';
define('CRON_SECRET', 'kanchi-cron-2025');

if (!$isCli && $token !== CRON_SECRET) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp.php';

$startTime = microtime(true);
$log = [];

function cronLog(string $msg): void {
    global $log;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    $log[] = $line;
    if (php_sapi_name() === 'cli') {
        echo $line . "\n";
    }
}

cronLog("=== Kanchi Farm Stay — Cron Sync Start ===");

// ── 1. Sync all OTA calendars ─────────────────────────────────
require_once __DIR__ . '/sync.php';

$calendars    = getExternalCalendars();
$totalImported = 0;
$allNewBooks  = [];
$errors       = [];

if (empty($calendars)) {
    cronLog("No calendars connected. Visit /channel-manager/setup-wizard.php to add iCal URLs.");
} else {
    foreach ($calendars as $cal) {
        if (!$cal['is_active']) {
            cronLog("SKIP [{$cal['platform']}] {$cal['room_id']} — paused");
            continue;
        }

        $res = syncOneCalendar($cal);

        if ($res['success']) {
            $totalImported += $res['imported'];
            cronLog("OK   [{$cal['platform']}] {$cal['room_id']} — {$res['imported']} new booking(s)");
            if (!empty($res['new_bookings'])) {
                $allNewBooks = array_merge($allNewBooks, $res['new_bookings']);
            }
        } else {
            $errors[] = "[{$cal['platform']}] {$cal['room_id']}: {$res['error']}";
            cronLog("FAIL [{$cal['platform']}] {$cal['room_id']} — {$res['error']}");
        }
    }
}

// ── 2. WhatsApp notifications for new bookings ────────────────
foreach ($allNewBooks as $b) {
    sendWhatsAppNotification(buildBookingMessage($b));
    cronLog("WHATSAPP sent for {$b['guest_name']} ({$b['room_name']})");
}

// ── 3. Re-seed demand events daily (first run of the day) ─────
$lastSeedFile = __DIR__ . '/.last_demand_seed';
$today = date('Y-m-d');
$lastSeed = @file_get_contents($lastSeedFile);

if ($lastSeed !== $today) {
    require_once __DIR__ . '/demand-engine.php';
    $seeded = seedDemandEvents();
    cronLog("Demand events re-seeded: {$seeded} events");
    file_put_contents($lastSeedFile, $today);
}

// ── 4. Write log file ─────────────────────────────────────────
$elapsed = round(microtime(true) - $startTime, 2);
cronLog("=== Done in {$elapsed}s — {$totalImported} new booking(s) imported ===");

$logFile = __DIR__ . '/cron.log';
$existing = @file_get_contents($logFile) ?: '';
// Keep last 200 lines
$allLines = array_merge($log, explode("\n", trim($existing)));
$allLines  = array_slice($allLines, 0, 200);
file_put_contents($logFile, implode("\n", $allLines) . "\n");

// ── 5. Summary output ─────────────────────────────────────────
if (!$isCli) {
    header('Content-Type: application/json');
    echo json_encode([
        'status'        => empty($errors) ? 'ok' : 'partial',
        'imported'      => $totalImported,
        'new_bookings'  => count($allNewBooks),
        'errors'        => $errors,
        'elapsed_sec'   => $elapsed,
        'timestamp'     => date('Y-m-d H:i:s'),
    ]);
}
