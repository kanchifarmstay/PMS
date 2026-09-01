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
    assertSame('Asia/Kolkata', date_default_timezone_get());
});

if (is_file($securityPath)) {
    require_once $configPath;
    require_once $securityPath;
}

function restoreTestEnvironment(string $name, string|false $original): void
{
    if ($original === false) {
        putenv($name);
        return;
    }
    putenv($name . '=' . $original);
}

test('private environment loader reads only KFS keys and quoted values', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'kfs-env-');
    assertTrue($path !== false, 'Could not create temporary environment file');
    $originals = [];
    foreach (['KFS_TEST_FILE', 'KFS_TEST_QUOTED', 'OTHER_SECRET'] as $name) {
        $originals[$name] = getenv($name);
    }

    try {
        file_put_contents($path, "KFS_TEST_FILE=loaded\nKFS_TEST_QUOTED=\"hello world\"\nOTHER_SECRET=ignored\n");
        putenv('KFS_TEST_FILE');
        putenv('KFS_TEST_QUOTED');
        putenv('OTHER_SECRET');

        assertSame(2, loadKfsEnvFile($path));
        assertSame('loaded', getenv('KFS_TEST_FILE'));
        assertSame('hello world', getenv('KFS_TEST_QUOTED'));
        assertFalse(getenv('OTHER_SECRET') !== false);
    } finally {
        foreach ($originals as $name => $original) restoreTestEnvironment($name, $original);
        if (is_file($path)) unlink($path);
    }
});

test('private environment loader enforces the exact KFS key grammar', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'kfs-env-');
    assertTrue($path !== false, 'Could not create temporary environment file');
    $names = ['KFS_TEST_2_VALID', 'KFS_test_lower', 'KFS-TEST-DASH'];
    $originals = [];
    foreach ($names as $name) $originals[$name] = getenv($name);

    try {
        file_put_contents($path, "KFS_TEST_2_VALID=accepted\nKFS_test_lower=rejected\nKFS-TEST-DASH=rejected\n");
        foreach ($names as $name) putenv($name);

        assertSame(1, loadKfsEnvFile($path));
        assertSame('accepted', getenv('KFS_TEST_2_VALID'));
        assertFalse(getenv('KFS_test_lower') !== false);
        assertFalse(getenv('KFS-TEST-DASH') !== false);
    } finally {
        foreach ($originals as $name => $original) restoreTestEnvironment($name, $original);
        if (is_file($path)) unlink($path);
    }
});

test('private environment loader fails closed without partial assignments', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'kfs-env-');
    assertTrue($path !== false, 'Could not create temporary environment file');
    $original = getenv('KFS_TEST_MALFORMED');

    try {
        file_put_contents($path, "KFS_TEST_MALFORMED=must-not-load\n[unclosed-section\n");
        putenv('KFS_TEST_MALFORMED');

        $thrown = false;
        try {
            loadKfsEnvFile($path);
        } catch (RuntimeException $e) {
            $thrown = true;
            assertSame('Private configuration is invalid.', $e->getMessage());
            assertNotContains($path, $e->getMessage());
            assertNotContains('must-not-load', $e->getMessage());
        }
        assertTrue($thrown, 'Malformed private configuration must throw');
        assertFalse(getenv('KFS_TEST_MALFORMED') !== false);
    } finally {
        restoreTestEnvironment('KFS_TEST_MALFORMED', $original);
        if (is_file($path)) unlink($path);
    }
});

test('private environment loader rejects NUL bytes without partial assignments', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'kfs-env-');
    assertTrue($path !== false, 'Could not create temporary environment file');
    $names = ['KFS_TEST_BEFORE_NUL', 'KFS_TEST_NUL'];
    $originals = [];
    foreach ($names as $name) $originals[$name] = getenv($name);

    try {
        file_put_contents($path, "KFS_TEST_BEFORE_NUL=must-not-load\nKFS_TEST_NUL=bad\0value\n");
        foreach ($names as $name) putenv($name);

        $thrown = false;
        try {
            loadKfsEnvFile($path);
        } catch (RuntimeException $e) {
            $thrown = true;
            assertSame('Private configuration is invalid.', $e->getMessage());
            assertNotContains($path, $e->getMessage());
            assertNotContains('must-not-load', $e->getMessage());
        }
        assertTrue($thrown, 'NUL bytes in private configuration must throw');
        assertFalse(getenv('KFS_TEST_BEFORE_NUL') !== false);
        assertFalse(getenv('KFS_TEST_NUL') !== false);
    } finally {
        foreach ($originals as $name => $original) restoreTestEnvironment($name, $original);
        if (is_file($path)) unlink($path);
    }
});

