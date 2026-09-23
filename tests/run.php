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
    foreach (['payment_orders', 'booking_holds', 'external_blocks', 'bookings', 'external_calendars', 'rate_overrides', 'pricing_suggestions', 'room_rates'] as $table) {
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

test('three external component blocks are required to block whole-property inventory', function (): void {
    resetAvailabilityData();
    $db = getDB();
    foreach (['white-villa-room-2', 'wooden-villa', 'tent'] as $index => $roomId) {
        $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
           ->execute([$roomId, 'airbnb', "https://example.com/{$roomId}.ics"]);
        $calendarId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out) VALUES (?,?,?,?,?,?)")
           ->execute([$calendarId, $roomId, 'airbnb', 'ext-' . $index, '2030-02-01', '2030-02-03']);
        if ($index < 2) assertTrue(isInventoryAvailable('kanchi-farm-stay', '2030-02-02', '2030-02-04'));
    }
    assertFalse(isInventoryAvailable('white-villa-full-floor', '2030-02-02', '2030-02-04'));
    assertFalse(isInventoryAvailable('kanchi-farm-stay', '2030-02-02', '2030-02-04'));
});

test('a confirmed group booking blocks every component room', function (): void {
    resetAvailabilityData();
    addBooking([
        'room_id'=>'kanchi-farm-stay', 'room_name'=>ROOM_IDS['kanchi-farm-stay'],
        'check_in'=>'2030-02-10', 'check_out'=>'2030-02-12', 'guest_name'=>'Group Guest',
    ]);
    foreach (array_keys(ROOM_IDS) as $roomId) {
        if ($roomId === 'kanchi-farm-stay') continue;
        assertFalse(isInventoryAvailable($roomId, '2030-02-10', '2030-02-12'), $roomId . ' must be blocked');
    }
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
    foreach (['natures-nest', 'wooden-villa'] as $roomId) {
        addBooking([
            'room_id'=>$roomId, 'room_name'=>ROOM_IDS[$roomId],
            'check_in'=>'2030-10-05', 'check_out'=>'2030-10-06', 'guest_name'=>'Component Guest',
        ]);
    }
    assertSame([], collectAvailabilityEvents('kanchi-farm-stay', 'airbnb', '2030-01-01'));
    addBooking([
        'room_id'=>'tent', 'room_name'=>ROOM_IDS['tent'],
        'check_in'=>'2030-10-05', 'check_out'=>'2030-10-06', 'guest_name'=>'Third Component Guest',
    ]);
    $groupEvents = collectAvailabilityEvents('kanchi-farm-stay', 'airbnb', '2030-01-01');
    assertSame([['2030-10-05','2030-10-06']], array_map(static fn($e)=>[$e['check_in'],$e['check_out']], $groupEvents));
});

test('calendar expansion shows group inventory only at the three-room threshold', function (): void {
    $entries = [];
    foreach (['wooden-villa', 'natures-nest', 'tent'] as $index => $roomId) {
        $entries[] = [
            'id'=>$index + 1, 'room_id'=>$roomId, 'room_name'=>ROOM_IDS[$roomId],
            'check_in'=>'2030-10-20', 'check_out'=>'2030-10-22', 'guest_name'=>'Guest',
            'source'=>'direct', 'status'=>'confirmed',
        ];
    }
    $twoRooms = expandCalendarEntriesToRelatedInventory(array_slice($entries, 0, 2));
    assertFalse(in_array('kanchi-farm-stay', array_column($twoRooms, 'room_id'), true));
    $threeRooms = expandCalendarEntriesToRelatedInventory($entries);
    $groupRows = array_values(array_filter($threeRooms, static fn(array $entry): bool => $entry['room_id'] === 'kanchi-farm-stay'));
    assertSame(1, count($groupRows));
    assertSame(1, $groupRows[0]['is_group_threshold']);
});

test('same-platform blocks are not exported back through related listings', function (): void {
    resetAvailabilityData();
    $db = getDB();
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['white-villa', 'airbnb', 'https://example.com/room-one.ics']);
    $calendarId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out) VALUES (?,?,?,?,?,?)")
       ->execute([$calendarId, 'white-villa', 'airbnb', 'same-platform', '2030-10-10', '2030-10-12']);

    foreach (['white-villa', 'white-villa-full-floor', 'kanchi-farm-stay'] as $target) {
        assertSame([], collectAvailabilityEvents($target, 'airbnb', '2030-01-01'));
    }
});

function seedSharedAirbnbEcho(PDO $db, array $rooms, string $checkIn, string $checkOut, string $uid = 'shared-echo'): void
{
    foreach ($rooms as $roomId) {
        $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
           ->execute([$roomId, 'airbnb', "https://example.com/{$roomId}-{$uid}.ics"]);
        $calendarId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
           ->execute([$calendarId, $roomId, 'airbnb', $uid, $checkIn, $checkOut, 'Airbnb (Not available)']);
    }
}

test('shared Airbnb echoes of a booking we hold are removed, reservations are not', function (): void {
    resetAvailabilityData();
    $db = getDB();
    // Airbnb fans a block out across linked listings because we published this
    // booking to them in the first place. That is what makes the copies echoes.
    addBooking([
        'room_id'=>'wooden-villa', 'room_name'=>ROOM_IDS['wooden-villa'],
        'check_in'=>'2030-10-10', 'check_out'=>'2030-10-12',
        'guest_name'=>'Villa Guest', 'source'=>'phone',
    ]);
    seedSharedAirbnbEcho($db, ['wooden-villa', 'tent'], '2030-10-10', '2030-10-12');
    $calendarId = (int)$db->query("SELECT id FROM external_calendars WHERE room_id='tent'")->fetchColumn();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
       ->execute([$calendarId, 'tent', 'airbnb', 'real-reservation', '2030-10-15', '2030-10-16', 'Reserved']);

    assertSame(2, removeSharedAirbnbEchoBlocks($db));
    $remaining = $db->query("SELECT room_id, external_uid, summary FROM external_blocks")->fetchAll();
    assertSame([['room_id'=>'tent', 'external_uid'=>'real-reservation', 'summary'=>'Reserved']], $remaining);
});

