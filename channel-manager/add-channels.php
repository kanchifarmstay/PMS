<?php
/**
 * One-time: insert all 9 Airbnb iCal feeds directly into DB.
 * No sync — cron will handle that. Delete after running.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
header('Content-Type: text/plain');

$channels = [
    ['tent',                  'airbnb', 'https://www.airbnb.co.in/calendar/ical/1616283714445728885.ics?t=b1abbfd0d5754bfca42aa7ee965be944'],
    ['kanchi-farm-stay',      'airbnb', 'https://www.airbnb.co.in/calendar/ical/1606525489417032253.ics?t=156930b727fb41e9ad9e8914b283b45a'],
    ['natures-nest',          'airbnb', 'https://www.airbnb.co.in/calendar/ical/1291620643453676424.ics?t=c1fc09475b5a4137a586e84aa7a85a25'],
    ['tranquil-retreat',      'airbnb', 'https://www.airbnb.co.in/calendar/ical/1243134117782394958.ics?t=15287ad17c5549b992e5bbb3abcf7ded'],
    ['white-villa-full-floor','airbnb', 'https://www.airbnb.co.in/calendar/ical/1663361418637337296.ics?t=3468f784f50845f4a4a192416e0a0623'],
    ['white-villa',           'airbnb', 'https://www.airbnb.co.in/calendar/ical/1291628873858653856.ics?t=69428004812a459aa70f0f6993f8c580'],
    ['white-villa-room-2',    'airbnb', 'https://www.airbnb.co.in/calendar/ical/1663350076137466403.ics?t=b851e85457d7401c8e8555e970d30456'],
    ['wooden-cottage',        'airbnb', 'https://www.airbnb.co.in/calendar/ical/1606520319311841383.ics?t=18181ffb2e6d4766a20f286bb8904374'],
    ['wooden-villa',          'airbnb', 'https://www.airbnb.co.in/calendar/ical/1573233534530395069.ics?t=eae88a9c9cee42c8a7ebe80ede74b791'],
];

$db = getDB();

// Wipe existing airbnb entries so we start clean
$db->exec("DELETE FROM external_calendars WHERE platform = 'airbnb'");
echo "Cleared existing Airbnb entries.\n\n";

$added = 0;
foreach ($channels as [$roomId, $platform, $url]) {
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url, is_active) VALUES (?,?,?,1)")
       ->execute([$roomId, $platform, $url]);
    $roomName = ROOM_IDS[$roomId] ?? $roomId;
    echo "ADDED: [$platform] $roomName\n";
    $added++;
}

echo "\n✅ $added Airbnb channels added successfully.\n";
echo "\nCurrent DB state:\n";
$rows = $db->query("SELECT room_id, platform FROM external_calendars ORDER BY platform, room_id")->fetchAll();
foreach ($rows as $r) {
    echo "  - {$r['platform']} → {$r['room_id']}\n";
}
echo "\nDone. Delete this file.\n";
