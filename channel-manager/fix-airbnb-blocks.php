<?php
/**
 * One-time cleanup: remove Airbnb auto-block records that slipped in
 * with guest_name like 'Blocked', 'Not available', etc.
 * Safe — only touches is_sync_imported=1 rows (auto-imported).
 * Delete this file after running.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');

$db = getDB();

$toDelete = $db->query("
    SELECT id, room_id, source, guest_name, check_in, check_out, notes
    FROM bookings
    WHERE is_sync_imported = 1
      AND status = 'confirmed'
      AND (
          LOWER(guest_name) IN ('blocked','not available','unavailable','closed','maintenance','hold','owner stay','owner block')
       OR LOWER(notes) LIKE '%airbnb (blocked)%'
       OR LOWER(notes) LIKE '%(blocked)%'
      )
")->fetchAll();

echo "Records to delete (" . count($toDelete) . "):\n";
foreach ($toDelete as $r) {
    echo "  ID:{$r['id']} [{$r['source']}] {$r['room_id']} | {$r['check_in']} → {$r['check_out']} | guest:\"{$r['guest_name']}\" | notes:\"{$r['notes']}\"\n";
}

if (empty($toDelete)) {
    echo "\n✅ No stale Airbnb block records found — database is clean.\n";
} else {
    $db->exec("
        DELETE FROM bookings
        WHERE is_sync_imported = 1
          AND status = 'confirmed'
          AND (
              LOWER(guest_name) IN ('blocked','not available','unavailable','closed','maintenance','hold','owner stay','owner block')
           OR LOWER(notes) LIKE '%airbnb (blocked)%'
           OR LOWER(notes) LIKE '%(blocked)%'
          )
    ");
    echo "\n✅ Deleted " . count($toDelete) . " stale Airbnb block record(s).\n";
}

// Also update any remaining Airbnb "Guest" records to "Airbnb Guest"
$updated = $db->exec("
    UPDATE bookings
    SET guest_name = 'Airbnb Guest'
    WHERE is_sync_imported = 1
      AND source = 'airbnb'
      AND guest_name = 'Guest'
");
echo "✅ Renamed $updated Airbnb 'Guest' → 'Airbnb Guest'.\n";

echo "\nRemaining Airbnb bookings:\n";
$rows = $db->query("
    SELECT id, room_id, guest_name, check_in, check_out, notes
    FROM bookings
    WHERE source = 'airbnb' AND status = 'confirmed' AND check_out >= date('now')
    ORDER BY check_in
")->fetchAll();
foreach ($rows as $r) {
    echo "  ID:{$r['id']} {$r['room_id']} | {$r['check_in']} → {$r['check_out']} | \"{$r['guest_name']}\"\n";
}
if (empty($rows)) echo "  None.\n";
echo "\nDone. Delete this file.\n";