test('private environment loader preserves process values and handles missing files', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'kfs-env-');
    assertTrue($path !== false, 'Could not create temporary environment file');
    $original = getenv('KFS_TEST_EXISTING');

    try {
        file_put_contents($path, "KFS_TEST_EXISTING=file-value\n");
        putenv('KFS_TEST_EXISTING=process-value');

        assertSame(0, loadKfsEnvFile($path));
        assertSame('process-value', getenv('KFS_TEST_EXISTING'));
        assertSame(0, loadKfsEnvFile($path . '.missing'));
    } finally {
        restoreTestEnvironment('KFS_TEST_EXISTING', $original);
        if (is_file($path)) unlink($path);
    }
});

test('private environment loader throws generically when a required file is missing', function (): void {
    $missingPath = sys_get_temp_dir() . '/missing-kfs-env-' . bin2hex(random_bytes(8));
    $thrown = false;

    try {
        loadKfsEnvFile($missingPath, true);
    } catch (RuntimeException $e) {
        $thrown = true;
        assertSame('Private configuration is invalid.', $e->getMessage());
        assertNotContains($missingPath, $e->getMessage());
    }

    assertTrue($thrown, 'A missing required private configuration must throw');
});

test('configuration requires a private file unless a native database path exists', function () use ($configPath): void {
    $runConfig = static function (bool $nativeDb, string|false $override) use ($configPath): int {
        $code = 'putenv("KFS_DB_PATH"); putenv("KFS_ENV_FILE");';
        if ($nativeDb) $code .= 'putenv("KFS_DB_PATH=/tmp/kfs-native.sqlite");';
        if ($override !== false) $code .= 'putenv(' . var_export('KFS_ENV_FILE=' . $override, true) . ');';
        $code .= 'require ' . var_export($configPath, true) . ';';

        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        assertTrue(is_resource($process), 'Could not start configuration subprocess');
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process);
    };

    assertSame(0, $runConfig(true, false), 'Native KFS_DB_PATH should make the private file optional');
    assertTrue($runConfig(false, false) !== 0, 'Missing KFS_DB_PATH must require the private file');
    assertTrue(
        $runConfig(true, '/definitely/missing/kfs.env') !== 0,
        'An explicit KFS_ENV_FILE must be required even with native KFS_DB_PATH'
    );
});

test('private environment path uses an override or the domain-root fallback', function (): void {
    $original = getenv('KFS_ENV_FILE');
    try {
        putenv('KFS_ENV_FILE=/private/custom-kfs.env');
        assertSame('/private/custom-kfs.env', kfsEnvFilePath('/home/example/domains/kanchifarmstay.com/public_html/channel-manager'));

        putenv('KFS_ENV_FILE');
        assertSame(
            '/home/example/domains/kanchifarmstay.com/kfs.env',
            kfsEnvFilePath('/home/example/domains/kanchifarmstay.com/public_html/channel-manager')
        );
    } finally {
        restoreTestEnvironment('KFS_ENV_FILE', $original);
    }
});

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

test('iCal export is private, CRLF terminated, escaped, and folded to 75 octets', function (): void {
    assertTrue(function_exists('renderAvailabilityCalendar'), 'renderAvailabilityCalendar is not implemented');
    $ical = renderAvailabilityCalendar('wooden-villa', ROOM_IDS['wooden-villa'], [[
        'uid'=>'stable-event@example.test', 'check_in'=>'2030-08-01', 'check_out'=>'2030-08-03',
        'summary'=>'Unavailable, owner; maintenance with a deliberately long explanation that requires folding',
    ]], new DateTimeImmutable('2030-01-01T00:00:00Z'));
    assertTrue(str_contains($ical, "\r\n"));
    assertFalse((bool)preg_match('/(?<!\r)\n/', $ical), 'calendar contains bare LF');
    assertTrue(str_ends_with($ical, "\r\n"));
    assertContains('SUMMARY:Unavailable\\, owner\\; maintenance', $ical);
    foreach (explode("\r\n", rtrim($ical, "\r\n")) as $line) {
        assertTrue(strlen($line) <= 75, 'iCal line exceeds 75 octets: ' . strlen($line));
    }
});

