<?php
/**
 * Front desk: stay status, the guest ID register (with Form C for foreign
 * guests) and housekeeping.
 *
 * Stay status is separate from booking status. `status` says whether a room is
 * sold (confirmed / cancelled) and drives availability; `stay_status` says what
 * happened at the gate (expected -> checked_in -> checked_out, or no_show) and
 * never touches availability.
 *
 * Guest register. Indian law requires a register of guests with photo ID, and
 * foreign guests must be reported to the FRRO on Form C within 24 hours of
 * arrival. Aadhaar is the exception to "store what you see": UIDAI rules bar a
 * business from keeping full Aadhaar numbers, so only the last four digits are
 * ever written. Passport and visa details are stored in full because Form C
 * needs them. ID photos live outside the web root and are served only to a
 * logged-in admin by guest-id-file.php.
 *
 * Housekeeping tracks the PHYSICAL rooms (bundles like the whole farm or the
 * full first floor expand to their rooms). Checking a booking out marks its
 * rooms dirty.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/wa-templates.php';

const FD_ID_TYPES = ['aadhaar' => 'Aadhaar', 'passport' => 'Passport', 'driving_licence' => 'Driving licence',
                     'voter_id' => 'Voter ID', 'pan' => 'PAN card', 'other' => 'Other photo ID'];
const FD_ROOM_STATUSES = ['ready' => 'Ready', 'dirty' => 'Dirty', 'cleaning' => 'Cleaning', 'maintenance' => 'Maintenance'];
const FD_FORM_C_HOURS = 24;
const FD_MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
const FD_UPLOAD_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

function fdGuestIdDir(): string {
    $dir = dirname(DB_PATH) . '/guest-ids';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function fdStayStatus(array $b): string {
    $s = (string)($b['stay_status'] ?? '');
    return $s === '' ? 'expected' : $s;
}

/** Bookings that matter at the gate: sold, not a date block. */
function fdBookingsWhere(string $where, array $args): array {
    $q = getDB()->prepare("SELECT * FROM bookings WHERE status = 'confirmed' AND source <> 'blocked' AND {$where} ORDER BY room_name, id");
    $q->execute($args);
    return $q->fetchAll();
}

/** @return array{arrivals:array, departures:array, inhouse:array, overdue:array} */
function fdDay(string $date): array {
    return [
        'arrivals'   => fdBookingsWhere('check_in = ?', [$date]),
        'departures' => fdBookingsWhere('check_out = ?', [$date]),
        'inhouse'    => fdBookingsWhere("stay_status = 'checked_in'", []),
        // Should have arrived before today and nobody marked them.
        'overdue'    => fdBookingsWhere("check_in < ? AND check_out > ? AND (stay_status IS NULL OR stay_status IN ('', 'expected'))", [$date, $date]),
    ];
}

// ── Guest IDs ────────────────────────────────────────────────

/** Aadhaar: keep the last four digits only. Everything else: trimmed, upper-cased. */
function fdStoredIdNumber(string $type, string $raw): string {
    $clean = strtoupper(preg_replace('/\s+/', '', trim($raw)));
    if ($type === 'aadhaar') {
        $digits = preg_replace('/\D/', '', $clean);
        return $digits === '' ? '' : 'XXXX-XXXX-' . substr($digits, -4);
    }
    return mb_substr($clean, 0, 40);
}

function fdIsForeign(string $nationality): bool {
    $n = strtolower(trim($nationality));
    return $n !== '' && !in_array($n, ['india', 'indian', 'in', 'ind'], true);
}

/**
 * Validate one guest row from the check-in form.
 * @return array the row ready to insert
 */
function fdGuestFromInput(array $g): array {
    $name = trim((string)($g['name'] ?? ''));
    $type = (string)($g['id_type'] ?? '');
    $number = trim((string)($g['id_number'] ?? ''));
    $nationality = trim((string)($g['nationality'] ?? 'Indian')) ?: 'Indian';
    if ($name === '') throw new InvalidArgumentException('Each guest needs a name.');
    if (!isset(FD_ID_TYPES[$type])) throw new InvalidArgumentException("Choose an ID type for {$name}.");
    if ($number === '') throw new InvalidArgumentException("Enter the ID number for {$name}.");
    if ($type === 'aadhaar' && strlen(preg_replace('/\D/', '', $number)) < 4) throw new InvalidArgumentException("Aadhaar for {$name}: enter at least the last 4 digits.");
    $foreign = fdIsForeign($nationality);
    $row = [
        'name' => mb_substr($name, 0, 120), 'id_type' => $type, 'id_number' => fdStoredIdNumber($type, $number),
        'nationality' => mb_substr($nationality, 0, 60), 'is_foreign' => $foreign ? 1 : 0,
        'phone' => mb_substr(trim((string)($g['phone'] ?? '')), 0, 30),
        'passport_no' => '', 'passport_expiry' => '', 'visa_no' => '', 'visa_type' => '', 'visa_expiry' => '',
        'arrived_india_on' => '', 'coming_from' => '', 'next_destination' => '',
    ];
    if ($foreign) {
        foreach (['passport_no', 'visa_no', 'visa_type', 'coming_from', 'next_destination'] as $k) {
            $row[$k] = mb_substr(strtoupper(trim((string)($g[$k] ?? ''))), 0, 60);
        }
        foreach (['passport_expiry', 'visa_expiry', 'arrived_india_on'] as $k) {
            $v = (string)($g[$k] ?? '');
            $row[$k] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        }
        if ($row['passport_no'] === '' && $type === 'passport') $row['passport_no'] = $row['id_number'];
        if ($row['passport_no'] === '') throw new InvalidArgumentException("{$name} is a foreign national: the passport number is needed for Form C.");
    }
    return $row;
}

