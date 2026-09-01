<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/demand-engine.php';

$isCli = PHP_SAPI === 'cli';
$providedToken = (string)($_GET['token'] ?? '');
if (!$isCli && (CRON_SECRET === '' || $providedToken === '' || !hash_equals(CRON_SECRET, $providedToken))) {
    http_response_code(403);
    exit("Forbidden\n");
}

$lockHandle = fopen(__DIR__ . '/cron.lock', 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if (!$isCli) header('Content-Type: application/json');
    echo $isCli ? "Another sync is already running.\n" : json_encode(['status'=>'busy']);
    exit;
}

$started = microtime(true);
$results = runCalendarSync();
$errors = array_values(array_filter($results, static fn(array $result): bool => !$result['success']));
$totalBlocks = array_sum(array_column($results, 'blocks'));
$seeded = 0;
$today = date('Y-m-d');
$lastSeedFile = __DIR__ . '/.last_demand_seed';
if (trim((string)@file_get_contents($lastSeedFile)) !== $today) {
    $seeded = seedDemandEvents();
    file_put_contents($lastSeedFile, $today, LOCK_EX);
}

$summary = [
    'status'=>$errors === [] ? 'ok' : 'partial',
    'calendars'=>count($results),
    'active_blocks'=>$totalBlocks,
    'demand_events_seeded'=>$seeded,
    'errors'=>array_map(static fn(array $result): array => [
        'calendar_id'=>$result['calendar_id'], 'platform'=>$result['platform'],
        'room_id'=>$result['room_id'], 'error'=>$result['error'],
    ], $errors),
    'elapsed_sec'=>round(microtime(true) - $started, 2),
    'timestamp'=>date(DATE_ATOM),
];

$logLine = '[' . date('Y-m-d H:i:s') . '] ' . json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
file_put_contents(__DIR__ . '/cron.log', $logLine, FILE_APPEND | LOCK_EX);
$logLines = file(__DIR__ . '/cron.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
if (count($logLines) > 200) {
    file_put_contents(__DIR__ . '/cron.log', implode(PHP_EOL, array_slice($logLines, -200)) . PHP_EOL, LOCK_EX);
}
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

if (!$isCli) header('Content-Type: application/json');
echo $isCli ? $logLine : json_encode($summary, JSON_UNESCAPED_SLASHES);