test('iCal export contains no guest names, references, notes, or payment data', function (): void {
    resetAvailabilityData();
    addBooking([
        'room_id'=>'wooden-villa', 'room_name'=>ROOM_IDS['wooden-villa'],
        'check_in'=>'2030-08-10', 'check_out'=>'2030-08-12',
        'guest_name'=>'Private Guest', 'guest_email'=>'private@example.com', 'guest_phone'=>'9999999999',
        'booking_ref'=>'SECRET-REF', 'notes'=>'Private medical note', 'amount'=>9999,
    ]);
    $events = collectAvailabilityEvents('wooden-villa', 'agoda', '2030-01-01');
    $ical = renderAvailabilityCalendar('wooden-villa', ROOM_IDS['wooden-villa'], $events, new DateTimeImmutable('2030-01-01T00:00:00Z'));
    foreach (['Private Guest', 'private@example.com', '9999999999', 'SECRET-REF', 'medical', '9999'] as $private) {
        assertNotContains($private, $ical);
    }
    assertContains('SUMMARY:Unavailable', $ical);
});

test('destination export excludes blocks originating from that destination', function (): void {
    resetAvailabilityData();
    $db = getDB();
    foreach ([['airbnb','air'], ['booking.com','book']] as [$platform, $uid]) {
        $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
           ->execute(['wooden-villa', $platform, "https://example.com/{$platform}.ics"]);
        $calendarId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out) VALUES (?,?,?,?,?,?)")
           ->execute([$calendarId, 'wooden-villa', $platform, $uid, '2030-09-01', '2030-09-03']);
    }
    $events = collectAvailabilityEvents('wooden-villa', 'airbnb', '2030-01-01');
    assertSame(1, count($events));
    assertSame('booking.com', $events[0]['origin']);
});

test('parent and component bookings propagate into related iCal exports', function (): void {
    resetAvailabilityData();
    addBooking([
        'room_id'=>'white-villa-full-floor', 'room_name'=>ROOM_IDS['white-villa-full-floor'],
        'check_in'=>'2030-10-01', 'check_out'=>'2030-10-03', 'guest_name'=>'Parent Guest',
    ]);
    $roomEvents = collectAvailabilityEvents('white-villa-room-2', 'booking.com', '2030-01-01');
    assertSame([['2030-10-01','2030-10-03']], array_map(static fn($e)=>[$e['check_in'],$e['check_out']], $roomEvents));

    resetAvailabilityData();
    addBooking([
        'room_id'=>'natures-nest', 'room_name'=>ROOM_IDS['natures-nest'],
        'check_in'=>'2030-10-05', 'check_out'=>'2030-10-06', 'guest_name'=>'Component Guest',
    ]);
    $groupEvents = collectAvailabilityEvents('kanchi-farm-stay', 'airbnb', '2030-01-01');
    assertSame([['2030-10-05','2030-10-06']], array_map(static fn($e)=>[$e['check_in'],$e['check_out']], $groupEvents));
});

test('same-platform blocks propagate to related listings but not back to their source listing', function (): void {
    resetAvailabilityData();
    $db = getDB();
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['white-villa', 'airbnb', 'https://example.com/room-one.ics']);
    $calendarId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out) VALUES (?,?,?,?,?,?)")
       ->execute([$calendarId, 'white-villa', 'airbnb', 'same-platform', '2030-10-10', '2030-10-12']);

    assertSame([], collectAvailabilityEvents('white-villa', 'airbnb', '2030-01-01'));
    foreach (['white-villa-full-floor', 'kanchi-farm-stay'] as $target) {
        $events = collectAvailabilityEvents($target, 'airbnb', '2030-01-01');
        assertSame([['2030-10-10','2030-10-12']], array_map(static fn($e)=>[$e['check_in'],$e['check_out']], $events));
    }
});

