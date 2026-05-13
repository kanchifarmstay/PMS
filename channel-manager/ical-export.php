<?php
/**
 * iCal Export — RFC 5545 compliant feed for one room.
 * Compatible with Airbnb, Booking.com, Agoda, MakeMyTrip.
 *
 * URL: /channel-manager/ical-export.php?room=wooden-villa&token=ksf-ical-secret-2025
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$roomId = trim($_GET['room'] ?? '');
$token  = trim($_GET['token'] ?? '');
$rooms  = ROOM_IDS;

if ($token !== ICAL_TOKEN) { http_response_code(403); exit('Invalid token.'); }
if (!$roomId || !isset($rooms[$roomId])) { http_response_code(404); exit('Room not found.'); }

$roomName = $rooms[$roomId];

$db   = getDB();
$stmt = $db->prepare("
    SELECT * FROM bookings
    WHERE room_id = ? AND status = 'confirmed'
      AND check_out >= date('now', '-1 year')
    ORDER BY check_in
");
$stmt->execute([$roomId]);
$bookings = $stmt->fetchAll();

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="' . $roomId . '.ics"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

$now     = gmdate('Ymd\THis\Z');
$siteUrl = SITE_URL;

// ── Calendar header ──────────────────────────────────────────
echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//Kanchi Farm Stay//Channel Manager//EN\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "X-WR-CALNAME:Kanchi Farm Stay - {$roomName}\r\n";
echo "X-WR-CALDESC:Availability calendar for {$roomName}\r\n";
echo "X-WR-TIMEZONE:Asia/Kolkata\r\n";
echo "REFRESH-INTERVAL;VALUE=DURATION:PT1H\r\n";
echo "X-PUBLISHED-TTL:PT1H\r\n";

// ── VTIMEZONE block (required by Booking.com) ────────────────
echo "BEGIN:VTIMEZONE\r\n";
echo "TZID:Asia/Kolkata\r\n";
echo "BEGIN:STANDARD\r\n";
echo "DTSTART:19700101T000000\r\n";
echo "TZOFFSETFROM:+0530\r\n";
echo "TZOFFSETTO:+0530\r\n";
echo "TZNAME:IST\r\n";
echo "END:STANDARD\r\n";
echo "END:VTIMEZONE\r\n";

// ── Booking.com requires at least one VEVENT in the feed.
//    We include a single far-past placeholder so the feed is
//    never empty, which would cause Booking.com to reject it.
echo "BEGIN:VEVENT\r\n";
echo "UID:kanchi-calendar-init-{$roomId}@kanchifarmstay.com\r\n";
echo "DTSTAMP:{$now}\r\n";
echo "DTSTART;VALUE=DATE:20200101\r\n";
echo "DTEND;VALUE=DATE:20200102\r\n";
echo "SUMMARY:Calendar Active\r\n";
echo "STATUS:CONFIRMED\r\n";
echo "TRANSP:TRANSPARENT\r\n";
echo "END:VEVENT\r\n";

// ── Real bookings ────────────────────────────────────────────
foreach ($bookings as $b) {
    $uid      = $b['uid'] ?: ('bk-' . $b['id'] . '@kanchifarmstay.com');
    $dtStart  = date('Ymd', strtotime($b['check_in']));
    $dtEnd    = date('Ymd', strtotime($b['check_out']));
    $sourceUC = strtoupper($b['source'] ?? 'DIRECT');
    $summary  = 'Booked';   // Booking.com prefers a plain summary
    $guest    = ($b['guest_name'] && $b['guest_name'] !== 'Blocked') ? $b['guest_name'] : 'Guest';
    $desc     = "Guest: {$guest} | Source: {$sourceUC}";
    if (!empty($b['booking_ref'])) $desc .= " | Ref: {$b['booking_ref']}";

    echo "BEGIN:VEVENT\r\n";
    echo "UID:{$uid}\r\n";
    echo "DTSTAMP:{$now}\r\n";
    echo "DTSTART;VALUE=DATE:{$dtStart}\r\n";
    echo "DTEND;VALUE=DATE:{$dtEnd}\r\n";
    echo "SUMMARY:{$summary}\r\n";
    echo "DESCRIPTION:{$desc}\r\n";
    echo "STATUS:CONFIRMED\r\n";
    echo "TRANSP:OPAQUE\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";