/**
 * Store an uploaded ID image/PDF. Returns the stored relative path or '' when
 * nothing was uploaded. The file type is read from the bytes, not the name.
 */
function fdStoreIdUpload(int $bookingId, ?array $file): string {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('The ID photo did not upload. Try again.');
    if ((int)$file['size'] > FD_MAX_UPLOAD_BYTES) throw new InvalidArgumentException('The ID photo is larger than 5 MB.');
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(FD_UPLOAD_TYPES[$mime])) throw new InvalidArgumentException('The ID photo must be a JPG, PNG, WEBP or PDF.');
    $dir = fdGuestIdDir() . '/' . $bookingId;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $name = bin2hex(random_bytes(12)) . '.' . FD_UPLOAD_TYPES[$mime];
    $dest = $dir . '/' . $name;
    $moved = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], $dest) : @rename($file['tmp_name'], $dest);
    if (!$moved) throw new InvalidArgumentException('Could not save the ID photo.');
    @chmod($dest, 0600);
    return $bookingId . '/' . $name;
}

/** The absolute path for a stored photo, or null if the value is not one we wrote. */
function fdIdFilePath(string $stored): ?string {
    if (!preg_match('#^(\d+)/([a-f0-9]{24})\.(jpg|png|webp|pdf)$#', $stored)) return null;
    $path = fdGuestIdDir() . '/' . $stored;
    return is_file($path) ? $path : null;
}

function fdGuestsForBooking(int $bookingId): array {
    $q = getDB()->prepare('SELECT * FROM guest_ids WHERE booking_id = ? ORDER BY id');
    $q->execute([$bookingId]);
    return $q->fetchAll();
}

/** The guest register: everyone recorded at check-in, filtered by stay date and a search. */
function fdRegister(string $from, string $to, string $q = ''): array {
    $sql = "SELECT g.*, b.room_name, b.check_in, b.check_out, b.checked_in_at, b.source
            FROM guest_ids g JOIN bookings b ON b.id = g.booking_id WHERE b.check_in <= ? AND b.check_out >= ?";
    $args = [$to, $from];
    if (trim($q) !== '') {
        $sql .= " AND (g.name LIKE ? OR g.phone LIKE ? OR g.id_number LIKE ? OR g.passport_no LIKE ? OR g.booking_id = ?)";
        $like = '%' . trim($q) . '%';
        array_push($args, $like, $like, $like, $like, (int)ltrim(preg_replace('/\D/', '', $q), '0'));
    }
    $st = getDB()->prepare($sql . ' ORDER BY b.check_in DESC, g.booking_id, g.id');
    $st->execute($args);
    return $st->fetchAll();
}

// ── Stay status ──────────────────────────────────────────────

/**
 * Check a booking in and record its guests.
 * @param array $guests rows from the form; $files the matching uploads by index
 */
function fdCheckIn(int $bookingId, array $guests, array $files = []): void {
    $b = getBookingById($bookingId);
    if (!$b || $b['status'] !== 'confirmed') throw new InvalidArgumentException('Only a confirmed booking can be checked in.');
    if (fdStayStatus($b) === 'checked_in') throw new InvalidArgumentException('This booking is already checked in.');
    $rows = [];
    foreach (array_values($guests) as $i => $g) {
        if (trim((string)($g['name'] ?? '')) === '' && trim((string)($g['id_number'] ?? '')) === '') continue;
        $rows[$i] = fdGuestFromInput($g);
    }
    if (!$rows) throw new InvalidArgumentException('Record at least one guest with photo ID.');
    $db = getDB();
    $owned = kfsBeginTransaction($db);
    try {
        $ins = $db->prepare("INSERT INTO guest_ids (booking_id, name, id_type, id_number, id_photo, nationality, is_foreign, phone,
            passport_no, passport_expiry, visa_no, visa_type, visa_expiry, arrived_india_on, coming_from, next_destination)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($rows as $i => $r) {
            $photo = fdStoreIdUpload($bookingId, $files[$i] ?? null);
            $ins->execute([$bookingId, $r['name'], $r['id_type'], $r['id_number'], $photo, $r['nationality'], $r['is_foreign'], $r['phone'],
                $r['passport_no'], $r['passport_expiry'], $r['visa_no'], $r['visa_type'], $r['visa_expiry'],
                $r['arrived_india_on'], $r['coming_from'], $r['next_destination']]);
        }
        $db->prepare("UPDATE bookings SET stay_status = 'checked_in', checked_in_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")
           ->execute([$bookingId]);
        kfsCommitTransaction($db, $owned);
    } catch (Throwable $e) {
        kfsRollbackTransaction($db, $owned);
        throw $e;
    }
}

