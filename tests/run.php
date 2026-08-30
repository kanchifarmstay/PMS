<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$configPath = dirname(__DIR__) . '/channel-manager/config.php';
$securityPath = dirname(__DIR__) . '/channel-manager/security.php';
$configSource = file_get_contents($configPath);

test('configuration contains no shipped production secrets', function () use ($configSource): void {
    foreach (['KanchiFarm2025!', 'ksf-ical-secret-2025', 'kanchi-cron-2025', 'rzp_live_', '2HSD20TrjbZSB2PI4l2L6zYk'] as $secret) {
        assertNotContains($secret, $configSource);
    }
    assertContains('getenv', $configSource);
});

if (is_file($securityPath)) {
    require_once $configPath;
    require_once $securityPath;
}

test('admin password uses the configured hash', function (): void {
    assertTrue(function_exists('verifyAdminPassword'), 'verifyAdminPassword is not implemented');
    assertTrue(verifyAdminPassword('test-secret'));
    assertFalse(verifyAdminPassword('wrong-secret'));
});

test('room identifiers are restricted to configured inventory', function (): void {
    assertTrue(function_exists('isValidRoomId'), 'isValidRoomId is not implemented');
    assertTrue(isValidRoomId('wooden-villa'));
    assertFalse(isValidRoomId('unknown-room'));
});

test('calendar URL validation permits HTTPS OTA feeds', function (): void {
    assertTrue(function_exists('isSafeCalendarUrl'), 'isSafeCalendarUrl is not implemented');
    assertTrue(isSafeCalendarUrl('https://www.airbnb.com/calendar/ical/example.ics'));
});

test('calendar URL validation rejects unsafe schemes and private targets', function (): void {
    assertFalse(function_exists('isSafeCalendarUrl') && isSafeCalendarUrl('http://example.com/feed.ics'));
    assertFalse(function_exists('isSafeCalendarUrl') && isSafeCalendarUrl('file:///etc/passwd'));
    assertFalse(function_exists('isSafeCalendarUrl') && isSafeCalendarUrl('https://127.0.0.1/feed.ics'));
    assertFalse(function_exists('isSafeCalendarUrl') && isSafeCalendarUrl('https://localhost/feed.ics'));
});

test('CSRF tokens are generated and checked with constant-time semantics', function (): void {
    assertTrue(function_exists('csrfToken'), 'csrfToken is not implemented');
    $_SESSION = [];
    $token = csrfToken();
    assertTrue(strlen($token) >= 32);
    assertTrue(validateCsrfToken($token));
    assertFalse(validateCsrfToken($token . 'x'));
});

$dbPath = dirname(__DIR__) . '/channel-manager/db.php';
$bookingServicePath = dirname(__DIR__) . '/channel-manager/booking-service.php';
require_once $dbPath;
if (is_file($bookingServicePath)) require_once $bookingServicePath;

function resetAvailabilityData(): void
{
    $db = getDB();
    foreach (['payment_orders', 'booking_holds', 'external_blocks', 'bookings', 'external_calendars'] as $table) {
        try { $db->exec("DELETE FROM {$table}"); } catch (Throwable) { /* table not implemented yet */ }
    }
}

test('availability schema separates external blocks and payment holds', function (): void {
    $tables = getDB()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    assertTrue(in_array('external_blocks', $tables, true), 'external_blocks table is missing');
    assertTrue(in_array('booking_holds', $tables, true), 'booking_holds table is missing');
    assertTrue(in_array('payment_orders', $tables, true), 'payment_orders table is missing');
});

test('inventory dependencies are bidirectional', function (): void {
    assertTrue(function_exists('relatedInventoryIds'), 'relatedInventoryIds is not implemented');
    assertSame(
        ['kanchi-farm-stay', 'white-villa', 'white-villa-full-floor'],
        relatedInventoryIds('white-villa')
    );
    assertSame(
        ['kanchi-farm-stay', 'white-villa', 'white-villa-full-floor', 'white-villa-room-2'],
        relatedInventoryIds('white-villa-full-floor')
    );
    assertSame(count(ROOM_IDS), count(relatedInventoryIds('kanchi-farm-stay')));
});

