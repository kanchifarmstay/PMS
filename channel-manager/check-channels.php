<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');

$db = getDB();

// Show what we're about to delete
$toDelete = $db->query("
    SELECT id, room_id, source, guest_name, check_in, check_out, notes
    FROM bookings
    WHERE is_sync_imported = 1
      AND status = 'confirmed'
      AND (
          LOWER(notes)      LIKE '%not available%'
       OR LOWER(notes)      LIKE '%closed%'
       OR LOWER(notes)      LIKE '%unavailable%'
       OR LOWER(guest_name) LIKE '%not available%'
       OR LOWER(guest_name) LIKE '%closed%'
      )
")->fetchAll();

echo "Records to delete (" . count($toDelete) . "):\n";
foreach ($toDelete as $r) {
    echo "  ID:{$r['id']} [{$r['source']}] {$r['room_id']} | {$r['check_in']} → {$r['check_out']} | \"{$r['notes']}\"\n";
}

// Delete them
$db->exec("
    DELETE FROM bookings
    WHERE is_sync_imported = 1
      AND status = 'confirmed'
      AND (
          LOWER(notes)      LIKE '%not available%'
       OR LOWER(notes)      LIKE '%closed%'
       OR LOWER(notes)      LIKE '%unavailable%'
       OR LOWER(guest_name) LIKE '%not available%'
       OR LOWER(guest_name) LIKE '%closed%'
      )
");

echo "\n✅ Deleted " . count($toDelete) . " not-available / closed records.\n";

// Run a fresh sync to confirm they don't come back
echo "\nRunning sync to verify fix holds...\n";
require_once __DIR__ . '/sync.php';
$calendars = getExternalCalendars();
$reimported = 0;
foreach ($calendars as $cal) {
    $res = syncOneCalendar($cal);
    if ($res['imported'] > 0) {
        echo "  [{$cal['platform']}] {$cal['room_id']}: {$res['imported']} new booking(s) imported\n";
        $reimported += $res['imported'];
    }
}

// Check if any not-available snuck back in
$remaining = $db->query("
    SELECT COUNT(*) FROM bookings
    WHERE is_sync_imported = 1 AND status = 'confirmed'
      AND (LOWER(notes) LIKE '%not available%' OR LOWER(notes) LIKE '%closed%')
")->fetchColumn();

echo "\nNot-available records after sync: $remaining\n";
if ($remaining == 0) {
    echo "✅ Fix confirmed — no not-available bookings are being imported.\n";
} else {
    echo "⚠️ Some records still slipping through — check sync.php filter.\n";
}

echo "\nAll confirmed bookings now in DB (is_sync_imported=1):\n";
$real = $db->query("
    SELECT room_id, source, guest_name, check_in, check_out
    FROM bookings
    WHERE is_sync_imported=1 AND status='confirmed' AND check_out >= date('now')
    ORDER BY check_in
")->fetchAll();
foreach ($real as $r) {
    echo "  [{$r['source']}] {$r['room_id']} | {$r['check_in']} → {$r['check_out']} | \"{$r['guest_name']}\"\n";
}
if (empty($real)) echo "  None.\n";
echo "\nDone.\n";