test('an unexplained shared Airbnb block keeps every room closed', function (): void {
    // The live failure of 2026-09-12: Airbnb blocked 2027-09-12 on three
    // listings, the sweep deleted all three copies because each had a twin, and
    // the site quoted and took payment for all three rooms. Nothing on our side
    // accounts for these nights, so nothing may be dropped.
    resetAvailabilityData();
    $db = getDB();
    seedSharedAirbnbEcho($db, ['natures-nest', 'tranquil-retreat', 'white-villa'], '2030-11-20', '2030-11-21');

    assertSame(0, removeSharedAirbnbEchoBlocks($db));
    assertSame(3, (int)$db->query('SELECT COUNT(*) FROM external_blocks')->fetchColumn());
    foreach (['natures-nest', 'tranquil-retreat', 'white-villa'] as $room) {
        assertFalse(
            isInventoryAvailable($room, '2030-11-20', '2030-11-21'),
            "expected {$room} to stay closed while the block is unexplained"
        );
    }
});

test('a partly explained shared Airbnb block keeps the night we cannot account for', function (): void {
    // A two-night echo against a one-night booking. Dropping it would sell the
    // second night, which nothing is holding.
    resetAvailabilityData();
    $db = getDB();
    addBooking([
        'room_id'=>'wooden-villa', 'room_name'=>ROOM_IDS['wooden-villa'],
        'check_in'=>'2030-12-01', 'check_out'=>'2030-12-02',
        'guest_name'=>'One Night', 'source'=>'phone',
    ]);
    seedSharedAirbnbEcho($db, ['natures-nest', 'tranquil-retreat'], '2030-12-01', '2030-12-03');

    assertSame(0, removeSharedAirbnbEchoBlocks($db));
    assertFalse(isInventoryAvailable('natures-nest', '2030-12-02', '2030-12-03'));
});

test('a genuine Airbnb reservation on one listing explains its echoes on the others', function (): void {
    resetAvailabilityData();
    $db = getDB();
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['wooden-cottage', 'airbnb', 'https://example.com/cottage.ics']);
    $reservedCalendar = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
       ->execute([$reservedCalendar, 'wooden-cottage', 'airbnb', 'res-1', '2030-12-10', '2030-12-11', 'Reserved']);
    seedSharedAirbnbEcho($db, ['natures-nest', 'tent'], '2030-12-10', '2030-12-11', 'echo-of-res-1');

    assertSame(2, removeSharedAirbnbEchoBlocks($db));
    // The reservation itself is never touched, so its own room stays closed.
    assertFalse(isInventoryAvailable('wooden-cottage', '2030-12-10', '2030-12-11'));
    assertTrue(isInventoryAvailable('natures-nest', '2030-12-10', '2030-12-11'));
});

test('a lone whole-property echo of our own booking is removed', function (): void {
    resetAvailabilityData();
    $db = getDB();
    addBooking([
        'room_id'=>'wooden-cottage', 'room_name'=>ROOM_IDS['wooden-cottage'],
        'check_in'=>'2030-10-10', 'check_out'=>'2030-10-12',
        'guest_name'=>'Cottage Guest', 'source'=>'manual',
    ]);
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['kanchi-farm-stay', 'airbnb', 'https://example.com/group.ics']);
    $calendarId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
       ->execute([$calendarId, 'kanchi-farm-stay', 'airbnb', 'lone-echo', '2030-10-10', '2030-10-12', 'Airbnb (Not available)']);

    // Only the whole-property feed returned the event, so there is no twin.
    // Until 2026-09-19 the shared-UID sweep could not see it and the block sat
    // there closing all ten rooms until removeOwnBookingEchoBlocks() happened
    // to match its dates exactly; now the parent path clears it here.
    assertSame(1, removeSharedAirbnbEchoBlocks($db));
    assertSame(0, (int)$db->query('SELECT COUNT(*) FROM external_blocks')->fetchColumn());
    // Nothing is left for the exact-date sweep to do.
    assertSame(0, removeOwnBookingEchoBlocks($db));
    foreach (['tent', 'natures-nest', 'white-villa'] as $room) {
        assertTrue(
            isInventoryAvailable($room, '2030-10-10', '2030-10-12'),
            "expected {$room} to be bookable once the echo is dropped"
        );
    }
    // The booking that started the round trip still blocks its own room.
    assertFalse(isInventoryAvailable('wooden-cottage', '2030-10-10', '2030-10-12'));
});

test('a merged whole-property block is dropped when its components explain every night', function (): void {
    // The live failure of 2026-09-19. One Airbnb reservation on Wooden Cottage
    // (night of the 19th) and one phone booking on Wooden Villa (night of the
    // 20th) made Airbnb mark the whole-property listing unavailable and export
    // it as ONE merged 19-21 range under its own UID. No feed shares those
    // dates, so no twin exists; no booking of ours matches them either, so the
    // exact-date sweep cannot help. All ten rooms read as sold out for two
    // nights while eight of them were free.
    resetAvailabilityData();
    $db = getDB();
    addBooking([
        'room_id'=>'wooden-villa', 'room_name'=>ROOM_IDS['wooden-villa'],
        'check_in'=>'2030-09-20', 'check_out'=>'2030-09-21',
        'guest_name'=>'Phone Guest', 'source'=>'phone',
    ]);
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['wooden-cottage', 'airbnb', 'https://example.com/cottage-res.ics']);
    $cottageCalendar = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
       ->execute([$cottageCalendar, 'wooden-cottage', 'airbnb', 'cottage-res', '2030-09-19', '2030-09-20', 'Reserved']);
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['kanchi-farm-stay', 'airbnb', 'https://example.com/group-merged.ics']);
    $groupCalendar = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
       ->execute([$groupCalendar, 'kanchi-farm-stay', 'airbnb', 'group-merged', '2030-09-19', '2030-09-21', 'Airbnb (Not available)']);

    assertSame(1, removeSharedAirbnbEchoBlocks($db));
    // The genuine reservation is never touched.
    assertSame(1, (int)$db->query('SELECT COUNT(*) FROM external_blocks')->fetchColumn());

    // The two rooms actually taken stay closed on the nights they are taken.
    assertFalse(isInventoryAvailable('wooden-cottage', '2030-09-19', '2030-09-20'));
    assertFalse(isInventoryAvailable('wooden-villa', '2030-09-20', '2030-09-21'));
    // Everything else is sellable again, on both nights.
    foreach (['tent', 'natures-nest', 'tranquil-retreat', 'white-villa', 'tree-house'] as $room) {
        assertTrue(isInventoryAvailable($room, '2030-09-19', '2030-09-20'), "expected {$room} free on the first night");
        assertTrue(isInventoryAvailable($room, '2030-09-20', '2030-09-21'), "expected {$room} free on the second night");
    }
});

