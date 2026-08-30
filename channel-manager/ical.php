<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function parseIcalEvents(string $raw): array
{
    if (!preg_match('/BEGIN:VCALENDAR/i', $raw) || !preg_match('/END:VCALENDAR/i', $raw)) {
        throw new UnexpectedValueException('Response is not a complete iCalendar document.');
    }
    $raw = preg_replace("/\r\n|\r/", "\n", $raw);
    $raw = preg_replace("/\n[ \t]/", '', (string)$raw);
    $events = [];
    $current = null;

    foreach (explode("\n", (string)$raw) as $line) {
        $line = rtrim($line);
        if (strcasecmp($line, 'BEGIN:VEVENT') === 0) { $current = []; continue; }
        if (strcasecmp($line, 'END:VEVENT') === 0) {
            if (is_array($current)) $events[] = $current;
            $current = null;
            continue;
        }
        if (!is_array($current) || !str_contains($line, ':')) continue;
        [$lhs, $value] = explode(':', $line, 2);
        $segments = explode(';', $lhs);
        $name = strtoupper(array_shift($segments));
        $params = [];
        foreach ($segments as $segment) {
            if (!str_contains($segment, '=')) continue;
            [$key, $paramValue] = explode('=', $segment, 2);
            $params[strtoupper($key)] = trim($paramValue, '"');
        }
        $current[$name] = ['value'=>$value, 'params'=>$params];
    }
    return $events;
}

function icalUnescapeText(string $value): string
{
    return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value);
}

function icalPropertyDate(array $property, string $propertyTimezone = 'Asia/Kolkata'): ?string
{
    $value = trim((string)($property['value'] ?? ''));
    $params = $property['params'] ?? [];
    if (preg_match('/^\d{8}$/', $value)) {
        $dt = DateTimeImmutable::createFromFormat('!Ymd', $value, new DateTimeZone($propertyTimezone));
        return $dt ? $dt->format('Y-m-d') : null;
    }
    if (!preg_match('/^\d{8}T\d{6}Z?$/', $value)) return null;
    try {
        $isUtc = str_ends_with($value, 'Z');
        $sourceTimezone = $isUtc ? 'UTC' : (string)($params['TZID'] ?? $propertyTimezone);
        $format = $isUtc ? '!Ymd\THis\Z' : '!Ymd\THis';
        $dt = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone($sourceTimezone));
        return $dt ? $dt->setTimezone(new DateTimeZone($propertyTimezone))->format('Y-m-d') : null;
    } catch (Throwable) {
        return null;
    }
}

function normalizeIcalBlocks(string $raw, array $calendar, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $normalized = [];
    foreach (parseIcalEvents($raw) as $event) {
        $checkIn = isset($event['DTSTART']) ? icalPropertyDate($event['DTSTART']) : null;
        $checkOut = isset($event['DTEND']) ? icalPropertyDate($event['DTEND']) : null;
        if (!$checkIn || !$checkOut || $checkOut <= $checkIn || $checkOut < $today) continue;
        $status = strtoupper(trim((string)($event['STATUS']['value'] ?? 'CONFIRMED')));
        $transparent = strtoupper(trim((string)($event['TRANSP']['value'] ?? 'OPAQUE'))) === 'TRANSPARENT';
        if ($status === 'CANCELLED' || $transparent) continue;
        $summary = trim(icalUnescapeText((string)($event['SUMMARY']['value'] ?? 'Unavailable')));
        $uid = trim((string)($event['UID']['value'] ?? ''));
        if ($uid === '') {
            $uid = 'generated-' . hash('sha256', implode('|', [
                $calendar['room_id'], $calendar['platform'], $checkIn, $checkOut, $summary,
            ]));
        }
        $normalized[$uid] = [
            'calendar_id'=>(int)$calendar['id'],
            'room_id'=>(string)$calendar['room_id'],
            'platform'=>strtolower((string)$calendar['platform']),
            'external_uid'=>$uid,
            'check_in'=>$checkIn,
            'check_out'=>$checkOut,
            'summary'=>$summary,
            'status'=>$status,
            'raw_hash'=>hash('sha256', json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }
    return array_values($normalized);
}

function applyIcalSnapshot(array $calendar, array $blocks, ?PDO $db = null): void
{
    $db ??= getDB();
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) $db->exec('BEGIN IMMEDIATE');
    try {
        $calendarId = (int)$calendar['id'];
        $db->prepare('DELETE FROM external_blocks WHERE calendar_id=?')->execute([$calendarId]);
        $insert = $db->prepare("INSERT INTO external_blocks
            (calendar_id, room_id, platform, external_uid, check_in, check_out, summary, status, raw_hash)
            VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($blocks as $block) {
            $insert->execute([
                $calendarId, $calendar['room_id'], strtolower((string)$calendar['platform']),
                $block['external_uid'], $block['check_in'], $block['check_out'],
                $block['summary'] ?? '', $block['status'] ?? 'CONFIRMED', $block['raw_hash'] ?? '',
            ]);
        }
        $db->prepare("DELETE FROM bookings WHERE room_id=? AND source=? AND is_sync_imported=1")
           ->execute([$calendar['room_id'], strtolower((string)$calendar['platform'])]);
        $db->prepare("UPDATE external_calendars SET last_synced=datetime('now'), last_status='ok', last_error='' WHERE id=?")
           ->execute([$calendarId]);
        if ($ownTransaction) $db->exec('COMMIT');
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
}

function icalEscapeText(string $value): string
{
    return str_replace(['\\', ';', ',', "\r\n", "\r", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $value);
}

function foldIcalLine(string $line): string
{
    $parts = [];
    $remaining = $line;
    $limit = 75;
    while (strlen($remaining) > $limit) {
        $cut = $limit;
        while ($cut > 0 && (ord($remaining[$cut]) & 0xC0) === 0x80) $cut--;
        $parts[] = substr($remaining, 0, $cut);
        $remaining = substr($remaining, $cut);
        $limit = 74;
    }
    $parts[] = $remaining;
    return implode("\r\n ", $parts);
}

function icalLines(array $lines): string
{
    return implode("\r\n", array_map('foldIcalLine', $lines)) . "\r\n";
}

