<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ical.php';

function fetchCalendarUrl(string $url): string
{
    $current = $url;
    for ($redirects = 0; $redirects <= 3; $redirects++) {
        if (!isSafeCalendarUrl($current)) throw new RuntimeException('Unsafe calendar URL.');
        $ch = curl_init($current);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_TIMEOUT=>30,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_USERAGENT=>'KanchiFarmStay-CalendarSync/2.0',
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirect = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error = curl_error($ch);
        if ($body === false) throw new RuntimeException($error ?: 'Calendar request failed.');
        if ($status >= 300 && $status < 400 && $redirect !== '') {
            $current = $redirect;
            continue;
        }
        if ($status !== 200) throw new RuntimeException("Calendar returned HTTP {$status}.");
        if (strlen($body) > 5_000_000) throw new RuntimeException('Calendar response is too large.');
        return $body;
    }
    throw new RuntimeException('Too many calendar redirects.');
}

/**
 * Fetch and parse one calendar. Deliberately writes nothing: the snapshot and
 * the echo sweeps are applied together at the end of the run, so the site never
 * reads a half-swept table.
 */
function prepareOneCalendar(array $calendar, ?callable $fetcher = null): array
{
    try {
        if (!isValidRoomId((string)$calendar['room_id'])) throw new RuntimeException('Calendar has an invalid room.');
        $fetcher ??= 'fetchCalendarUrl';
        $raw = $fetcher((string)$calendar['ical_url']);
        if (!is_string($raw)) throw new RuntimeException('Calendar fetcher returned invalid data.');
        $blocks = normalizeIcalBlocks($raw, $calendar);
        return ['success'=>true, 'imported'=>count($blocks), 'blocks'=>count($blocks), 'error'=>'', 'parsed'=>$blocks];
    } catch (Throwable $e) {
        if (!empty($calendar['id'])) {
            getDB()->prepare("UPDATE external_calendars SET last_status='error', last_error=? WHERE id=?")
                ->execute([substr($e->getMessage(), 0, 500), (int)$calendar['id']]);
        }
        return ['success'=>false, 'imported'=>0, 'blocks'=>0, 'error'=>$e->getMessage(), 'parsed'=>[]];
    }
}

/** Fetch, store and sweep one calendar on its own. Used by tests and by hand. */
function syncOneCalendar(array $calendar, ?callable $fetcher = null): array
{
    $result = prepareOneCalendar($calendar, $fetcher);
    if (!$result['success']) { unset($result['parsed']); return $result; }
    try {
        applyIcalSnapshot($calendar, $result['parsed']);
    } catch (Throwable $e) {
        if (!empty($calendar['id'])) {
            getDB()->prepare("UPDATE external_calendars SET last_status='error', last_error=? WHERE id=?")
                ->execute([substr($e->getMessage(), 0, 500), (int)$calendar['id']]);
        }
        return ['success'=>false, 'imported'=>0, 'blocks'=>0, 'error'=>$e->getMessage()];
    }
    unset($result['parsed']);
    return $result;
}

function runCalendarSync(?callable $fetcher = null): array
{
    $db = getDB();
    $calendars = $db->query("SELECT * FROM external_calendars WHERE is_active=1 ORDER BY room_id, platform")->fetchAll();

    // Phase 1 - network only. Thirty feeds take about ten seconds and none of
    // it may happen with the database write-locked.
    $results = [];
    $pending = [];
    foreach ($calendars as $calendar) {
        $result = prepareOneCalendar($calendar, $fetcher);
        if ($result['success']) $pending[] = [$calendar, $result['parsed']];
        unset($result['parsed']);
        $results[] = array_merge(
            ['calendar_id'=>(int)$calendar['id'], 'platform'=>$calendar['platform'], 'room_id'=>$calendar['room_id']],
            $result
        );
    }

    // Phase 2 - one transaction, milliseconds. Storing the snapshots and then
    // sweeping in separate transactions left the echoes readable in between,
    // so for the ten seconds of every run the site showed rooms as booked that
    // were free the moment the sweeps landed.
    $ownTransaction = kfsBeginTransaction($db);
    try {
        foreach ($pending as [$calendar, $blocks]) {
            applyIcalSnapshot($calendar, $blocks, $db);
        }
        // Echoes are only recognisable once every feed that answered has been
        // stored, which is why the sweeps run here and not per calendar.
        removeSharedAirbnbEchoBlocks($db);
        // A parent-level block closes every room beneath it, and on booking.com
        // and agoda no wording distinguishes a block from a reservation. Drop
        // the parent copy only where a component feed carries the same block.
        removeParentInventoryEchoBlocks($db);
        // Anything whose dates we already hold ourselves.
        removeOwnBookingEchoBlocks($db);
        kfsCommitTransaction($db, $ownTransaction);
    } catch (Throwable $e) {
        kfsRollbackTransaction($db, $ownTransaction);
        $message = 'Snapshot failed: ' . $e->getMessage();
        foreach ($results as $index => $result) {
            if (!$result['success']) continue;
            $results[$index] = array_merge($result, ['success'=>false, 'imported'=>0, 'blocks'=>0, 'error'=>$message]);
            $db->prepare("UPDATE external_calendars SET last_status='error', last_error=? WHERE id=?")
               ->execute([substr($message, 0, 500), (int)$result['calendar_id']]);
        }
    }
    return $results;
}

/**
 * Hold the same lock cron.php uses. Two syncs at once would sweep a table the
 * other is still refilling, and the sweeps decide what is bookable.
 */
function withSyncLock(callable $work): mixed
{
    $handle = fopen(__DIR__ . '/cron.lock', 'c');
    if ($handle === false) return $work();
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new RuntimeException('Another sync is already running.');
    }
    try {
        return $work();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function outputSyncResults(array $results, bool $json): void
{
    if ($json) {
        $activeBlocks = (int)getDB()->query('SELECT COUNT(*) FROM external_blocks')->fetchColumn();
        header('Content-Type: application/json');
        echo json_encode([
            'results'=>$results,
            'sync_time'=>date('c'),
            'total_blocks'=>$activeBlocks,
        ], JSON_UNESCAPED_SLASHES);
        return;
    }
    foreach ($results as $result) {
        $status = $result['success'] ? "OK — {$result['blocks']} active block(s)" : "FAILED — {$result['error']}";
        echo "[{$result['platform']}] {$result['room_id']}: {$status}\n";
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $isCli = PHP_SAPI === 'cli';
    if (!$isCli) {
        startSecureSession();
        if (empty($_SESSION['admin_logged_in'])) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error'=>'Unauthorized']);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            header('Content-Type: application/json');
            echo json_encode(['error'=>'Method not allowed']);
            exit;
        }
        requireValidCsrfToken($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null));
    }
    try {
        $results = withSyncLock(static fn(): array => runCalendarSync());
    } catch (RuntimeException $e) {
        if (!$isCli) {
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode(['error'=>$e->getMessage()]);
            exit;
        }
        exit($e->getMessage() . "\n");
    }
    if (!$isCli) {
        $_SESSION['last_sync_results'] = $results;
        $_SESSION['last_sync_time'] = date('Y-m-d H:i:s');
    }
    outputSyncResults($results, !$isCli);
}