$apiServicePath = dirname(__DIR__) . '/channel-manager/api.php';
if (is_file($apiServicePath)) require_once $apiServicePath;

test('stay validation rejects unknown rooms, impossible dates, and past dates', function (): void {
    foreach ([
        ['not-a-room','2030-01-01','2030-01-02'],
        ['wooden-villa','2030-02-30','2030-03-02'],
        ['wooden-villa','2000-01-01','2000-01-02'],
    ] as [$room, $in, $out]) {
        $thrown = false;
        try { validateStay($room, $in, $out); } catch (InvalidArgumentException) { $thrown = true; }
        assertTrue($thrown, "Expected invalid stay: {$room} {$in} {$out}");
    }
});

test('server quote calculates room rates and extra guests', function (): void {
    assertTrue(function_exists('calculateQuote'), 'calculateQuote is not implemented');
    $quote = calculateQuote('wooden-villa', '2030-11-04', '2030-11-06', 3, 2);
    assertSame(2, $quote['nights']);
    assertSame(8600.0, $quote['total']);
    assertSame(3, $quote['adults']);
    assertSame(2, $quote['children']);

    // Weekend rate test for wooden-villa (Friday 2030-11-08 to Sunday 2030-11-10: 2 weekend nights at ₹3500 = ₹7000)
    $weekendQuote = calculateQuote('wooden-villa', '2030-11-08', '2030-11-10', 2, 1);
    assertSame(2, $weekendQuote['nights']);
    assertSame(2, $weekendQuote['weekend_nights']);
    assertSame(7000.0, $weekendQuote['total']);

    // Tree house quote test (1 weekday night at ₹1500)
    $treeQuote = calculateQuote('tree-house', '2030-11-04', '2030-11-05', 2, 0);
    assertSame(1, $treeQuote['nights']);
    assertSame(1500.0, $treeQuote['total']);
});

test('server quote enforces guest limits', function (): void {
    $thrown = false;
    try { calculateQuote('tent', '2030-11-04', '2030-11-05', 3, 0); } catch (InvalidArgumentException) { $thrown = true; }
    assertTrue($thrown);
});

test('blocked-range API data includes parent and external dependencies', function (): void {
    resetAvailabilityData();
    $db = getDB();
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['white-villa', 'airbnb', 'https://example.com/feed.ics']);
    $calendarId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out) VALUES (?,?,?,?,?,?)")
       ->execute([$calendarId, 'white-villa', 'airbnb', 'api-block', '2030-12-01', '2030-12-03']);
    $ranges = getBlockedRangesForRoom('white-villa-full-floor', '2030-01-01');
    assertSame([['check_in'=>'2030-12-01','check_out'=>'2030-12-03']], $ranges);
});

test('external blocks are normalized for privacy-safe operational calendar views', function (): void {
    resetAvailabilityData();
    $db = getDB();
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['wooden-villa', 'agoda', 'https://example.com/agoda.ics']);
    $calendarId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out) VALUES (?,?,?,?,?,?)")
       ->execute([$calendarId, 'wooden-villa', 'agoda', 'private-provider-uid', '2030-12-10', '2030-12-12']);
    $entries = getExternalBlockCalendarEntries('2030-12-01', '2030-12-31');
    assertSame(1, count($entries));
    assertSame('OTA unavailable', $entries[0]['guest_name']);
    assertSame('agoda', $entries[0]['source']);
    assertFalse(str_contains(json_encode($entries), 'private-provider-uid'));
    $expanded = expandCalendarEntriesToRelatedInventory($entries);
    assertSame(
        ['kanchi-farm-stay', 'wooden-villa'],
        array_column($expanded, 'room_id')
    );
});