test('a component booking blocks its parent but not unrelated inventory', function (): void {
    resetAvailabilityData();
    addBooking([
        'room_id'=>'white-villa', 'room_name'=>ROOM_IDS['white-villa'],
        'check_in'=>'2030-01-10', 'check_out'=>'2030-01-12', 'guest_name'=>'Test Guest',
    ]);
    assertFalse(isInventoryAvailable('white-villa-full-floor', '2030-01-11', '2030-01-13'));
    assertTrue(isInventoryAvailable('wooden-villa', '2030-01-11', '2030-01-13'));
    assertTrue(isInventoryAvailable('white-villa', '2030-01-12', '2030-01-13'), 'checkout day must remain available');
});

test('an external component block blocks parent and whole-property inventory', function (): void {
    resetAvailabilityData();
    $db = getDB();
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['white-villa-room-2', 'airbnb', 'https://example.com/feed.ics']);
    $calendarId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out) VALUES (?,?,?,?,?,?)")
       ->execute([$calendarId, 'white-villa-room-2', 'airbnb', 'ext-1', '2030-02-01', '2030-02-03']);
    assertFalse(isInventoryAvailable('white-villa-full-floor', '2030-02-02', '2030-02-04'));
    assertFalse(isInventoryAvailable('kanchi-farm-stay', '2030-02-02', '2030-02-04'));
});

test('payment holds are exclusive and expired holds are ignored', function (): void {
    resetAvailabilityData();
    assertTrue(function_exists('createBookingHold'), 'createBookingHold is not implemented');
    $hold = createBookingHold([
        'room_id'=>'wooden-villa', 'check_in'=>'2030-03-10', 'check_out'=>'2030-03-12',
        'guest_name'=>'First Guest', 'guest_email'=>'first@example.com', 'guest_phone'=>'9999999999',
        'adults'=>2, 'children'=>1, 'amount'=>6000,
    ]);
    assertTrue(is_array($hold) && !empty($hold['token']));
    assertFalse(isInventoryAvailable('wooden-villa', '2030-03-11', '2030-03-13'));
    assertTrue(isInventoryAvailable('wooden-villa', '2030-03-10', '2030-03-12', null, $hold['token']));

    $thrown = false;
    try {
        createBookingHold([
            'room_id'=>'wooden-villa', 'check_in'=>'2030-03-11', 'check_out'=>'2030-03-13',
            'guest_name'=>'Second Guest', 'guest_email'=>'second@example.com', 'guest_phone'=>'8888888888',
            'adults'=>2, 'children'=>0, 'amount'=>6000,
        ]);
    } catch (DomainException) { $thrown = true; }
    assertTrue($thrown, 'overlapping hold should be rejected');

    getDB()->prepare("UPDATE booking_holds SET expires_at='2000-01-01 00:00:00' WHERE token=?")->execute([$hold['token']]);
    assertTrue(isInventoryAvailable('wooden-villa', '2030-03-10', '2030-03-12'));
});

$icalServicePath = dirname(__DIR__) . '/channel-manager/ical.php';
if (is_file($icalServicePath)) require_once $icalServicePath;

function fixtureCalendar(array $events, string $newline = "\r\n"): string
{
    return implode($newline, array_merge(['BEGIN:VCALENDAR', 'VERSION:2.0'], $events, ['END:VCALENDAR', '']));
}

test('iCal parser unfolds LF and CRLF continuations and keeps parameters', function (): void {
    assertTrue(function_exists('parseIcalEvents'), 'parseIcalEvents is not implemented');
    $raw = fixtureCalendar([
        'BEGIN:VEVENT', 'UID:folded-1', 'DTSTART;VALUE=DATE:20300401', 'DTEND;VALUE=DATE:20300403',
        'SUMMARY:Not avail', ' able', 'END:VEVENT',
    ], "\n");
    $events = parseIcalEvents($raw);
    assertSame(1, count($events));
    assertSame('Not available', $events[0]['SUMMARY']['value']);
    assertSame('DATE', $events[0]['DTSTART']['params']['VALUE']);
});