test('a whole-property block is kept when one of its nights is unexplained', function (): void {
    // Same shape as the merged block above but a night longer. Dropping it
    // would sell ten rooms for a night nothing accounts for.
    resetAvailabilityData();
    $db = getDB();
    addBooking([
        'room_id'=>'wooden-villa', 'room_name'=>ROOM_IDS['wooden-villa'],
        'check_in'=>'2030-09-20', 'check_out'=>'2030-09-21',
        'guest_name'=>'Phone Guest', 'source'=>'phone',
    ]);
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['kanchi-farm-stay', 'airbnb', 'https://example.com/group-long.ics']);
    $groupCalendar = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
       ->execute([$groupCalendar, 'kanchi-farm-stay', 'airbnb', 'group-long', '2030-09-20', '2030-09-22', 'Airbnb (Not available)']);

    assertSame(0, removeSharedAirbnbEchoBlocks($db));
    assertFalse(isInventoryAvailable('tent', '2030-09-21', '2030-09-22'));
});

test('a parent block is not explained by a room that is not one of its components', function (): void {
    // White Villa's full floor is made of White Villa and White Villa Room 2.
    // A booking on the Tent says nothing about it, and testing coverage
    // against occupancy anywhere on the farm - rather than against this
    // parent's own components - would drop the block and oversell the floor.
    resetAvailabilityData();
    $db = getDB();
    addBooking([
        'room_id'=>'tent', 'room_name'=>ROOM_IDS['tent'],
        'check_in'=>'2030-09-25', 'check_out'=>'2030-09-26',
        'guest_name'=>'Tent Guest', 'source'=>'phone',
    ]);
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['white-villa-full-floor', 'airbnb', 'https://example.com/floor.ics']);
    $floorCalendar = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
       ->execute([$floorCalendar, 'white-villa-full-floor', 'airbnb', 'floor-block', '2030-09-25', '2030-09-26', 'Airbnb (Not available)']);

    assertSame(0, removeSharedAirbnbEchoBlocks($db));
    assertFalse(isInventoryAvailable('white-villa-full-floor', '2030-09-25', '2030-09-26'));
});

test('a genuine whole-property reservation is never dropped as an echo', function (): void {
    // Airbnb labels a real booking `Reserved`. Even with a component occupied
    // on every one of its nights, it must survive - it is the whole property
    // being sold, not a shadow of something else.
    resetAvailabilityData();
    $db = getDB();
    addBooking([
        'room_id'=>'wooden-villa', 'room_name'=>ROOM_IDS['wooden-villa'],
        'check_in'=>'2030-09-28', 'check_out'=>'2030-09-29',
        'guest_name'=>'Phone Guest', 'source'=>'phone',
    ]);
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['kanchi-farm-stay', 'airbnb', 'https://example.com/group-real.ics']);
    $groupCalendar = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
       ->execute([$groupCalendar, 'kanchi-farm-stay', 'airbnb', 'group-real', '2030-09-28', '2030-09-29', 'Reserved']);

    assertSame(0, removeSharedAirbnbEchoBlocks($db));
    assertFalse(isInventoryAvailable('tent', '2030-09-28', '2030-09-29'));
});

test('an OTA block that matches no booking of ours is kept', function (): void {
    resetAvailabilityData();
    $db = getDB();
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['kanchi-farm-stay', 'airbnb', 'https://example.com/group-genuine.ics']);
    $calendarId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
       ->execute([$calendarId, 'kanchi-farm-stay', 'airbnb', 'genuine-block', '2030-11-01', '2030-11-03', 'Airbnb (Not available)']);

    assertSame(0, removeOwnBookingEchoBlocks($db));
    assertSame(1, (int)$db->query('SELECT COUNT(*) FROM external_blocks')->fetchColumn());
    assertFalse(isInventoryAvailable('tent', '2030-11-01', '2030-11-03'));
});

test('a block is not an echo of a booking that came from the same platform', function (): void {
    resetAvailabilityData();
    $db = getDB();
    addBooking([
        'room_id'=>'wooden-cottage', 'room_name'=>ROOM_IDS['wooden-cottage'],
        'check_in'=>'2030-12-01', 'check_out'=>'2030-12-03',
        'guest_name'=>'Airbnb Guest', 'source'=>'airbnb', 'is_sync_imported'=>1,
    ]);
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['wooden-cottage', 'airbnb', 'https://example.com/cottage.ics']);
    $calendarId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
       ->execute([$calendarId, 'wooden-cottage', 'airbnb', 'airbnb-own', '2030-12-01', '2030-12-03', 'Airbnb (Not available)']);

    // The booking came from Airbnb, so the block is that same reservation, not a
    // round trip of ours. applyIcalSnapshot() clears imported bookings on every
    // run, so the block stays as the durable record.
    assertSame(0, removeOwnBookingEchoBlocks($db));
    assertSame(1, (int)$db->query('SELECT COUNT(*) FROM external_blocks')->fetchColumn());
});

test('a parent-inventory block is dropped when a component carries the same one', function (): void {
    resetAvailabilityData();
    $db = getDB();
    // booking.com, not Airbnb: no summary wording separates its blocks from its
    // reservations, so the Airbnb sweep can never be pointed at this feed.
    foreach (['kanchi-farm-stay', 'natures-nest'] as $roomId) {
        $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
           ->execute([$roomId, 'booking.com', "https://example.com/{$roomId}-bdc.ics"]);
        $calendarId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
           ->execute([$calendarId, $roomId, 'booking.com', 'bdc-shared', '2030-09-04', '2030-09-06', 'CLOSED']);
    }

    assertSame(0, removeSharedAirbnbEchoBlocks($db), 'the Airbnb sweep must not touch another platform');
    assertSame(1, removeParentInventoryEchoBlocks($db));

    $remaining = $db->query('SELECT room_id FROM external_blocks')->fetchAll(PDO::FETCH_COLUMN);
    assertSame(['natures-nest'], $remaining);
    assertFalse(isInventoryAvailable('natures-nest', '2030-09-04', '2030-09-06'));
    foreach (['tent', 'wooden-cottage', 'white-villa'] as $room) {
        assertTrue(
            isInventoryAvailable($room, '2030-09-04', '2030-09-06'),
            "expected {$room} to stay sellable once the parent copy is dropped"
        );
    }
});