test('manual confirmed bookings cannot bypass dependent inventory conflicts', function (): void {
    resetAvailabilityData();
    createConfirmedBooking([
        'room_id'=>'white-villa', 'check_in'=>'2031-03-10', 'check_out'=>'2031-03-12',
        'guest_name'=>'Room Guest', 'source'=>'manual',
    ]);
    $thrown = false;
    try {
        createConfirmedBooking([
            'room_id'=>'white-villa-full-floor', 'check_in'=>'2031-03-11', 'check_out'=>'2031-03-13',
            'guest_name'=>'Floor Guest', 'source'=>'manual',
        ]);
    } catch (DomainException) { $thrown = true; }
    assertTrue($thrown);
    assertSame(1, (int)getDB()->query("SELECT COUNT(*) FROM bookings")->fetchColumn());
});

test('reconnecting a room platform updates one calendar instead of duplicating it', function (): void {
    resetAvailabilityData();
    $firstId = addExternalCalendar('wooden-villa', 'airbnb', 'https://example.com/first.ics');
    $secondId = addExternalCalendar('wooden-villa', 'airbnb', 'https://example.com/second.ics');
    assertSame($firstId, $secondId);
    assertSame(1, (int)getDB()->query("SELECT COUNT(*) FROM external_calendars")->fetchColumn());
    assertSame('https://example.com/second.ics', getDB()->query("SELECT ical_url FROM external_calendars")->fetchColumn());
});

$paymentServicePath = dirname(__DIR__) . '/channel-manager/payment-service.php';
if (is_file($paymentServicePath)) require_once $paymentServicePath;

test('Razorpay checkout signatures are verified exactly', function (): void {
    assertTrue(function_exists('verifyRazorpayPaymentSignature'), 'verifyRazorpayPaymentSignature is not implemented');
    $signature = hash_hmac('sha256', 'order_123|pay_456', RAZORPAY_KEY_SECRET);
    assertTrue(verifyRazorpayPaymentSignature('order_123', 'pay_456', $signature));
    assertFalse(verifyRazorpayPaymentSignature('order_123', 'pay_tampered', $signature));
    assertFalse(verifyRazorpayPaymentSignature('order_123', 'pay_456', ''));
});

test('Razorpay webhook signatures are verified against the raw payload', function (): void {
    assertTrue(function_exists('verifyRazorpayWebhookSignature'), 'verifyRazorpayWebhookSignature is not implemented');
    $payload = '{"event":"payment.captured"}';
    $signature = hash_hmac('sha256', $payload, RAZORPAY_WEBHOOK_SECRET);
    assertTrue(verifyRazorpayWebhookSignature($payload, $signature));
    assertFalse(verifyRazorpayWebhookSignature($payload . 'x', $signature));
});

test('verified payment atomically converts its hold into one paid booking', function (): void {
    resetAvailabilityData();
    $hold = createBookingHold([
        'room_id'=>'wooden-villa', 'check_in'=>'2031-01-10', 'check_out'=>'2031-01-12',
        'guest_name'=>'Paid Guest', 'guest_email'=>'paid@example.com', 'guest_phone'=>'9999999999',
        'adults'=>2, 'children'=>1, 'amount'=>6000,
    ]);
    getDB()->prepare("INSERT INTO payment_orders (order_id, hold_token, amount_paise) VALUES (?,?,?)")
        ->execute(['order_paid', $hold['token'], 600000]);
    $signature = hash_hmac('sha256', 'order_paid|pay_paid', RAZORPAY_KEY_SECRET);
    $bookingId = confirmRazorpayPayment('order_paid', 'pay_paid', $signature);
    assertTrue($bookingId > 0);
    $booking = getBookingById($bookingId);
    assertSame('paid', $booking['payment_status']);
    assertSame(6000.0, (float)$booking['amount_paid']);
    assertSame('pay_paid', $booking['booking_ref']);
    assertSame('confirmed', getDB()->query("SELECT status FROM booking_holds WHERE token=" . getDB()->quote($hold['token']))->fetchColumn());
    assertSame('paid', getDB()->query("SELECT status FROM payment_orders WHERE order_id='order_paid'")->fetchColumn());

    $secondId = confirmRazorpayPayment('order_paid', 'pay_paid', $signature);
    assertSame($bookingId, $secondId);
    assertSame(1, (int)getDB()->query("SELECT COUNT(*) FROM bookings WHERE booking_ref='pay_paid'")->fetchColumn());
});