test('Airbnb unavailable and Booking.com closed events are imported as blocks', function (): void {
    $raw = fixtureCalendar([
        'BEGIN:VEVENT', 'UID:air-1', 'DTSTART;VALUE=DATE:20300401', 'DTEND;VALUE=DATE:20300403', 'SUMMARY:Not available', 'END:VEVENT',
        'BEGIN:VEVENT', 'UID:book-1', 'DTSTART;VALUE=DATE:20300404', 'DTEND;VALUE=DATE:20300405', 'SUMMARY:CLOSED - Not available', 'END:VEVENT',
        'BEGIN:VEVENT', 'UID:cancel-1', 'DTSTART;VALUE=DATE:20300406', 'DTEND;VALUE=DATE:20300407', 'STATUS:CANCELLED', 'END:VEVENT',
        'BEGIN:VEVENT', 'UID:transparent-1', 'DTSTART;VALUE=DATE:20300408', 'DTEND;VALUE=DATE:20300409', 'TRANSP:TRANSPARENT', 'END:VEVENT',
    ]);
    $blocks = normalizeIcalBlocks($raw, ['id'=>1, 'room_id'=>'wooden-villa', 'platform'=>'airbnb'], '2030-01-01');
    assertSame(['air-1', 'book-1'], array_column($blocks, 'external_uid'));
});

test('UTC iCal date-times are converted to property-local dates', function (): void {
    $raw = fixtureCalendar([
        'BEGIN:VEVENT', 'UID:timed-1', 'DTSTART:20300401T200000Z', 'DTEND:20300402T200000Z', 'END:VEVENT',
    ]);
    $blocks = normalizeIcalBlocks($raw, ['id'=>1, 'room_id'=>'wooden-villa', 'platform'=>'airbnb'], '2030-01-01');
    assertSame('2030-04-02', $blocks[0]['check_in']);
    assertSame('2030-04-03', $blocks[0]['check_out']);
});

test('calendar snapshots update changed dates without duplicating UIDs', function (): void {
    resetAvailabilityData();
    $db = getDB();
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['wooden-villa', 'airbnb', 'https://example.com/feed.ics']);
    $cal = ['id'=>(int)$db->lastInsertId(), 'room_id'=>'wooden-villa', 'platform'=>'airbnb'];
    $first = fixtureCalendar(['BEGIN:VEVENT','UID:same-uid','DTSTART;VALUE=DATE:20300501','DTEND;VALUE=DATE:20300503','END:VEVENT']);
    applyIcalSnapshot($cal, normalizeIcalBlocks($first, $cal, '2030-01-01'));
    $changed = fixtureCalendar(['BEGIN:VEVENT','UID:same-uid','DTSTART;VALUE=DATE:20300510','DTEND;VALUE=DATE:20300512','END:VEVENT']);
    applyIcalSnapshot($cal, normalizeIcalBlocks($changed, $cal, '2030-01-01'));
    $rows = $db->query("SELECT external_uid, check_in, check_out FROM external_blocks")->fetchAll();
    assertSame([['external_uid'=>'same-uid','check_in'=>'2030-05-10','check_out'=>'2030-05-12']], $rows);
});

test('a valid empty calendar clears stale blocks', function (): void {
    resetAvailabilityData();
    $db = getDB();
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['wooden-villa', 'airbnb', 'https://example.com/feed.ics']);
    $cal = ['id'=>(int)$db->lastInsertId(), 'room_id'=>'wooden-villa', 'platform'=>'airbnb'];
    $raw = fixtureCalendar(['BEGIN:VEVENT','UID:old','DTSTART;VALUE=DATE:20300601','DTEND;VALUE=DATE:20300602','END:VEVENT']);
    applyIcalSnapshot($cal, normalizeIcalBlocks($raw, $cal, '2030-01-01'));
    applyIcalSnapshot($cal, normalizeIcalBlocks(fixtureCalendar([]), $cal, '2030-01-01'));
    assertSame(0, (int)$db->query("SELECT COUNT(*) FROM external_blocks")->fetchColumn());
});

test('invalid calendar input does not erase the last successful snapshot', function (): void {
    resetAvailabilityData();
    $db = getDB();
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['wooden-villa', 'airbnb', 'https://example.com/feed.ics']);
    $cal = ['id'=>(int)$db->lastInsertId(), 'room_id'=>'wooden-villa', 'platform'=>'airbnb'];
    $raw = fixtureCalendar(['BEGIN:VEVENT','UID:kept','DTSTART;VALUE=DATE:20300701','DTEND;VALUE=DATE:20300702','END:VEVENT']);
    applyIcalSnapshot($cal, normalizeIcalBlocks($raw, $cal, '2030-01-01'));
    $thrown = false;
    try { normalizeIcalBlocks('not a calendar', $cal, '2030-01-01'); } catch (UnexpectedValueException) { $thrown = true; }
    assertTrue($thrown);
    assertSame(1, (int)$db->query("SELECT COUNT(*) FROM external_blocks")->fetchColumn());
});

runTests();
