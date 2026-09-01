<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function validYmd(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function validateStay(string $roomId, string $checkIn, string $checkOut, bool $allowPast = false): void
{
    if (!isValidRoomId($roomId)) throw new InvalidArgumentException('Unknown room.');
    if (!validYmd($checkIn) || !validYmd($checkOut)) throw new InvalidArgumentException('Invalid date.');
    if ($checkOut <= $checkIn) throw new InvalidArgumentException('Check-out must be after check-in.');
    if (!$allowPast && $checkIn < date('Y-m-d')) throw new InvalidArgumentException('Check-in cannot be in the past.');
}

function relatedInventoryIds(string $roomId): array
{
    if (!isValidRoomId($roomId)) return [];
    $related = [$roomId=>true];
    foreach (INVENTORY_COMPONENTS as $parent=>$components) {
        if ($roomId === $parent) {
            foreach ($components as $component) $related[$component] = true;
        }
        if (in_array($roomId, $components, true)) $related[$parent] = true;
    }
    ksort($related);
    return array_keys($related);
}

function inventoryOccupancyRows(
    string $fromDate,
    ?string $toDate = null,
    ?int $excludeBookingId = null,
    ?string $excludeHoldToken = null,
    ?string $excludeDestination = null,
    ?PDO $db = null
): array {
    if (!validYmd($fromDate) || ($toDate !== null && (!validYmd($toDate) || $toDate <= $fromDate))) {
        throw new InvalidArgumentException('Invalid occupancy range.');
    }
    $db ??= getDB();
    $rows = [];

    $bookingSql = "SELECT room_id, check_in, check_out, lower(source) AS origin
        FROM bookings WHERE status='confirmed' AND check_out > ?";
    $bookingParams = [$fromDate];
    if ($toDate !== null) { $bookingSql .= ' AND check_in < ?'; $bookingParams[] = $toDate; }
    if ($excludeBookingId !== null) { $bookingSql .= ' AND id != ?'; $bookingParams[] = $excludeBookingId; }
    if ($excludeDestination !== null && $excludeDestination !== 'generic') {
        $bookingSql .= ' AND lower(source) != ?';
        $bookingParams[] = $excludeDestination;
    }
    $stmt = $db->prepare($bookingSql);
    $stmt->execute($bookingParams);
    $rows = array_merge($rows, $stmt->fetchAll());

    $blockSql = "SELECT room_id, check_in, check_out, lower(platform) AS origin
        FROM external_blocks WHERE check_out > ?";
    $blockParams = [$fromDate];
    if ($toDate !== null) { $blockSql .= ' AND check_in < ?'; $blockParams[] = $toDate; }
    if ($excludeDestination !== null && $excludeDestination !== 'generic') {
        $blockSql .= ' AND lower(platform) != ?';
        $blockParams[] = $excludeDestination;
    }
    $stmt = $db->prepare($blockSql);
    $stmt->execute($blockParams);
    $rows = array_merge($rows, $stmt->fetchAll());

    $holdSql = "SELECT room_id, check_in, check_out, 'hold' AS origin
        FROM booking_holds WHERE status='pending' AND expires_at > datetime('now') AND check_out > ?";
    $holdParams = [$fromDate];
    if ($toDate !== null) { $holdSql .= ' AND check_in < ?'; $holdParams[] = $toDate; }
    if ($excludeHoldToken !== null) { $holdSql .= ' AND token != ?'; $holdParams[] = $excludeHoldToken; }
    $stmt = $db->prepare($holdSql);
    $stmt->execute($holdParams);
    return array_merge($rows, $stmt->fetchAll());
}

function mergeDateRanges(array $ranges): array
{
    if ($ranges === []) return [];
    usort($ranges, static fn(array $a, array $b): int => [$a['check_in'], $a['check_out']] <=> [$b['check_in'], $b['check_out']]);
    $merged = [];
    foreach ($ranges as $range) {
        if ($merged === [] || $range['check_in'] > $merged[array_key_last($merged)]['check_out']) {
            $merged[] = ['check_in'=>$range['check_in'], 'check_out'=>$range['check_out']];
            continue;
        }
        $last = array_key_last($merged);
        if ($range['check_out'] > $merged[$last]['check_out']) $merged[$last]['check_out'] = $range['check_out'];
    }
    return $merged;
}

function groupThresholdRangesFromRows(array $rows, string $fromDate, ?string $toDate = null): array
{
    $occupiedByNight = [];
    foreach ($rows as $row) {
        $roomId = (string)($row['room_id'] ?? '');
        if ($roomId === GROUP_INVENTORY_ID || !isset(ROOM_IDS[$roomId])) continue;
        $start = max($fromDate, (string)$row['check_in']);
        $end = (string)$row['check_out'];
        if ($toDate !== null) $end = min($toDate, $end);
        if ($end <= $start) continue;
        for ($night = $start; $night < $end; $night = (new DateTimeImmutable($night))->modify('+1 day')->format('Y-m-d')) {
            $occupiedByNight[$night][$roomId] = true;
        }
    }

    $blockedNights = [];
    foreach ($occupiedByNight as $night => $rooms) {
        if (count($rooms) >= GROUP_BOOKING_THRESHOLD) $blockedNights[] = $night;
    }
    sort($blockedNights);
    $ranges = [];
    foreach ($blockedNights as $night) {
        $next = (new DateTimeImmutable($night))->modify('+1 day')->format('Y-m-d');
        $last = array_key_last($ranges);
        if ($last !== null && $ranges[$last]['check_out'] === $night) $ranges[$last]['check_out'] = $next;
        else $ranges[] = ['check_in'=>$night, 'check_out'=>$next];
    }
    return $ranges;
}

function groupBlockedRangesFromRows(array $rows, string $fromDate, ?string $toDate = null): array
{
    $ranges = groupThresholdRangesFromRows($rows, $fromDate, $toDate);
    foreach ($rows as $row) {
        if (($row['room_id'] ?? '') !== GROUP_INVENTORY_ID) continue;
        $start = max($fromDate, (string)$row['check_in']);
        $end = (string)$row['check_out'];
        if ($toDate !== null) $end = min($toDate, $end);
        if ($end > $start) $ranges[] = ['check_in'=>$start, 'check_out'=>$end];
    }
    return mergeDateRanges($ranges);
}

function groupBlockedRanges(
    string $fromDate,
    ?string $toDate = null,
    ?int $excludeBookingId = null,
    ?string $excludeHoldToken = null,
    ?string $excludeDestination = null,
    ?PDO $db = null
): array {
    $rows = inventoryOccupancyRows(
        $fromDate, $toDate, $excludeBookingId, $excludeHoldToken, $excludeDestination, $db
    );
    return groupBlockedRangesFromRows($rows, $fromDate, $toDate);
}

function isInventoryAvailable(
    string $roomId,
    string $checkIn,
    string $checkOut,
    ?int $excludeBookingId = null,
    ?string $excludeHoldToken = null,
    ?PDO $db = null
): bool {
    validateStay($roomId, $checkIn, $checkOut);
    $db ??= getDB();
    if ($roomId === GROUP_INVENTORY_ID) {
        return groupBlockedRanges($checkIn, $checkOut, $excludeBookingId, $excludeHoldToken, null, $db) === [];
    }
    $rooms = relatedInventoryIds($roomId);
    $roomPlaceholders = implode(',', array_fill(0, count($rooms), '?'));

    $bookingSql = "SELECT 1 FROM bookings WHERE room_id IN ({$roomPlaceholders}) AND status='confirmed' AND check_in < ? AND check_out > ?";
    $bookingParams = array_merge($rooms, [$checkOut, $checkIn]);
    if ($excludeBookingId !== null) { $bookingSql .= ' AND id != ?'; $bookingParams[] = $excludeBookingId; }
    $stmt = $db->prepare($bookingSql . ' LIMIT 1');
    $stmt->execute($bookingParams);
    if ($stmt->fetchColumn()) return false;

    $stmt = $db->prepare("SELECT 1 FROM external_blocks WHERE room_id IN ({$roomPlaceholders}) AND check_in < ? AND check_out > ? LIMIT 1");
    $stmt->execute(array_merge($rooms, [$checkOut, $checkIn]));
    if ($stmt->fetchColumn()) return false;

    $holdSql = "SELECT 1 FROM booking_holds WHERE room_id IN ({$roomPlaceholders}) AND status='pending' AND expires_at > datetime('now') AND check_in < ? AND check_out > ?";
    $holdParams = array_merge($rooms, [$checkOut, $checkIn]);
    if ($excludeHoldToken !== null) { $holdSql .= ' AND token != ?'; $holdParams[] = $excludeHoldToken; }
    $stmt = $db->prepare($holdSql . ' LIMIT 1');
    $stmt->execute($holdParams);
    return !$stmt->fetchColumn();
}

function cleanupExpiredHolds(?PDO $db = null): int
{
    $db ??= getDB();
    return $db->exec("UPDATE booking_holds SET status='expired', updated_at=datetime('now') WHERE status='pending' AND expires_at <= datetime('now')");
}

function createBookingHold(array $data): array
{
    $roomId = trim((string)($data['room_id'] ?? ''));
    $checkIn = trim((string)($data['check_in'] ?? ''));
    $checkOut = trim((string)($data['check_out'] ?? ''));
    validateStay($roomId, $checkIn, $checkOut);

    foreach (['guest_name', 'guest_email', 'guest_phone'] as $field) {
        if (trim((string)($data[$field] ?? '')) === '') throw new InvalidArgumentException("Missing {$field}.");
    }
    $amount = (float)($data['amount'] ?? 0);
    if ($amount <= 0) throw new InvalidArgumentException('Amount must be positive.');

    $db = getDB();
    $db->exec('BEGIN IMMEDIATE');
    try {
        cleanupExpiredHolds($db);
        if (!isInventoryAvailable($roomId, $checkIn, $checkOut, null, null, $db)) {
            throw new DomainException('The requested inventory is no longer available.');
        }
        $token = bin2hex(random_bytes(24));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + PAYMENT_HOLD_MINUTES * 60);
        $stmt = $db->prepare("INSERT INTO booking_holds
            (token, room_id, check_in, check_out, guest_name, guest_email, guest_phone, adults, children, amount, expires_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $token, $roomId, $checkIn, $checkOut,
            trim((string)$data['guest_name']), trim((string)$data['guest_email']), trim((string)$data['guest_phone']),
            max(1, (int)($data['adults'] ?? 1)), max(0, (int)($data['children'] ?? 0)), $amount, $expiresAt,
        ]);
        $db->exec('COMMIT');
        return ['token'=>$token, 'expires_at'=>$expiresAt, 'amount'=>$amount];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
}

function releaseBookingHold(string $token): void
{
    getDB()->prepare("UPDATE booking_holds SET status='released', updated_at=datetime('now') WHERE token=? AND status='pending'")->execute([$token]);
}

function createConfirmedBooking(array $data): int
{
    $roomId = trim((string)($data['room_id'] ?? ''));
    $checkIn = trim((string)($data['check_in'] ?? ''));
    $checkOut = trim((string)($data['check_out'] ?? ''));
    validateStay($roomId, $checkIn, $checkOut);

    $db = getDB();
    $db->exec('BEGIN IMMEDIATE');
    try {
        cleanupExpiredHolds($db);
        if (!isInventoryAvailable($roomId, $checkIn, $checkOut, null, null, $db)) {
            throw new DomainException('Those dates conflict with a booking, OTA block, or payment hold.');
        }
        $data['room_id'] = $roomId;
        $data['room_name'] = ROOM_IDS[$roomId];
        $data['check_in'] = $checkIn;
        $data['check_out'] = $checkOut;
        $data['status'] = 'confirmed';
        $id = addBooking($data);
        if (!$id) throw new DomainException('This booking already exists.');
        $db->exec('COMMIT');
        return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
}

function updateConfirmedBooking(int $id, array $data): void
{
    $existing = getBookingById($id);
    if (!$existing) throw new InvalidArgumentException('Booking not found.');

    $roomId = trim((string)($data['room_id'] ?? $existing['room_id']));
    $checkIn = trim((string)($data['check_in'] ?? $existing['check_in']));
    $checkOut = trim((string)($data['check_out'] ?? $existing['check_out']));
    $status = trim((string)($data['status'] ?? $existing['status']));
    if (!in_array($status, ['confirmed', 'cancelled'], true)) {
        throw new InvalidArgumentException('Invalid booking status.');
    }
    validateStay($roomId, $checkIn, $checkOut, true);

    $db = getDB();
    $db->exec('BEGIN IMMEDIATE');
    try {
        cleanupExpiredHolds($db);
        if ($status === 'confirmed' && !isInventoryAvailable($roomId, $checkIn, $checkOut, $id, null, $db)) {
            throw new DomainException('Those dates conflict with another booking, OTA block, or payment hold.');
        }
        $data['room_id'] = $roomId;
        $data['room_name'] = ROOM_IDS[$roomId] ?? $roomId;
        $data['check_in'] = $checkIn;
        $data['check_out'] = $checkOut;
        $data['status'] = $status;
        $data['guest_name'] = trim((string)($data['guest_name'] ?? $existing['guest_name']));
        if ($data['guest_name'] === '') $data['guest_name'] = 'Guest';
        $data['guest_email'] = trim((string)($data['guest_email'] ?? $existing['guest_email']));
        $data['guest_phone'] = trim((string)($data['guest_phone'] ?? $existing['guest_phone']));
        $data['whatsapp_number'] = trim((string)($data['whatsapp_number'] ?? $existing['whatsapp_number']));
        $data['source'] = trim((string)($data['source'] ?? $existing['source']));
        $data['booking_ref'] = trim((string)($data['booking_ref'] ?? $existing['booking_ref']));
        $data['amount'] = max(0.0, (float)($data['amount'] ?? $existing['amount']));
        $data['amount_paid'] = max(0.0, (float)($data['amount_paid'] ?? $existing['amount_paid']));
        $data['payment_method'] = trim((string)($data['payment_method'] ?? $existing['payment_method']));
        $data['payment_status'] = trim((string)($data['payment_status'] ?? $existing['payment_status']));
        $data['notes'] = trim((string)($data['notes'] ?? $existing['notes']));

        updateBooking($id, $data);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
}

function roomPricing(string $roomId, ?PDO $db = null): array
{
    if (!isset(ROOM_PRICING[$roomId])) throw new InvalidArgumentException('Unknown room.');
    $pricing = ROOM_PRICING[$roomId];
    $db ??= getDB();
    $stmt = $db->prepare('SELECT base_price FROM room_rates WHERE room_id=?');
    $stmt->execute([$roomId]);
    $saved = (float)($stmt->fetchColumn() ?: 0);
    if ($saved > 0) {
        $pricing['weekday'] = $saved;
        $pricing['weekend'] = $saved;
    }
    return $pricing;
}

function calculateQuote(string $roomId, string $checkIn, string $checkOut, int $adults, int $children, ?PDO $db = null): array
{
    validateStay($roomId, $checkIn, $checkOut);
    $pricing = roomPricing($roomId, $db);
    if ($adults < 1 || $adults > $pricing['max_adults']) throw new InvalidArgumentException('Invalid number of adults.');
    if ($children < 0 || $children > $pricing['max_children']) throw new InvalidArgumentException('Invalid number of children.');

    $cursor = new DateTimeImmutable($checkIn);
    $end = new DateTimeImmutable($checkOut);
    $weekdayNights = 0;
    $weekendNights = 0;
    $baseTotal = 0.0;
    while ($cursor < $end) {
        $isWeekend = in_array((int)$cursor->format('N'), WEEKEND_ISO_DAYS, true);
        if ($isWeekend) { $weekendNights++; $baseTotal += (float)$pricing['weekend']; }
        else { $weekdayNights++; $baseTotal += (float)$pricing['weekday']; }
        $cursor = $cursor->modify('+1 day');
    }
    $nights = $weekdayNights + $weekendNights;
    $extraAdults = max(0, $adults - (int)$pricing['base_adults']);
    $extraChildren = max(0, $children - (int)$pricing['base_children']);
    $extraTotal = ($extraAdults * EXTRA_ADULT_RATE + $extraChildren * EXTRA_CHILD_RATE) * $nights;
    return [
        'room_id'=>$roomId, 'check_in'=>$checkIn, 'check_out'=>$checkOut,
        'nights'=>$nights, 'weekday_nights'=>$weekdayNights, 'weekend_nights'=>$weekendNights,
        'adults'=>$adults, 'children'=>$children, 'base_total'=>$baseTotal,
        'extra_adults'=>$extraAdults, 'extra_children'=>$extraChildren,
        'extra_total'=>(float)$extraTotal, 'total'=>(float)($baseTotal + $extraTotal),
    ];
}

function getBlockedRangesForRoom(string $roomId, ?string $fromDate = null, ?PDO $db = null): array
{
    if (!isValidRoomId($roomId)) throw new InvalidArgumentException('Unknown room.');
    $fromDate ??= date('Y-m-d');
    if (!validYmd($fromDate)) throw new InvalidArgumentException('Invalid starting date.');
    $db ??= getDB();
    if ($roomId === GROUP_INVENTORY_ID) return groupBlockedRanges($fromDate, null, null, null, null, $db);
    $rooms = relatedInventoryIds($roomId);
    $ph = implode(',', array_fill(0, count($rooms), '?'));
    $ranges = [];
    foreach ([
        ["SELECT check_in, check_out FROM bookings WHERE room_id IN ({$ph}) AND status='confirmed' AND check_out >= ?", array_merge($rooms, [$fromDate])],
        ["SELECT check_in, check_out FROM external_blocks WHERE room_id IN ({$ph}) AND check_out >= ?", array_merge($rooms, [$fromDate])],
        ["SELECT check_in, check_out FROM booking_holds WHERE room_id IN ({$ph}) AND status='pending' AND expires_at > datetime('now') AND check_out >= ?", array_merge($rooms, [$fromDate])],
    ] as [$sql, $params]) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) $ranges[$row['check_in'] . '|' . $row['check_out']] = $row;
    }
    ksort($ranges);
    return array_values($ranges);
}

function getExternalBlockCalendarEntries(?string $fromDate = null, ?string $toDate = null, ?PDO $db = null): array
{
    if ($fromDate !== null && !validYmd($fromDate)) throw new InvalidArgumentException('Invalid starting date.');
    if ($toDate !== null && !validYmd($toDate)) throw new InvalidArgumentException('Invalid ending date.');
    if ($fromDate !== null && $toDate !== null && $toDate <= $fromDate) throw new InvalidArgumentException('Invalid date range.');
    $db ??= getDB();
    $where = [];
    $params = [];
    if ($fromDate !== null) { $where[] = 'check_out > ?'; $params[] = $fromDate; }
    if ($toDate !== null) { $where[] = 'check_in < ?'; $params[] = $toDate; }
    $sql = 'SELECT id, room_id, platform, check_in, check_out FROM external_blocks';
    if ($where !== []) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY check_in, room_id, platform';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return array_map(static fn(array $row): array => [
        'id'=>-1_000_000 - (int)$row['id'],
        'room_id'=>$row['room_id'],
        'room_name'=>ROOM_IDS[$row['room_id']] ?? $row['room_id'],
        'check_in'=>$row['check_in'], 'check_out'=>$row['check_out'],
        'guest_name'=>'OTA unavailable', 'guest_email'=>'', 'guest_phone'=>'', 'whatsapp_number'=>'',
        'source'=>strtolower((string)$row['platform']), 'booking_ref'=>'',
        'amount'=>0.0, 'amount_paid'=>0.0, 'payment_method'=>'', 'payment_status'=>'unpaid',
        'status'=>'confirmed', 'uid'=>'external-block-' . (int)$row['id'] . '@kanchifarmstay.com',
        'notes'=>'Imported iCal availability block', 'is_sync_imported'=>1, 'is_external_block'=>1,
    ], $stmt->fetchAll());
}