test('payment confirmation rejects an expired hold and mismatched payment', function (): void {
    resetAvailabilityData();
    $hold = createBookingHold([
        'room_id'=>'wooden-villa', 'check_in'=>'2031-02-10', 'check_out'=>'2031-02-12',
        'guest_name'=>'Expired Guest', 'guest_email'=>'expired@example.com', 'guest_phone'=>'9999999999',
        'adults'=>2, 'children'=>1, 'amount'=>6000,
    ]);
    getDB()->prepare("INSERT INTO payment_orders (order_id, hold_token, amount_paise) VALUES (?,?,?)")
        ->execute(['order_expired', $hold['token'], 600000]);
    getDB()->prepare("UPDATE booking_holds SET expires_at='2000-01-01 00:00:00' WHERE token=?")->execute([$hold['token']]);
    $signature = hash_hmac('sha256', 'order_expired|pay_expired', RAZORPAY_KEY_SECRET);
    $thrown = false;
    try { confirmRazorpayPayment('order_expired', 'pay_expired', $signature); } catch (DomainException) { $thrown = true; }
    assertTrue($thrown);
    assertSame(0, (int)getDB()->query("SELECT COUNT(*) FROM bookings")->fetchColumn());
});

test('operational PHP sources contain no retired production credentials or OTA feed tokens', function (): void {
    $root = dirname(__DIR__);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    $needles = ['KanchiFarm2025!', 'ksf-ical-secret-2025', 'kanchi-cron-2025', 'rzp_live_', '/calendar/ical/', 'ical.booking.com/v1/export?t='];
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) continue;
        $source = (string)file_get_contents($file->getPathname());
        foreach ($needles as $needle) assertNotContains($needle, $source, $file->getPathname() . " contains {$needle}");
    }
});

test('public maintenance and credential-writing scripts are absent', function (): void {
    $dir = dirname(__DIR__) . '/channel-manager/';
    foreach (['add-channels.php', 'add-bookingcom.php', 'check-channels.php', 'fix-airbnb-blocks.php', 'fix-duplicates.php'] as $name) {
        assertFalse(is_file($dir . $name), "{$name} must not be web-accessible");
    }
});

test('admin authentication and mutations use secure sessions and CSRF protection', function (): void {
    $source = (string)file_get_contents(dirname(__DIR__) . '/channel-manager/admin.php');
    assertContains('startSecureSession()', $source);
    assertContains('verifyAdminPassword(', $source);
    assertContains('session_regenerate_id(true)', $source);
    assertContains('requireValidCsrfToken(', $source);
    assertContains('csrfField()', $source);
    assertContains("'&destination='", $source);
    assertNotContains('ADMIN_PASSWORD', $source);
    assertNotContains('?action=logout', $source);
});

test('cron uses configured authentication, a non-blocking lock, and one sync pass', function (): void {
    $source = (string)file_get_contents(dirname(__DIR__) . '/channel-manager/cron.php');
    assertNotContains("define('CRON_SECRET'", $source);
    assertContains('hash_equals(CRON_SECRET', $source);
    assertContains('flock(', $source);
    assertContains('LOCK_NB', $source);
    assertContains('array_slice($logLines, -200)', $source);
    assertSame(1, substr_count($source, 'runCalendarSync('));
});

test('manual sync persists structured results and admin consumes the active-block contract', function (): void {
    $root = dirname(__DIR__);
    $sync = (string)file_get_contents($root . '/channel-manager/sync.php');
    $admin = (string)file_get_contents($root . '/channel-manager/admin.php');
    assertContains("\$_SESSION['last_sync_results']", $sync);
    assertContains('total_blocks', $admin);
    assertNotContains('total_new', $admin);
});

test('web server rules deny private data and contain no embedded calendar token', function (): void {
    $root = dirname(__DIR__);
    $source = (string)file_get_contents($root . '/.htaccess');
    foreach (['calendar\\.db', '.env', 'cron\\.log', 'tests'] as $protected) assertContains($protected, $source);
    foreach ([$root . '/.htaccess', $root . '/channel-manager/.htaccess'] as $path) {
        assertNotContains('ksf-ical-secret-2025', (string)file_get_contents($path));
    }
});

