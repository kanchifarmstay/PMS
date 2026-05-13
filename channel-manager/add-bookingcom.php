<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');

$channels = [
    ['wooden-villa', 'booking.com', 'https://ical.booking.com/v1/export?t=6bb81e0d-cb06-42ec-9f7e-5e1a19b907e0'],
    ['white-villa',  'booking.com', 'https://ical.booking.com/v1/export?t=4aac9520-5466-4ce8-82f8-cc32f5feb37f'],
    ['natures-nest', 'booking.com', 'https://ical.booking.com/v1/export?t=815d4e0e-4bcd-4b16-ac72-553622671b06'],
];

$db = getDB();

// Remove existing booking.com entries for these rooms so we start clean
foreach ($channels as [$roomId,,]) {
    $db->prepare("DELETE FROM external_calendars WHERE room_id = ? AND platform = 'booking.com'")->execute([$roomId]);
}

$added = 0;
foreach ($channels as [$roomId, $platform, $url]) {
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url, is_active) VALUES (?,?,?,1)")
       ->execute([$roomId, $platform, $url]);
    $roomName = ROOM_IDS[$roomId] ?? $roomId;
    echo "ADDED: [$platform] $roomName\n";
    $added++;
}

echo "\n✅ $added Booking.com channels added.\n\n";

// Run sync immediately
require_once __DIR__ . '/sync.php';
$calendars = getExternalCalendars();
echo "Running sync...\n";
foreach ($calendars as $cal) {
    if ($cal['platform'] !== 'booking.com') continue;
    $res = syncOneCalendar($cal);
    $roomName = ROOM_IDS[$cal['room_id']] ?? $cal['room_id'];
    if ($res['success']) {
        echo "  ✅ $roomName — {$res['imported']} new booking(s) imported\n";
    } else {
        echo "  ❌ $roomName — {$res['error']}\n";
    }
}

echo "\nAll external calendars now in DB:\n";
$rows = $db->query("SELECT room_id, platform, last_synced FROM external_calendars ORDER BY platform, room_id")->fetchAll();
foreach ($rows as $r) {
    echo "  [{$r['platform']}] " . (ROOM_IDS[$r['room_id']] ?? $r['room_id']) . " — last synced: " . ($r['last_synced'] ?? 'never') . "\n";
}
echo "\nDone. Delete this file.\n";
