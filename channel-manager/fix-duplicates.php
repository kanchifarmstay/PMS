<?php
/**
 * One-time cleanup: remove duplicate bookings keeping the oldest record
 * (lowest ID) for each room_id + check_in + check_out + status group.
 * Delete this file after running.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');

$db = getDB();

// Find all groups with more than one booking for same room + dates + status
$dupes = $db->query("
    SELECT room_id, check_in, check_out, status,
           COUNT(*) AS cnt,
           MIN(id)  AS keep_id,
           GROUP_CONCAT(id ORDER BY id) AS all_ids
    FROM bookings
    GROUP BY room_id, check_in, check_out, status
    HAVING COUNT(*) > 1
")->fetchAll();

if (empty($dupes)) {
    echo "✅ No duplicate bookings found — database is clean.\n";
    exit;
}

echo "Duplicate groups found: " . count($dupes) . "\n\n";
$totalDeleted = 0;

foreach ($dupes as $g) {
    echo "  Room: {$g['room_id']} | {$g['check_in']} → {$g['check_out']} | status:{$g['status']}\n";
    echo "  All IDs: {$g['all_ids']} → keeping ID {$g['keep_id']}, deleting the rest\n";

    // Show the details of what we're keeping
    $keep = $db->prepare("SELECT guest_name, guest_phone, amount, source FROM bookings WHERE id = ?");
    $keep->execute([$g['keep_id']]);
    $kr = $keep->fetch();
    echo "  Keeping: guest=\"{$kr['guest_name']}\" phone=\"{$kr['guest_phone']}\" amount={$kr['amount']} source={$kr['source']}\n";

    $del = $db->prepare("
        DELETE FROM bookings
        WHERE room_id   = ?
          AND check_in  = ?
          AND check_out = ?
          AND status    = ?
          AND id       != ?
    ");
    $del->execute([$g['room_id'], $g['check_in'], $g['check_out'], $g['status'], $g['keep_id']]);
    $deleted = $del->rowCount();
    $totalDeleted += $deleted;
    echo "  Deleted $deleted duplicate(s)\n\n";
}

echo "✅ Done. Removed $totalDeleted duplicate record(s) total.\n\n";

// Show remaining confirmed bookings
echo "Remaining confirmed bookings:\n";
$rows = $db->query("
    SELECT id, room_id, guest_name, check_in, check_out, source
    FROM bookings
    WHERE status = 'confirmed' AND check_out >= date('now')
    ORDER BY check_in
")->fetchAll();
foreach ($rows as $r) {
    echo "  ID:{$r['id']} [{$r['source']}] {$r['room_id']} | {$r['check_in']} → {$r['check_out']} | \"{$r['guest_name']}\"\n";
}
if (empty($rows)) echo "  None.\n";
echo "\nDelete this file after reviewing.\n";
