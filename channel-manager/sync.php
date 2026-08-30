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

function syncOneCalendar(array $calendar, ?callable $fetcher = null): array
{
    try {
        if (!isValidRoomId((string)$calendar['room_id'])) throw new RuntimeException('Calendar has an invalid room.');
        $fetcher ??= 'fetchCalendarUrl';
        $raw = $fetcher((string)$calendar['ical_url']);
        if (!is_string($raw)) throw new RuntimeException('Calendar fetcher returned invalid data.');
        $blocks = normalizeIcalBlocks($raw, $calendar);
        applyIcalSnapshot($calendar, $blocks);
        return ['success'=>true, 'imported'=>count($blocks), 'blocks'=>count($blocks), 'error'=>''];
    } catch (Throwable $e) {
        if (!empty($calendar['id'])) {
            getDB()->prepare("UPDATE external_calendars SET last_status='error', last_error=? WHERE id=?")
                ->execute([substr($e->getMessage(), 0, 500), (int)$calendar['id']]);
        }
        return ['success'=>false, 'imported'=>0, 'blocks'=>0, 'error'=>$e->getMessage()];
    }
}

function runCalendarSync(?callable $fetcher = null): array
{
    $calendars = getDB()->query("SELECT * FROM external_calendars WHERE is_active=1 ORDER BY room_id, platform")->fetchAll();
    $results = [];
    foreach ($calendars as $calendar) {
        $results[] = array_merge(
            ['calendar_id'=>(int)$calendar['id'], 'platform'=>$calendar['platform'], 'room_id'=>$calendar['room_id']],
            syncOneCalendar($calendar, $fetcher)
        );
    }
    return $results;
}

function outputSyncResults(array $results, bool $json): void
{
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode([
            'results'=>$results,
            'sync_time'=>date('c'),
            'total_blocks'=>array_sum(array_column($results, 'blocks')),
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
    }
    outputSyncResults(runCalendarSync(), !$isCli);
}