test('the full-floor parent is narrowed to its component too', function (): void {
    resetAvailabilityData();
    $db = getDB();
    foreach (['white-villa-full-floor', 'white-villa'] as $roomId) {
        $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
           ->execute([$roomId, 'agoda', "https://example.com/{$roomId}-agoda.ics"]);
        $calendarId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
           ->execute([$calendarId, $roomId, 'agoda', 'agoda-shared', '2030-09-10', '2030-09-12', 'Blocked']);
    }

    assertSame(1, removeParentInventoryEchoBlocks($db));
    assertFalse(isInventoryAvailable('white-villa', '2030-09-10', '2030-09-12'));
    assertTrue(isInventoryAvailable('white-villa-room-2', '2030-09-10', '2030-09-12'));
});

test('a parent-inventory block with no component twin is kept', function (): void {
    resetAvailabilityData();
    $db = getDB();
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute(['kanchi-farm-stay', 'agoda', 'https://example.com/group-agoda.ics']);
    $calendarId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
       ->execute([$calendarId, 'kanchi-farm-stay', 'agoda', 'group-only', '2030-09-20', '2030-09-22', 'Blocked']);

    // Nothing here says this is not a real whole-property reservation, so it
    // stands. Over-blocking is the safe direction; underblocking sells a room twice.
    assertSame(0, removeParentInventoryEchoBlocks($db));
    assertSame(0, removeOwnBookingEchoBlocks($db));
    assertSame(1, (int)$db->query('SELECT COUNT(*) FROM external_blocks')->fetchColumn());
    assertFalse(isInventoryAvailable('tent', '2030-09-20', '2030-09-22'));
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
        ['wooden-villa'],
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

test('a saved weekday rate never overwrites the weekend rate', function (): void {
    resetAvailabilityData();
    // ROOM_PRICING's uplift must survive the rates screen. Saving one number
    // used to write it over both, flattening every weekend in the property.
    $before = roomPricing('natures-nest');
    assertTrue($before['weekend'] > $before['weekday'], 'fixture room should have a weekend uplift');

    upsertRoomRate('natures-nest', 2800.0, null);
    $after = roomPricing('natures-nest');
    assertSame(2800.0, (float)$after['weekday']);
    assertSame((float)$before['weekend'], (float)$after['weekend'], 'the weekend rate must be untouched');

    upsertRoomRate('natures-nest', 2800.0, 3600.0);
    $both = roomPricing('natures-nest');
    assertSame(2800.0, (float)$both['weekday']);
    assertSame(3600.0, (float)$both['weekend']);

    // A weekend is never quietly cheaper than the weekday it sits next to.
    upsertRoomRate('natures-nest', 5000.0, 3000.0);
    assertSame(5000.0, (float)roomPricing('natures-nest')['weekend']);
    resetAvailabilityData();
});

test('approving a pricing suggestion changes what a guest is quoted', function (): void {
    resetAvailabilityData();
    $db = getDB();
    // Fri 2030-11-08 and Sat 2030-11-09 are weekend nights, so this also proves
    // an override beats the weekend rate rather than being added to it.
    $standard = calculateQuote('natures-nest', '2030-11-08', '2030-11-10', 2, 1);
    addPricingSuggestion([
        'room_id'=>'natures-nest', 'date_from'=>'2030-11-08', 'date_to'=>'2030-11-09',
        'current_price'=>2500, 'suggested_price'=>4000, 'suggestion_pct'=>60,
        'reason'=>'Test event', 'demand_level'=>'high',
    ]);
    $id = (int)$db->query('SELECT id FROM pricing_suggestions ORDER BY id DESC LIMIT 1')->fetchColumn();

    approveSuggestion($id, 4000.0, 'applied by test');
    $repriced = calculateQuote('natures-nest', '2030-11-08', '2030-11-10', 2, 1);
    assertSame(8000.0, (float)$repriced['base_total'], 'both nights should bill at the approved price');
    assertSame(2, (int)$repriced['override_nights']);
    assertTrue((float)$repriced['total'] > (float)$standard['total']);

    // Nights outside the suggestion keep the standard rate.
    $untouched = calculateQuote('natures-nest', '2030-11-11', '2030-11-12', 2, 1);
    assertSame(0, (int)$untouched['override_nights']);

    assertSame(2, unapplySuggestion($id));
    $reverted = calculateQuote('natures-nest', '2030-11-08', '2030-11-10', 2, 1);
    assertSame((float)$standard['total'], (float)$reverted['total'], 'undo must restore the standard rate');
    assertSame('pending', (string)$db->query("SELECT status FROM pricing_suggestions WHERE id={$id}")->fetchColumn());
    resetAvailabilityData();
});

test('transaction guards do not depend on PDO::inTransaction()', function (): void {
    // PHP 8.1 (production) reports false from inTransaction() after
    // exec('BEGIN IMMEDIATE'); PHP 8.5 (these tests) reports true. Guards built
    // on it rolled back nothing in production and could not see a nested BEGIN.
    $db = getDB();
    assertSame(0, kfsTransactionDepth());
    $outer = kfsBeginTransaction($db);
    assertTrue($outer, 'the first caller owns the transaction');
    assertSame(1, kfsTransactionDepth());
    $inner = kfsBeginTransaction($db);
    assertFalse($inner, 'a nested caller must not issue a second BEGIN');
    kfsCommitTransaction($db, $inner);
    assertSame(1, kfsTransactionDepth(), 'a nested caller must not commit');
    kfsCommitTransaction($db, $outer);
    assertSame(0, kfsTransactionDepth());

    // And a rollback actually rolls back.
    kfsBeginTransaction($db);
    $db->exec("INSERT INTO settings (key, value) VALUES ('kfs_tx_probe','1')");
    kfsRollbackTransaction($db);
    assertSame(false, $db->query("SELECT value FROM settings WHERE key='kfs_tx_probe'")->fetchColumn());
    assertSame(0, kfsTransactionDepth());

    // The guards are gone from every source that manages a transaction.
    foreach (['db.php', 'ical.php', 'sync.php', 'booking-service.php', 'payment-service.php'] as $file) {
        $source = (string)file_get_contents(dirname(__DIR__) . '/channel-manager/' . $file);
        assertNotContains('$db->inTransaction()', $source, "{$file} still asks the driver");
    }
});

test('requiring the demand engine does not run it', function (): void {
    // cron.php requires demand-engine.php, and its entry-point block fired on
    // any CLI process that required it - so a command-line sync re-seeded
    // events and generated a fresh batch of suggestions every run.
    $source = (string)file_get_contents(dirname(__DIR__) . '/channel-manager/demand-engine.php');
    assertContains('$demandEngineIsEntryPoint', $source);
    assertTrue(
        strpos($source, 'realpath((string)($_SERVER[') < strpos($source, "php_sapi_name() === 'cli'"),
        'the entry-point check must gate the CLI branch'
    );
    // Requiring it here would have seeded events if the guard were missing.
    $before = (int)getDB()->query('SELECT COUNT(*) FROM pricing_suggestions')->fetchColumn();
    require_once dirname(__DIR__) . '/channel-manager/demand-engine.php';
    assertSame($before, (int)getDB()->query('SELECT COUNT(*) FROM pricing_suggestions')->fetchColumn());
});

test('stored timestamps are read as UTC', function (): void {
    // SQLite writes datetime('now') in UTC and the app runs in Asia/Kolkata, so
    // strtotime() on a raw stored value reported every sync 5h30m stale.
    $db = getDB();
    $stored = (string)$db->query("SELECT datetime('now')")->fetchColumn();
    $age = time() - (int)kfsDbTimestamp($stored);
    assertTrue($age >= -5 && $age <= 5, "expected a fresh timestamp, got {$age}s of drift");
    assertSame(null, kfsDbTimestamp(null));
    assertSame(null, kfsDbTimestamp(''));
});

test('a sync stores its snapshots and sweeps in one transaction', function (): void {
    $sync = (string)file_get_contents(dirname(__DIR__) . '/channel-manager/sync.php');
    // Fetching must happen before the write lock is taken: thirty feeds take
    // about ten seconds, and holding the database for that long blocks booking.
    assertContains('prepareOneCalendar(', $sync);
    assertContains('kfsBeginTransaction($db)', $sync);
    assertTrue(
        strpos($sync, 'kfsBeginTransaction($db)') < strpos($sync, 'removeSharedAirbnbEchoBlocks($db)'),
        'the sweeps must run inside the snapshot transaction'
    );
    assertTrue(
        strpos($sync, 'prepareOneCalendar($calendar, $fetcher);') < strpos($sync, 'kfsBeginTransaction($db)'),
        'every feed must be fetched before the write lock is taken'
    );
    assertContains('withSyncLock(', $sync);
    assertContains('LOCK_NB', $sync);
    // applyIcalSnapshot must never fetch: a network call inside the write
    // transaction is what the split exists to prevent.
    assertNotContains('fetchCalendarUrl', (string)file_get_contents(dirname(__DIR__) . '/channel-manager/ical.php'));
});

test('sync failures are visible to a human', function (): void {
    $admin = (string)file_get_contents(dirname(__DIR__) . '/channel-manager/admin.php');
    // last_status and last_error were written by every sync and displayed
    // nowhere, so a feed broken for weeks still showed a green tick.
    assertContains("last_status", $admin);
    assertContains("last_error", $admin);
    assertContains('kfsDbTimestamp(', $admin);
    assertNotContains("strtotime(\$cal['last_synced'])", $admin);
});

test('the oversell-prone getBlockedRanges is gone', function (): void {
    // It read bookings only - no OTA blocks, no holds - and sat one letter from
    // getBlockedRangesForRoom().
    assertFalse(function_exists('getBlockedRanges'), 'getBlockedRanges() must not exist');
    assertTrue(function_exists('getBlockedRangesForRoom'));
});

test('synthetic calendar ids cannot collide', function (): void {
    // external_blocks is deleted and re-inserted every sync, so its
    // AUTOINCREMENT climbs by about a thousand a day.
    $service = (string)file_get_contents(dirname(__DIR__) . '/channel-manager/booking-service.php');
    assertContains('-1_000_000_000 - (int)$row[', $service);
    assertNotContains('-2_000_000 - $index', $service);
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
    assertContains('showToast(', $admin);
    assertContains('aria-live="polite"', $admin);
    assertContains("sessionStorage.setItem('kfsSyncToast'", $admin);
    assertNotContains("alert('Sync", $admin);
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

function insertOtaBlock(string $roomId, string $platform, string $uid, string $checkIn, string $checkOut): int
{
    $db = getDB();
    $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)")
       ->execute([$roomId, $platform, "https://example.com/{$roomId}-{$platform}.ics"]);
    $calendarId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO external_blocks (calendar_id, room_id, platform, external_uid, check_in, check_out, summary) VALUES (?,?,?,?,?,?,?)")
       ->execute([$calendarId, $roomId, $platform, $uid, $checkIn, $checkOut, 'CLOSED - Not available']);
    return (int)$db->lastInsertId();
}

function countExternalBlocks(): int
{
    return (int)getDB()->query('SELECT COUNT(*) FROM external_blocks')->fetchColumn();
}

test('an OTA booking replaces the block its own reservation exported', function (): void {
    // The live failure of 2026-09-20. Booking.com sold White Villa - Full 1st
    // Floor for one night and published the closure on BOTH linked listings
    // under one uid; removeParentInventoryEchoBlocks() kept the component copy,
    // so the reservation came back as a block on White Villa 1 and closed the
    // floor it was a reservation for. Recording it was impossible.
    resetAvailabilityData();
    insertOtaBlock('white-villa', 'booking.com', 'f0c4a2ca@booking.com', '2030-09-20', '2030-09-21');
    assertFalse(isInventoryAvailable('white-villa-full-floor', '2030-09-20', '2030-09-21'));

    $id = createConfirmedBooking([
        'room_id'=>'white-villa-full-floor',
        'check_in'=>'2030-09-20', 'check_out'=>'2030-09-21',
        'guest_name'=>'Booking.com Guest', 'source'=>'booking.com',
    ]);
    assertTrue($id > 0);
    assertSame(0, countExternalBlocks());

    // The booking now holds the dates the block was holding - nothing reopened.
    assertFalse(isInventoryAvailable('white-villa-full-floor', '2030-09-20', '2030-09-21'));
    assertFalse(isInventoryAvailable('white-villa', '2030-09-20', '2030-09-21'));
    // And this is what the block could never do: the block sat on White Villa 1,
    // whose family does not contain Room 2, so Room 2 stayed on sale for a night
    // the whole floor was sold. The booking closes it.
    assertFalse(isInventoryAvailable('white-villa-room-2', '2030-09-20', '2030-09-21'));
});

test('a booking that did not come from the platform never clears its blocks', function (): void {
    resetAvailabilityData();
    insertOtaBlock('white-villa', 'booking.com', 'someone-else@booking.com', '2030-09-20', '2030-09-21');

    foreach (['manual', 'direct', 'phone', ''] as $source) {
        $refused = false;
        try {
            createConfirmedBooking([
                'room_id'=>'white-villa-full-floor',
                'check_in'=>'2030-09-20', 'check_out'=>'2030-09-21',
                'guest_name'=>'Walk-in', 'source'=>$source,
            ]);
        } catch (DomainException $e) {
            $refused = true;
            assertSame('Those dates conflict with a booking, OTA block, or payment hold.', $e->getMessage());
        }
        assertTrue($refused, "expected a {$source} booking to be refused");
    }
    assertSame(1, countExternalBlocks());
});

test('another platform holding the same night still refuses the stay', function (): void {
    resetAvailabilityData();
    insertOtaBlock('white-villa', 'booking.com', 'ours@booking.com', '2030-09-20', '2030-09-21');
    insertOtaBlock('white-villa-room-2', 'airbnb', 'theirs@airbnb.com', '2030-09-20', '2030-09-21');

    $refused = false;
    try {
        createConfirmedBooking([
            'room_id'=>'white-villa-full-floor',
            'check_in'=>'2030-09-20', 'check_out'=>'2030-09-21',
            'guest_name'=>'Booking.com Guest', 'source'=>'booking.com',
        ]);
    } catch (DomainException) { $refused = true; }
    assertTrue($refused, 'a second OTA on the same night must still refuse');
    // Neither block was touched - not even the one the source did match.
    assertSame(2, countExternalBlocks());
});

test('a block that outlives the stay belongs to someone else', function (): void {
    resetAvailabilityData();
    // Three nights blocked, one night being recorded: the other two nights are
    // not explained by this reservation, so the block is not its echo.
    insertOtaBlock('white-villa', 'booking.com', 'longer@booking.com', '2030-09-19', '2030-09-22');

    $refused = false;
    try {
        createConfirmedBooking([
            'room_id'=>'white-villa-full-floor',
            'check_in'=>'2030-09-20', 'check_out'=>'2030-09-21',
            'guest_name'=>'Booking.com Guest', 'source'=>'booking.com',
        ]);
    } catch (DomainException) { $refused = true; }
    assertTrue($refused, 'a block wider than the stay must refuse it');
    assertSame(1, countExternalBlocks());
});

test('a conflict hidden behind a claimable block refuses, and the block comes back', function (): void {
    resetAvailabilityData();
    insertOtaBlock('white-villa', 'booking.com', 'echo@booking.com', '2030-09-20', '2030-09-21');
    // A real guest is already in Room 2 that night, which the block was hiding.
    addBooking([
        'room_id'=>'white-villa-room-2', 'room_name'=>ROOM_IDS['white-villa-room-2'],
        'check_in'=>'2030-09-20', 'check_out'=>'2030-09-21',
        'guest_name'=>'Room 2 Guest', 'source'=>'phone',
    ]);

    $refused = false;
    try {
        createConfirmedBooking([
            'room_id'=>'white-villa-full-floor',
            'check_in'=>'2030-09-20', 'check_out'=>'2030-09-21',
            'guest_name'=>'Booking.com Guest', 'source'=>'booking.com',
        ]);
    } catch (DomainException) { $refused = true; }
    assertTrue($refused, 'a genuine booking behind the block must still refuse');
    // The rollback is the point: claiming deletes before the second check, so a
    // refusal that did not restore the block would quietly reopen the night.
    assertSame(1, countExternalBlocks());
    assertFalse(isInventoryAvailable('white-villa-full-floor', '2030-09-20', '2030-09-21'));
});

test('a whole-property OTA booking replaces the component blocks it closed', function (): void {
    resetAvailabilityData();
    // The group listing has no block of its own - removeParentInventoryEchoBlocks()
    // drops that copy - and GROUP_BOOKING_THRESHOLD closes the property once three
    // components are taken. All three are this same reservation.
    insertOtaBlock('wooden-villa', 'booking.com', 'group@booking.com', '2030-09-20', '2030-09-21');
    insertOtaBlock('wooden-cottage', 'booking.com', 'group@booking.com', '2030-09-20', '2030-09-21');
    insertOtaBlock('natures-nest', 'booking.com', 'group@booking.com', '2030-09-20', '2030-09-21');
    assertFalse(isInventoryAvailable('kanchi-farm-stay', '2030-09-20', '2030-09-21'));

    $id = createConfirmedBooking([
        'room_id'=>'kanchi-farm-stay',
        'check_in'=>'2030-09-20', 'check_out'=>'2030-09-21',
        'guest_name'=>'Booking.com Group', 'source'=>'booking.com',
    ]);
    assertTrue($id > 0);
    assertSame(0, countExternalBlocks());
    assertFalse(isInventoryAvailable('kanchi-farm-stay', '2030-09-20', '2030-09-21'));
    assertFalse(isInventoryAvailable('wooden-villa', '2030-09-20', '2030-09-21'));
});

test('claimable ids are read only, and say no before anything is deleted', function (): void {
    resetAvailabilityData();
    $blockId = insertOtaBlock('white-villa', 'booking.com', 'read-only@booking.com', '2030-09-20', '2030-09-21');

    assertSame([$blockId], claimableOtaBlockIds('white-villa-full-floor', '2030-09-20', '2030-09-21', 'Booking.com'));
    assertSame([], claimableOtaBlockIds('white-villa-full-floor', '2030-09-20', '2030-09-21', 'manual'));
    assertSame([], claimableOtaBlockIds('white-villa-full-floor', '2030-09-20', '2030-09-21', 'airbnb'));
    // A room in no way related to White Villa sees nothing to claim.
    assertSame([], claimableOtaBlockIds('tent', '2030-09-20', '2030-09-21', 'booking.com'));
    assertSame(1, countExternalBlocks());
});


// ── Bill / GST invoice generator ──────────────────────────────
$billServicePath = dirname(__DIR__) . '/channel-manager/bill-service.php';
if (is_file($billServicePath)) require_once $billServicePath;

function renderBillPage(array $get, bool $admin = true, array $env = []): string
{
    $script = tempnam(sys_get_temp_dir(), 'kfs-bill-') . '.php';
    $page = var_export(dirname(__DIR__) . '/channel-manager/bill.php', true);
    $code = '<?php ';
    foreach ($env as $k => $v) $code .= 'putenv(' . var_export("{$k}={$v}", true) . ');';
    $code .= 'session_start();' . ($admin ? '$_SESSION["admin_logged_in"]=true;' : '')
        . '$_SERVER["REQUEST_METHOD"]="GET";$_GET=' . var_export($get, true) . ';'
        . 'include ' . $page . ';';
    file_put_contents($script, $code);
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);
    return (string)$out;
}

function sampleBill(array $over = []): array
{
    return array_replace_recursive(billFromInput([
        'guest_name' => 'Test Guest', 'guest_phone' => '9876543210', 'invoice_date' => '2030-09-20',
        'room_name' => 'Wooden Villa', 'check_in' => '2030-09-20', 'check_out' => '2030-09-22',
        'inclusive' => '1', 'paid' => '5000',
        'items' => [['desc' => 'Room tariff', 'sac' => '996311', 'qty' => '2', 'rate' => '4500', 'gst' => '5']],
    ]), $over);
}

test('bill: an inclusive room rate splits into taxable + CGST + SGST that add back to the rate', function (): void {
    $c = computeBill([['desc' => 'Room', 'sac' => '996311', 'qty' => 2, 'rate' => 450000, 'gst' => 5]], true);
    assertSame(857143, $c['taxable']);
    assertSame(21428, $c['cgst']);
    assertSame(21429, $c['sgst']);
    assertSame(900000, $c['taxable'] + $c['cgst'] + $c['sgst']);
    assertSame(900000, $c['grand']);
    assertSame(0, $c['round_off']);
});

test('bill: exclusive rates add tax on top and round the total to the rupee with the round-off shown', function (): void {
    $c = computeBill([
        ['desc' => 'Room', 'sac' => '996311', 'qty' => 1, 'rate' => 333333, 'gst' => 5],
        ['desc' => 'Dinner', 'sac' => '996331', 'qty' => 3, 'rate' => 45050, 'gst' => 5],
    ], false);
    assertSame(333333 + 135150, $c['taxable']);
    assertSame($c['tax'], $c['cgst'] + $c['sgst']);
    assertSame(0, $c['grand'] % 100);
    assertSame($c['grand'], $c['taxable'] + $c['tax'] + $c['round_off']);
    assertSame(2, count($c['tax_summary']), 'one tax-summary row per SAC and rate');
});

test('bill: room GST default follows the ₹7,500 pre-tax ceiling', function (): void {
    assertSame(5, defaultRoomGstRate(750000, false));
    assertSame(18, defaultRoomGstRate(750001, false));
    // ₹7,800 inclusive is ₹7,428.57 before tax, so still 5%.
    assertSame(5, defaultRoomGstRate(780000, true));
    assertSame(18, defaultRoomGstRate(900000, true));
});

test('bill: financial year and invoice number follow the April-March year and fit GST\'s 16 characters', function (): void {
    assertSame('2026-27', financialYear('2026-09-23'));
    assertSame('2026-27', financialYear('2027-03-31'));
    assertSame('2027-28', financialYear('2027-04-01'));
    $no = formatInvoiceNumber('KFSTAY', '2026-27', 12);
    assertSame('KFSTAY/26-27/0012', $no);
    assertTrue(strlen(formatInvoiceNumber('KFS', '2026-27', 9999)) <= 16);
});

test('bill: amounts print in Indian grouping and in words', function (): void {
    assertSame('₹12,34,567.50', fmtPaise(123456750));
    assertSame('₹999.00', fmtPaise(99900));
    assertSame('Rupees Nine Thousand Only', amountInWords(900000));
    assertSame('Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six and Seventy Eight Paise Only', amountInWords(12345678));
    assertSame('Rupees Zero Only', amountInWords(0));
});

test('bill: input is refused rather than printed when it would make a bad invoice', function (): void {
    foreach ([
        ['guest_name' => '', 'invoice_date' => '2030-01-01', 'items' => [['desc' => 'x', 'qty' => 1, 'rate' => 1, 'gst' => 5]]],
        ['guest_name' => 'A', 'invoice_date' => '', 'items' => [['desc' => 'x', 'qty' => 1, 'rate' => 1, 'gst' => 5]]],
        ['guest_name' => 'A', 'invoice_date' => '2030-01-01', 'items' => []],
        ['guest_name' => 'A', 'invoice_date' => '2030-01-01', 'items' => [['desc' => 'x', 'qty' => 1, 'rate' => 1, 'gst' => 7]]],
        ['guest_name' => 'A', 'invoice_date' => '2030-01-01', 'items' => [['desc' => '', 'qty' => 1, 'rate' => 100, 'gst' => 5]]],
        ['guest_name' => 'A', 'invoice_date' => '2030-01-01', 'guest_gstin' => 'NOTAGSTIN', 'items' => [['desc' => 'x', 'qty' => 1, 'rate' => 1, 'gst' => 5]]],
    ] as $i => $bad) {
        $threw = false;
        try { billFromInput($bad); } catch (InvalidArgumentException) { $threw = true; }
        assertTrue($threw, "case {$i} should be refused");
    }
    assertTrue(isValidGstin('33BFYPP2186L1ZM'));
});

test('bill: a draft from a booking bills exactly the booking amount', function (): void {
    $even = billDraftFromBooking(['id' => 7, 'room_name' => 'Tent', 'check_in' => '2030-01-01', 'check_out' => '2030-01-04',
        'guest_name' => 'G', 'guest_phone' => '', 'whatsapp_number' => '9999999999', 'amount' => 9000, 'amount_paid' => 3000]);
    assertSame(3, $even['items'][0]['qty']);
    assertSame(300000, $even['items'][0]['rate']);
    assertSame('9999999999', $even['guest']['phone']);
    assertSame(300000, $even['paid']);
    $odd = billDraftFromBooking(['id' => 8, 'room_name' => 'Tent', 'check_in' => '2030-01-01', 'check_out' => '2030-01-04',
        'guest_name' => 'G', 'amount' => 10000]);
    assertSame(1, $odd['items'][0]['qty']);
    assertSame(1000000, computeBill($odd['items'], true)['grand']);
});

test('bill: numbers run in sequence per financial year, and an edit keeps its number', function (): void {
    getDB()->exec('DELETE FROM bills');
    $a = saveBill(sampleBill());
    $b = saveBill(sampleBill());
    $c = saveBill(sampleBill(['invoice_date' => '2031-04-02']));
    assertSame('KFS/30-31/0001', getBill($a)['invoice_no']);
    assertSame('KFS/30-31/0002', getBill($b)['invoice_no']);
    assertSame('KFS/31-32/0001', getBill($c)['invoice_no']);

    saveBill(sampleBill(['guest' => ['name' => 'Renamed']]), $a);
    assertSame('KFS/30-31/0001', getBill($a)['invoice_no']);
    assertSame('Renamed', getBill($a)['guest_name']);

    $moved = false;
    try { saveBill(sampleBill(['invoice_date' => '2031-05-01']), $a); } catch (InvalidArgumentException) { $moved = true; }
    assertTrue($moved, 'an edit may not move an invoice into another financial year');

    cancelBill($b);
    assertSame('cancelled', getBill($b)['status']);
    $edited = false;
    try { saveBill(sampleBill(), $b); } catch (InvalidArgumentException) { $edited = true; }
    assertTrue($edited, 'a cancelled invoice cannot be edited');
    // Cancelling never frees the number.
    assertSame('KFS/30-31/0003', getBill(saveBill(sampleBill()))['invoice_no']);
});

test('bill: an issued invoice keeps the business details it was issued with', function (): void {
    getDB()->exec('DELETE FROM bills');
    $id = saveBill(sampleBill());
    saveBillProfile(array_merge(BILL_PROFILE_DEFAULTS, ['address' => 'New Road, Kanchipuram 631501']));
    assertSame(BILL_PROFILE_DEFAULTS['address'], getBill($id)['data']['business']['address']);
    assertSame('New Road, Kanchipuram 631501', getBill(saveBill(sampleBill()))['data']['business']['address']);
    $bad = false;
    try { saveBillProfile(array_merge(BILL_PROFILE_DEFAULTS, ['gstin' => '33BAD'])); } catch (InvalidArgumentException) { $bad = true; }
    assertTrue($bad);
    saveBillProfile(BILL_PROFILE_DEFAULTS);
});

test('bill: the registered address prints by default, and a missing PIN is flagged', function (): void {
    assertContains('Chithathur Village', BILL_PROFILE_DEFAULTS['address']);
    assertTrue(billAddressNeedsPin(BILL_PROFILE_DEFAULTS['address']));
    assertFalse(billAddressNeedsPin(BILL_PROFILE_DEFAULTS['address'] . ' 604 410'));
    assertFalse(billAddressNeedsPin('Chithathur, Tamil Nadu - 604410'));
    assertContains('no PIN code', renderBillPage([]));
});

test('bill: the printed invoice carries the logo, GSTIN, phone numbers, and totals', function (): void {
    getDB()->exec('DELETE FROM bills');
    $id = saveBill(sampleBill());
    $html = renderBillPage(['id' => (string)$id]);
    foreach (['assets/images/logo.png', 'TAX INVOICE', '33BFYPP2186L1ZM', '+91 6383726094', '+91 8825775747',
              'KFS/30-31/0001', 'Test Guest', 'Chithathur Village', 'Tiruvannamalai District', 'Rupees Nine Thousand Only', '9,000.00', 'Tamil Nadu (33)', '2.5% + 2.5%'] as $needle) {
        assertContains($needle, $html, "invoice should show {$needle}");
    }
    assertNotContains('Warning', $html);
    assertNotContains('Fatal', $html);
});

test('bill: the guest link needs the signed token, and the admin pages need a session', function (): void {
    getDB()->exec('DELETE FROM bills');
    $id = saveBill(sampleBill());
    $secret = 'test-doc-secret';
    $env = ['KFS_DOCUMENT_SIGNING_SECRET' => $secret];
    $token = hash_hmac('sha256', 'bill-' . $id, $secret);
    assertContains('TAX INVOICE', renderBillPage(['id' => (string)$id, 'token' => $token], false, $env));
    assertContains('Access denied', renderBillPage(['id' => (string)$id, 'token' => 'nope'], false, $env));
    assertContains('Access denied', renderBillPage(['id' => (string)$id], false));
    assertNotContains('New bill', renderBillPage(['new' => '1'], false, $env));
    // The admin sees a WhatsApp share of that same signed link; the guest does not.
    assertContains('Send on WhatsApp', renderBillPage(['id' => (string)$id], true, $env));
    assertNotContains('Send on WhatsApp', renderBillPage(['id' => (string)$id, 'token' => $token], false, $env));
});

test('bill: the form and list render, and the form\'s inline JS parses', function (): void {
    $bookingId = addBooking(['room_id' => 'tent', 'room_name' => 'Tent', 'check_in' => '2030-11-01', 'check_out' => '2030-11-03',
        'guest_name' => "O'Brien <b>", 'amount' => 6000, 'amount_paid' => 0, 'status' => 'confirmed']);
    $form = renderBillPage(['new' => '1', 'booking' => (string)$bookingId]);
    assertContains('Save &amp; issue bill', $form);
    assertContains('O&#039;Brien &lt;b&gt;', $form, 'guest name is escaped into the form');
    assertContains('Accommodation — Tent', $form);
    assertNotContains('<b>"', $form);
    assertTrue((bool)preg_match('#<script>(.*?)</script>#s', $form, $m), 'form has an inline script');
    $js = tempnam(sys_get_temp_dir(), 'kfs-bill-js-') . '.js';
    file_put_contents($js, $m[1]);
    $node = trim((string)shell_exec('command -v node'));
    if ($node !== '') {
        exec(escapeshellarg($node) . ' --check ' . escapeshellarg($js) . ' 2>&1', $out, $code);
        assertSame(0, $code, 'inline JS must parse: ' . implode("\n", $out));
    }
    @unlink($js);
    assertContains('Bills &amp; GST Invoices', renderBillPage([]));
    assertContains('Full postal address', renderBillPage(['profile' => '1']));
});

test('bill: the admin panel links to the generator from the sidebar and from each booking', function (): void {
    $admin = file_get_contents(dirname(__DIR__) . '/channel-manager/admin.php');
    assertContains("'bills'     => ['🧾', 'Bills / GST Invoice', 'bill.php', 0]", $admin);
    assertContains('bill.php?new=1&amp;booking=<?= (int)$b[\'id\'] ?>', $admin);
    assertContains('bill.php?new=1&booking=${b.id}', $admin);
});


runTests();
