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