test('public and admin service workers isolate their caches', function (): void {
    $root = dirname(__DIR__);
    $public = (string)file_get_contents($root . '/sw.js');
    $admin = (string)file_get_contents($root . '/channel-manager/admin-sw.js');
    assertContains("kfs-public-", $public);
    assertContains("startsWith('kfs-public-')", $public);
    assertContains("k === 'kfs-v1'", $public);
    assertNotContains('caches.match(request)', $public);
    assertContains("kfs-admin-", $admin);
    assertContains("startsWith('kfs-admin-')", $admin);
    assertNotContains("request.url.includes('/channel-manager/')", $admin);
    assertNotContains("'/channel-manager/admin.php'", $admin);
});

test('booking edit allows date modification when inventory is available and validates conflicts', function (): void {
    resetAvailabilityData();
    $bookingId = createConfirmedBooking([
        'room_id'     => 'wooden-villa',
        'check_in'    => '2030-05-10',
        'check_out'   => '2030-05-12',
        'guest_name'  => 'Original Guest',
        'amount'      => 7000,
        'amount_paid' => 3500,
        'source'      => 'direct',
    ]);
    assertTrue($bookingId > 0);

    // Create another booking nearby to test conflicts
    $otherBookingId = createConfirmedBooking([
        'room_id'     => 'wooden-villa',
        'check_in'    => '2030-05-15',
        'check_out'   => '2030-05-18',
        'guest_name'  => 'Neighbor Guest',
        'amount'      => 10500,
        'source'      => 'direct',
    ]);
    assertTrue($otherBookingId > 0);

    // Attempting to move booking 1 into conflicting dates (2030-05-14 to 2030-05-16) must fail
    $conflictThrown = false;
    try {
        updateConfirmedBooking($bookingId, [
            'room_id'   => 'wooden-villa',
            'check_in'  => '2030-05-14',
            'check_out' => '2030-05-16',
        ]);
    } catch (DomainException $e) {
        $conflictThrown = true;
    }
    assertTrue($conflictThrown, 'Conflicting booking dates must throw a DomainException');

    // Successfully edit dates, guest details, room, and payment when dates are available
    updateConfirmedBooking($bookingId, [
        'room_id'        => 'wooden-villa',
        'check_in'       => '2030-05-08',
        'check_out'      => '2030-05-11',
        'guest_name'     => 'Updated Guest Name',
        'guest_phone'    => '9876543210',
        'amount'         => 10500,
        'amount_paid'    => 10500,
        'payment_method' => 'upi',
        'payment_status' => 'paid',
        'status'         => 'confirmed',
        'notes'          => 'Requested extra pillows',
    ]);

    $updated = getBookingById($bookingId);
    assertSame('2030-05-08', $updated['check_in']);
    assertSame('2030-05-11', $updated['check_out']);
    assertSame('Updated Guest Name', $updated['guest_name']);
    assertSame('9876543210', $updated['guest_phone']);
    assertSame(10500.0, (float)$updated['amount']);
    assertSame(10500.0, (float)$updated['amount_paid']);
    assertSame('upi', $updated['payment_method']);
    assertSame('paid', $updated['payment_status']);
    assertSame('Requested extra pillows', $updated['notes']);
});

test('booking deletion permanently removes record and frees up calendar inventory', function (): void {
    resetAvailabilityData();
    $bookingId = createConfirmedBooking([
        'room_id'    => 'tent',
        'check_in'   => '2030-06-01',
        'check_out'  => '2030-06-03',
        'guest_name' => 'Dummy Test Booking',
        'amount'     => 1000,
        'source'     => 'manual',
    ]);
    assertTrue($bookingId > 0);

    // Verify inventory is blocked
    assertFalse(isInventoryAvailable('tent', '2030-06-01', '2030-06-03'));

    // Delete booking
    deleteBooking($bookingId);

    // Verify record is gone from DB
    $deleted = getBookingById($bookingId);
    assertTrue($deleted === null, 'Booking must be deleted from DB');

    // Verify inventory is immediately free again
    assertTrue(isInventoryAvailable('tent', '2030-06-01', '2030-06-03'));
});

runTests();