function fdCheckOut(int $bookingId): void {
    $b = getBookingById($bookingId);
    if (!$b || fdStayStatus($b) !== 'checked_in') throw new InvalidArgumentException('Only a checked-in booking can be checked out.');
    getDB()->prepare("UPDATE bookings SET stay_status = 'checked_out', checked_out_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")
        ->execute([$bookingId]);
    foreach (waExpandRoom($b['room_id']) as $room) hkSetStatus($room, 'dirty', 'Checked out: booking #' . waBookingNo($b));
}

function fdMarkNoShow(int $bookingId, ?string $today = null): void {
    $today ??= date('Y-m-d');
    $b = getBookingById($bookingId);
    if (!$b || $b['status'] !== 'confirmed') throw new InvalidArgumentException('Booking not found.');
    if (fdStayStatus($b) !== 'expected') throw new InvalidArgumentException('Only a guest who has not arrived can be a no-show.');
    if ($b['check_in'] > $today) throw new InvalidArgumentException('A booking cannot be a no-show before its check-in date.');
    getDB()->prepare("UPDATE bookings SET stay_status = 'no_show', updated_at = datetime('now') WHERE id = ?")->execute([$bookingId]);
}

/** Put a booking back to "expected" - for a mistaken check-in or no-show. Guest IDs are kept. */
function fdUndoStay(int $bookingId): void {
    getDB()->prepare("UPDATE bookings SET stay_status = 'expected', checked_in_at = NULL, checked_out_at = NULL, updated_at = datetime('now') WHERE id = ?")
        ->execute([$bookingId]);
}

// ── Form C ───────────────────────────────────────────────────

/** Foreign guests whose Form C is not yet marked submitted, oldest arrival first. */
function fcPending(): array {
    return getDB()->query("SELECT g.*, b.room_name, b.check_in, b.check_out, b.checked_in_at
        FROM guest_ids g JOIN bookings b ON b.id = g.booking_id
        WHERE g.is_foreign = 1 AND (g.form_c_submitted_at IS NULL OR g.form_c_submitted_at = '')
        ORDER BY b.checked_in_at, g.id")->fetchAll();
}

function fcDeadline(array $row): ?int {
    $in = kfsDbTimestamp($row['checked_in_at'] ?? '');
    return $in === null ? null : $in + FD_FORM_C_HOURS * 3600;
}

function fcMarkSubmitted(int $guestId, string $reference): void {
    $ref = trim($reference);
    if ($ref === '') throw new InvalidArgumentException('Enter the Form C application / reference number from the FRRO portal.');
    getDB()->prepare("UPDATE guest_ids SET form_c_submitted_at = datetime('now'), form_c_reference = ? WHERE id = ? AND is_foreign = 1")
        ->execute([mb_substr($ref, 0, 60), $guestId]);
}

// ── Housekeeping ─────────────────────────────────────────────

/** @return list<array{room_id:string, name:string, status:string, note:string, updated_at:?string}> */
function hkRooms(): array {
    $rows = [];
    foreach (getDB()->query('SELECT * FROM room_status')->fetchAll() as $r) $rows[$r['room_id']] = $r;
    $out = [];
    foreach (waPhysicalRooms() as $id) {
        $r = $rows[$id] ?? null;
        $out[] = ['room_id' => $id, 'name' => ROOM_IDS[$id] ?? $id, 'status' => $r['status'] ?? 'ready',
                  'note' => (string)($r['note'] ?? ''), 'updated_at' => $r['updated_at'] ?? null];
    }
    return $out;
}

function hkSetStatus(string $roomId, string $status, string $note = ''): void {
    if (!in_array($roomId, waPhysicalRooms(), true)) throw new InvalidArgumentException('Unknown room.');
    if (!isset(FD_ROOM_STATUSES[$status])) throw new InvalidArgumentException('Unknown room status.');
    getDB()->prepare("INSERT INTO room_status (room_id, status, note, updated_at) VALUES (?, ?, ?, datetime('now'))
        ON CONFLICT(room_id) DO UPDATE SET status = excluded.status, note = excluded.note, updated_at = excluded.updated_at")
        ->execute([$roomId, $status, mb_substr($note, 0, 200)]);
}