function expandCalendarEntriesToRelatedInventory(array $entries): array
{
    $expanded = [];
    foreach ($entries as $entry) {
        $originRoomId = (string)($entry['room_id'] ?? '');
        $targets = $originRoomId === GROUP_INVENTORY_ID
            ? array_keys(ROOM_IDS)
            : array_values(array_filter(
                relatedInventoryIds($originRoomId),
                static fn(string $id): bool => $id !== GROUP_INVENTORY_ID
            ));
        foreach ($targets as $targetRoomId) {
            $copy = $entry;
            $copy['inventory_origin_room_id'] = $originRoomId;
            $copy['room_id'] = $targetRoomId;
            $copy['room_name'] = ROOM_IDS[$targetRoomId];
            $copy['is_derived_inventory'] = $targetRoomId !== $originRoomId ? 1 : 0;
            $expanded[] = $copy;
        }
    }

    $validEntries = array_values(array_filter($entries, static fn(array $entry): bool =>
        validYmd((string)($entry['check_in'] ?? '')) && validYmd((string)($entry['check_out'] ?? ''))
    ));
    if ($validEntries !== []) {
        $fromDate = min(array_column($validEntries, 'check_in'));
        $toDate = max(array_column($validEntries, 'check_out'));
        foreach (groupThresholdRangesFromRows($validEntries, $fromDate, $toDate) as $index => $range) {
            $expanded[] = [
                'id'=>-2_000_000 - $index,
                'room_id'=>GROUP_INVENTORY_ID, 'room_name'=>ROOM_IDS[GROUP_INVENTORY_ID],
                'check_in'=>$range['check_in'], 'check_out'=>$range['check_out'],
                'guest_name'=>GROUP_BOOKING_THRESHOLD . '+ rooms occupied',
                'guest_email'=>'', 'guest_phone'=>'', 'whatsapp_number'=>'',
                'source'=>'blocked', 'booking_ref'=>'', 'amount'=>0.0, 'amount_paid'=>0.0,
                'payment_method'=>'', 'payment_status'=>'unpaid', 'status'=>'confirmed',
                'notes'=>'Group inventory threshold reached', 'is_group_threshold'=>1,
                'is_derived_inventory'=>1, 'inventory_origin_room_id'=>'threshold',
            ];
        }
    }
    return $expanded;
}
