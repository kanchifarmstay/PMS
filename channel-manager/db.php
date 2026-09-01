<?php
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA busy_timeout = 5000');
        _initSchema($db);
    }
    return $db;
}

function _initSchema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS bookings (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id          TEXT    NOT NULL,
            room_name        TEXT    NOT NULL,
            check_in         DATE    NOT NULL,
            check_out        DATE    NOT NULL,
            guest_name       TEXT    DEFAULT 'Blocked',
            guest_email      TEXT    DEFAULT '',
            guest_phone      TEXT    DEFAULT '',
            whatsapp_number  TEXT    DEFAULT '',
            source           TEXT    DEFAULT 'direct',
            booking_ref      TEXT    DEFAULT '',
            amount           REAL    DEFAULT 0,
            amount_paid      REAL    DEFAULT 0,
            payment_method   TEXT    DEFAULT 'cash',
            payment_status   TEXT    DEFAULT 'unpaid',
            status           TEXT    DEFAULT 'confirmed',
            uid              TEXT    UNIQUE,
            notes            TEXT    DEFAULT '',
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS external_calendars (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id      TEXT NOT NULL,
            platform     TEXT NOT NULL,
            ical_url     TEXT NOT NULL,
            last_synced  DATETIME,
            is_active    INTEGER DEFAULT 1,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS room_rates (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id      TEXT NOT NULL UNIQUE,
            base_price   REAL NOT NULL DEFAULT 0,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS platform_rates (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id      TEXT NOT NULL,
            platform     TEXT NOT NULL,
            rate         REAL NOT NULL DEFAULT 0,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(room_id, platform)
        );

        CREATE TABLE IF NOT EXISTS discount_rules (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id      TEXT NOT NULL DEFAULT '__all__',
            rule_type    TEXT NOT NULL,
            label        TEXT NOT NULL DEFAULT '',
            value        REAL NOT NULL DEFAULT 0,
            unit         TEXT NOT NULL DEFAULT 'pct',
            min_nights   INTEGER DEFAULT 1,
            days_ahead   INTEGER DEFAULT 0,
            enabled      INTEGER NOT NULL DEFAULT 1,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(room_id, rule_type)
        );

        CREATE TABLE IF NOT EXISTS demand_events (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            event_date   DATE NOT NULL,
            event_name   TEXT NOT NULL,
            event_type   TEXT NOT NULL,
            demand_level TEXT NOT NULL DEFAULT 'high',
            notes        TEXT DEFAULT '',
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS pricing_suggestions (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id         TEXT NOT NULL,
            date_from       DATE NOT NULL,
            date_to         DATE NOT NULL,
            current_price   REAL DEFAULT 0,
            suggested_price REAL DEFAULT 0,
            suggestion_pct  INTEGER DEFAULT 0,
            reason          TEXT NOT NULL,
            demand_level    TEXT DEFAULT 'high',
            status          TEXT DEFAULT 'pending',
            approved_price  REAL,
            approved_at     DATETIME,
            notes           TEXT DEFAULT '',
            generated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS price_history (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id      TEXT NOT NULL,
            date_from    DATE NOT NULL,
            date_to      DATE NOT NULL,
            old_price    REAL,
            new_price    REAL NOT NULL,
            reason       TEXT NOT NULL,
            changed_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS wa_conversations (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            phone            TEXT NOT NULL UNIQUE,
            guest_name       TEXT DEFAULT 'Unknown Guest',
            country_flag     TEXT DEFAULT '🇮🇳',
            status           TEXT DEFAULT 'new_inquiry',
            last_message     TEXT DEFAULT '',
            last_message_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            unread_count     INTEGER DEFAULT 0,
            booking_id       INTEGER,
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS settings (
            key        TEXT PRIMARY KEY,
            value      TEXT NOT NULL DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS external_blocks (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            calendar_id   INTEGER NOT NULL,
            room_id       TEXT NOT NULL,
            platform      TEXT NOT NULL,
            external_uid  TEXT NOT NULL,
            check_in      DATE NOT NULL,
            check_out     DATE NOT NULL,
            summary       TEXT DEFAULT '',
            status        TEXT DEFAULT 'CONFIRMED',
            raw_hash      TEXT DEFAULT '',
            first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(calendar_id, external_uid),
            FOREIGN KEY(calendar_id) REFERENCES external_calendars(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS booking_holds (
            token          TEXT PRIMARY KEY,
            room_id        TEXT NOT NULL,
            check_in       DATE NOT NULL,
            check_out      DATE NOT NULL,
            guest_name     TEXT NOT NULL,
            guest_email    TEXT NOT NULL,
            guest_phone    TEXT NOT NULL,
            adults         INTEGER NOT NULL DEFAULT 1,
            children       INTEGER NOT NULL DEFAULT 0,
            amount         REAL NOT NULL,
            status         TEXT NOT NULL DEFAULT 'pending',
            expires_at     DATETIME NOT NULL,
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS payment_orders (
            order_id          TEXT PRIMARY KEY,
            hold_token        TEXT NOT NULL UNIQUE,
            payment_id        TEXT DEFAULT '',
            amount_paise      INTEGER NOT NULL,
            currency          TEXT NOT NULL DEFAULT 'INR',
            status            TEXT NOT NULL DEFAULT 'created',
            signature_verified INTEGER NOT NULL DEFAULT 0,
            booking_id        INTEGER,
            last_error        TEXT DEFAULT '',
            created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(hold_token) REFERENCES booking_holds(token) ON DELETE RESTRICT,
            FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE RESTRICT
        );

        CREATE TABLE IF NOT EXISTS wa_messages (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id     INTEGER NOT NULL,
            sender              TEXT NOT NULL DEFAULT 'guest',
            body                TEXT NOT NULL,
            timestamp           DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_inquiry          INTEGER DEFAULT 0,
            extracted_check_in  TEXT DEFAULT '',
            extracted_check_out TEXT DEFAULT '',
            extracted_guests    INTEGER DEFAULT 0,
            extracted_room      TEXT DEFAULT '',
            meta_message_id     TEXT DEFAULT '',
            FOREIGN KEY(conversation_id) REFERENCES wa_conversations(id)
        );
    ");

    // Safe schema migrations for existing installs (SQLite ignores duplicates)
    $migrations = [
        "ALTER TABLE bookings ADD COLUMN whatsapp_number TEXT DEFAULT ''",
        "ALTER TABLE bookings ADD COLUMN amount_paid REAL DEFAULT 0",
        "ALTER TABLE bookings ADD COLUMN payment_method TEXT DEFAULT 'cash'",
        "ALTER TABLE bookings ADD COLUMN payment_status TEXT DEFAULT 'unpaid'",
        // is_sync_imported = 1 means the row was auto-imported by iCal sync.
        // Only these rows may be deleted by the stale-booking cleanup.
        // Manually logged bookings (even with source='airbnb') stay = 0.
        "ALTER TABLE bookings ADD COLUMN is_sync_imported INTEGER DEFAULT 0",
    ];
    foreach ($migrations as $sql) {
        try { $db->exec($sql); } catch (PDOException) { /* column already exists */ }
    }

    $calendarMigrations = [
        "ALTER TABLE external_calendars ADD COLUMN last_error TEXT DEFAULT ''",
        "ALTER TABLE external_calendars ADD COLUMN last_status TEXT DEFAULT 'never'",
    ];
    foreach ($calendarMigrations as $sql) {
        try { $db->exec($sql); } catch (PDOException) { /* column already exists */ }
    }

    $paymentMigrations = [
        "ALTER TABLE payment_orders ADD COLUMN booking_id INTEGER",
        "ALTER TABLE payment_orders ADD COLUMN last_error TEXT DEFAULT ''",
    ];
    foreach ($paymentMigrations as $sql) {
        try { $db->exec($sql); } catch (PDOException) { /* column already exists */ }
    }

    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_availability ON bookings(room_id, status, check_in, check_out)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_external_blocks_availability ON external_blocks(room_id, check_in, check_out)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_booking_holds_availability ON booking_holds(room_id, status, expires_at, check_in, check_out)");
}

// ---- Bookings ------------------------------------------------

function addBooking(array $data): int|false {
    $db  = getDB();

    // ── Duplicate guard ───────────────────────────────────────────
    // For iCal imports the UID column (UNIQUE) handles deduplication via
    // INSERT OR IGNORE.  For manual bookings there is no canonical UID, so
    // we check explicitly: same room + same check-in + same check-out and
    // still confirmed → treat as duplicate and return the existing ID.
    $isSyncImported = (int)($data['is_sync_imported'] ?? 0);
    if ($isSyncImported === 0) {
        $dup = $db->prepare("
            SELECT id FROM bookings
            WHERE room_id   = ?
              AND check_in  = ?
              AND check_out = ?
              AND status    = 'confirmed'
            LIMIT 1
        ");
        $dup->execute([$data['room_id'], $data['check_in'], $data['check_out']]);
        if ($row = $dup->fetch()) {
            return false; // already exists — silently skip
        }
    }

    $uid  = $data['uid'] ?? ('bk-' . bin2hex(random_bytes(8)) . '@kanchifarmstay.com');
    $stmt = $db->prepare("
        INSERT OR IGNORE INTO bookings
            (room_id, room_name, check_in, check_out,
             guest_name, guest_email, guest_phone, whatsapp_number,
             source, booking_ref, amount, amount_paid, payment_method, payment_status,
             status, uid, notes, is_sync_imported)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $data['room_id'],
        $data['room_name'],
        $data['check_in'],
        $data['check_out'],
        $data['guest_name']       ?? 'Guest',
        $data['guest_email']      ?? '',
        $data['guest_phone']      ?? '',
        $data['whatsapp_number']  ?? '',
        $data['source']           ?? 'direct',
        $data['booking_ref']      ?? '',
        $data['amount']           ?? 0,
        $data['amount_paid']      ?? 0,
        $data['payment_method']   ?? 'cash',
        $data['payment_status']   ?? 'unpaid',
        $data['status']           ?? 'confirmed',
        $uid,
        $data['notes']            ?? '',
        $isSyncImported,
    ]);
    return $stmt->rowCount() ? (int)$db->lastInsertId() : false;
}

function getBookingById(int $id): ?array {
    $stmt = getDB()->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getAllBookings(array $filters = []): array {
    $db     = getDB();
    $where  = ['1=1'];
    $params = [];
    if (!empty($filters['room_id'])) { $where[] = 'room_id = ?'; $params[] = $filters['room_id']; }
    if (!empty($filters['source']))  { $where[] = 'source = ?';  $params[] = $filters['source']; }
    $w    = implode(' AND ', $where);
    $stmt = $db->prepare("SELECT * FROM bookings WHERE $w ORDER BY check_in DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getUpcomingBookings(): array {
    $stmt = getDB()->prepare("
        SELECT * FROM bookings
        WHERE status = 'confirmed' AND check_out >= date('now')
        ORDER BY check_in
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getBlockedRanges(string $roomId): array {
    $stmt = getDB()->prepare("
        SELECT check_in, check_out FROM bookings
        WHERE room_id = ? AND status = 'confirmed' AND check_out >= date('now')
        ORDER BY check_in
    ");
    $stmt->execute([$roomId]);
    return $stmt->fetchAll();
}

function deleteBooking(int $id): void {
    $stmt = getDB()->prepare("DELETE FROM bookings WHERE id = ?");
    $stmt->execute([$id]);
}

function updateBooking(int $id, array $data): bool {
    $db = getDB();
    $stmt = $db->prepare("
        UPDATE bookings SET
            room_id = ?,
            room_name = ?,
            check_in = ?,
            check_out = ?,
            guest_name = ?,
            guest_email = ?,
            guest_phone = ?,
            whatsapp_number = ?,
            source = ?,
            booking_ref = ?,
            amount = ?,
            amount_paid = ?,
            payment_method = ?,
            payment_status = ?,
            status = ?,
            notes = ?,
            updated_at = datetime('now')
        WHERE id = ?
    ");
    return $stmt->execute([
        $data['room_id'],
        $data['room_name'],
        $data['check_in'],
        $data['check_out'],
        $data['guest_name'] ?? 'Guest',
        $data['guest_email'] ?? '',
        $data['guest_phone'] ?? '',
        $data['whatsapp_number'] ?? '',
        $data['source'] ?? 'direct',
        $data['booking_ref'] ?? '',
        $data['amount'] ?? 0,
        $data['amount_paid'] ?? 0,
        $data['payment_method'] ?? 'cash',
        $data['payment_status'] ?? 'unpaid',
        $data['status'] ?? 'confirmed',
        $data['notes'] ?? '',
        $id,
    ]);
}

function cancelBooking(int $id): void {
    $stmt = getDB()->prepare("UPDATE bookings SET status='cancelled', updated_at=datetime('now') WHERE id=?");
    $stmt->execute([$id]);
}

// ---- Revenue analytics ----------------------------------------

function getRevenueByPeriod(string $period = 'monthly'): array {
    $db = getDB();
    $commissions = OTA_COMMISSIONS;
    $rows = $db->query("
        SELECT source, amount, check_in
        FROM bookings
        WHERE status='confirmed' AND amount > 0
        ORDER BY check_in
    ")->fetchAll();

    $grouped = [];
    foreach ($rows as $r) {
        $key = match($period) {
            'daily'     => $r['check_in'],
            'weekly'    => 'W' . date('W', strtotime($r['check_in'])) . ' ' . date('Y', strtotime($r['check_in'])),
            'monthly'   => substr($r['check_in'], 0, 7),
            'quarterly' => 'Q' . ceil((int)date('n', strtotime($r['check_in'])) / 3) . ' ' . date('Y', strtotime($r['check_in'])),
            'yearly'    => date('Y', strtotime($r['check_in'])),
            default     => substr($r['check_in'], 0, 7),
        };
        $pct = ($commissions[strtolower($r['source'])] ?? 0) / 100;
        $gross = (float)$r['amount'];
        $commission = round($gross * $pct, 2);
        $net = $gross - $commission;
        if (!isset($grouped[$key])) $grouped[$key] = ['gross'=>0,'commission'=>0,'net'=>0,'count'=>0];
        $grouped[$key]['gross']      += $gross;
        $grouped[$key]['commission'] += $commission;
        $grouped[$key]['net']        += $net;
        $grouped[$key]['count']++;
    }
    ksort($grouped);
    return $grouped;
}

function getRevenueProjection(): array {
    $db = getDB();
    $rooms = ROOM_IDS;
    $roomCount = count($rooms);

    // Historical occupancy: past 90 days
    $stmt = $db->prepare("
        SELECT check_in, check_out FROM bookings
        WHERE status='confirmed'
          AND check_in >= date('now','-90 days')
          AND check_in < date('now')
    ");
    $stmt->execute();
    $past = $stmt->fetchAll();
    $totalHistNights = 0;
    foreach ($past as $b) {
        $totalHistNights += max(0, (int)ceil((strtotime($b['check_out'])-strtotime($b['check_in']))/86400));
    }
    $histOccupancy = $roomCount > 0 ? min(1, $totalHistNights / ($roomCount * 90)) : 0.3;

    // Confirmed future revenue
    $stmt2 = $db->prepare("SELECT SUM(amount) as rev FROM bookings WHERE status='confirmed' AND check_in >= date('now')");
    $stmt2->execute();
    $confirmedFutureRev = (float)($stmt2->fetch()['rev'] ?? 0);

    // Average nightly rate from past bookings
    $stmt3 = $db->prepare("
        SELECT AVG(amount / MAX(1, CAST((julianday(check_out)-julianday(check_in)) AS INTEGER))) as avg_rate
        FROM bookings
        WHERE status='confirmed' AND amount > 0 AND check_in >= date('now','-90 days')
    ");
    $stmt3->execute();
    $avgNightlyRate = (float)($stmt3->fetch()['avg_rate'] ?? 2500);

    // Remaining days this year
    $today = new DateTime('today');
    $yearEnd = new DateTime(date('Y') . '-12-31');
    $remainingDays = (int)$today->diff($yearEnd)->days;

    // Demand event premium: count gold/high demand days remaining in year
    $stmt4 = $db->prepare("
        SELECT COUNT(*) as cnt, demand_level FROM demand_events
        WHERE event_date >= date('now') AND event_date <= date('now', '+365 days')
        GROUP BY demand_level
    ");
    $stmt4->execute();
    $demandCounts = ['gold'=>0,'high'=>0,'medium'=>0];
    foreach ($stmt4->fetchAll() as $row) $demandCounts[$row['demand_level']] = (int)$row['cnt'];

    $premiumRevenue = ($demandCounts['gold'] * $avgNightlyRate * 0.40 * $roomCount)
                    + ($demandCounts['high'] * $avgNightlyRate * 0.25 * $roomCount);

    $projectedUnconfirmed = $remainingDays * $histOccupancy * $roomCount * $avgNightlyRate;
    $totalProjected = $confirmedFutureRev + $projectedUnconfirmed + $premiumRevenue;

    return [
        'hist_occupancy_pct'   => round($histOccupancy * 100, 1),
        'avg_nightly_rate'     => round($avgNightlyRate, 0),
        'confirmed_future_rev' => $confirmedFutureRev,
        'projected_unconfirmed'=> round($projectedUnconfirmed, 0),
        'demand_premium'       => round($premiumRevenue, 0),
        'total_projected'      => round($totalProjected, 0),
        'remaining_days'       => $remainingDays,
        'gold_dates'           => $demandCounts['gold'],
        'high_dates'           => $demandCounts['high'],
    ];
}

// ---- Room Rates -----------------------------------------------

function getRoomRates(): array {
    return getDB()->query("SELECT * FROM room_rates")->fetchAll();
}

function upsertRoomRate(string $roomId, float $price): void {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO room_rates (room_id, base_price, updated_at)
        VALUES (?, ?, datetime('now'))
        ON CONFLICT(room_id) DO UPDATE SET base_price=excluded.base_price, updated_at=datetime('now')
    ");
    $stmt->execute([$roomId, $price]);
}

function getRoomBasePrice(string $roomId): float {
    $stmt = getDB()->prepare("SELECT base_price FROM room_rates WHERE room_id=?");
    $stmt->execute([$roomId]);
    return (float)($stmt->fetchColumn() ?: 0);
}

// ---- Platform Rates ------------------------------------------

function getPlatformRates(): array {
    $rows = getDB()->query("SELECT * FROM platform_rates ORDER BY room_id, platform")->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['room_id']][$r['platform']] = (float)$r['rate'];
    return $out;
}

function upsertPlatformRate(string $roomId, string $platform, float $rate): void {
    getDB()->prepare("
        INSERT INTO platform_rates (room_id, platform, rate, updated_at)
        VALUES (?, ?, ?, datetime('now'))
        ON CONFLICT(room_id, platform) DO UPDATE SET rate=excluded.rate, updated_at=datetime('now')
    ")->execute([$roomId, $platform, $rate]);
}

// ---- Discount Rules ------------------------------------------

function getDiscountRules(): array {
    return getDB()->query("SELECT * FROM discount_rules ORDER BY room_id, rule_type")->fetchAll();
}

function upsertDiscountRule(string $roomId, string $ruleType, string $label, float $value, string $unit, int $minNights, int $daysAhead, int $enabled): void {
    getDB()->prepare("
        INSERT INTO discount_rules (room_id, rule_type, label, value, unit, min_nights, days_ahead, enabled, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ON CONFLICT(room_id, rule_type) DO UPDATE SET
            label=excluded.label, value=excluded.value, unit=excluded.unit,
            min_nights=excluded.min_nights, days_ahead=excluded.days_ahead,
            enabled=excluded.enabled, updated_at=datetime('now')
    ")->execute([$roomId, $ruleType, $label, $value, $unit, $minNights, $daysAhead, $enabled]);
}

// ---- Demand Events -------------------------------------------

function getDemandEvents(?string $from = null, ?string $to = null): array {
    $db = getDB();
    $from = $from ?? date('Y-m-d');
    $to   = $to   ?? date('Y-m-d', strtotime('+365 days'));
    $stmt = $db->prepare("SELECT * FROM demand_events WHERE event_date BETWEEN ? AND ? ORDER BY event_date");
    $stmt->execute([$from, $to]);
    return $stmt->fetchAll();
}

function getDemandEventsByDate(): array {
    $events = getDemandEvents();
    $byDate = [];
    foreach ($events as $e) $byDate[$e['event_date']][] = $e;
    return $byDate;
}

function upsertDemandEvent(string $date, string $name, string $type, string $level, string $notes = ''): void {
    $db = getDB();
    $existing = $db->prepare("SELECT id FROM demand_events WHERE event_date=? AND event_name=?");
    $existing->execute([$date, $name]);
    if ($existing->fetchColumn()) return;
    $stmt = $db->prepare("INSERT INTO demand_events (event_date, event_name, event_type, demand_level, notes) VALUES (?,?,?,?,?)");
    $stmt->execute([$date, $name, $type, $level, $notes]);
}

function clearAndReSeedDemandEvents(): void {
    getDB()->exec("DELETE FROM demand_events WHERE event_type IN ('muhuratham','festival','holiday','bridge_holiday')");
}

// ---- Pricing Suggestions ------------------------------------

function getPricingSuggestions(string $status = 'pending'): array {
    $stmt = getDB()->prepare("SELECT * FROM pricing_suggestions WHERE status=? ORDER BY date_from");
    $stmt->execute([$status]);
    return $stmt->fetchAll();
}

function getAllPricingSuggestions(): array {
    return getDB()->query("SELECT * FROM pricing_suggestions ORDER BY generated_at DESC, date_from")->fetchAll();
}

function addPricingSuggestion(array $d): void {
    $db = getDB();
    // Avoid duplicates for same room+date_from
    $chk = $db->prepare("SELECT id FROM pricing_suggestions WHERE room_id=? AND date_from=? AND status='pending'");
    $chk->execute([$d['room_id'], $d['date_from']]);
    if ($chk->fetchColumn()) return;
    $stmt = $db->prepare("
        INSERT INTO pricing_suggestions
            (room_id, date_from, date_to, current_price, suggested_price, suggestion_pct, reason, demand_level)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $d['room_id'], $d['date_from'], $d['date_to'],
        $d['current_price'], $d['suggested_price'], $d['suggestion_pct'],
        $d['reason'], $d['demand_level'],
    ]);
}

function approveSuggestion(int $id, float $approvedPrice, string $notes = ''): void {
    $db = getDB();
    $row = $db->prepare("SELECT * FROM pricing_suggestions WHERE id=?");
    $row->execute([$id]);
    $s = $row->fetch();
    if (!$s) return;

    $db->prepare("
        UPDATE pricing_suggestions
        SET status='approved', approved_price=?, approved_at=datetime('now'), notes=?
        WHERE id=?
    ")->execute([$approvedPrice, $notes, $id]);

    // Log to price history
    $db->prepare("
        INSERT INTO price_history (room_id, date_from, date_to, old_price, new_price, reason)
        VALUES (?,?,?,?,?,?)
    ")->execute([
        $s['room_id'], $s['date_from'], $s['date_to'],
        $s['current_price'], $approvedPrice,
        'Approved: ' . $s['reason']
    ]);
}

function dismissSuggestion(int $id, string $notes = ''): void {
    getDB()->prepare("UPDATE pricing_suggestions SET status='dismissed', notes=? WHERE id=?")->execute([$notes, $id]);
}

function getPriceHistory(): array {
    return getDB()->query("SELECT * FROM price_history ORDER BY changed_at DESC LIMIT 100")->fetchAll();
}

// ---- External Calendars -------------------------------------

function getExternalCalendars(): array {
    return getDB()->query("SELECT * FROM external_calendars ORDER BY room_id, platform")->fetchAll();
}

function addExternalCalendar(string $roomId, string $platform, string $url): int {
    $db = getDB();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $find = $db->prepare('SELECT id FROM external_calendars WHERE room_id=? AND platform=? ORDER BY id');
        $find->execute([$roomId, $platform]);
        $ids = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN));
        if ($ids !== []) {
            $id = array_shift($ids);
            $db->prepare("UPDATE external_calendars SET ical_url=?, is_active=1, last_synced=NULL, last_status='never', last_error='' WHERE id=?")
                ->execute([$url, $id]);
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("DELETE FROM external_calendars WHERE id IN ({$placeholders})")->execute($ids);
            }
        } else {
            $stmt = $db->prepare("INSERT INTO external_calendars (room_id, platform, ical_url) VALUES (?,?,?)");
            $stmt->execute([$roomId, $platform, $url]);
            $id = (int)$db->lastInsertId();
        }
        $db->exec('COMMIT');
        return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
}

function deleteExternalCalendar(int $id): void {
    $stmt = getDB()->prepare("DELETE FROM external_calendars WHERE id = ?");
    $stmt->execute([$id]);
}

function touchLastSynced(int $calendarId): void {
    $stmt = getDB()->prepare("UPDATE external_calendars SET last_synced = datetime('now') WHERE id = ?");
    $stmt->execute([$calendarId]);
}

// ── WhatsApp Conversations ─────────────────────────────────────

function getWAConversations(): array {
    return getDB()->query("
        SELECT * FROM wa_conversations ORDER BY last_message_time DESC
    ")->fetchAll();
}

function getWAConversation(int $id): ?array {
    $stmt = getDB()->prepare("SELECT * FROM wa_conversations WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getOrCreateConversation(string $phone, string $guestName = 'Unknown Guest'): array {
    $db   = getDB();
    $phone = preg_replace('/\D/', '', $phone);
    $stmt = $db->prepare("SELECT * FROM wa_conversations WHERE phone = ?");
    $stmt->execute([$phone]);
    $row  = $stmt->fetch();
    if ($row) return $row;

    $ins = $db->prepare("INSERT INTO wa_conversations (phone, guest_name) VALUES (?,?)");
    $ins->execute([$phone, $guestName]);
    $id = (int)$db->lastInsertId();
    return getWAConversation($id);
}

function updateConversation(int $id, array $data): void {
    $db   = getDB();
    $sets = [];
    $vals = [];
    foreach (['guest_name','status','last_message','last_message_time','unread_count','booking_id'] as $f) {
        if (array_key_exists($f, $data)) { $sets[] = "$f = ?"; $vals[] = $data[$f]; }
    }
    if (!$sets) return;
    $vals[] = $id;
    $db->prepare("UPDATE wa_conversations SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
}

function markConversationRead(int $id): void {
    getDB()->prepare("UPDATE wa_conversations SET unread_count = 0 WHERE id = ?")->execute([$id]);
}

function getWAUnreadTotal(): int {
    return (int)(getDB()->query("SELECT SUM(unread_count) FROM wa_conversations")->fetchColumn() ?? 0);
}

// ── WhatsApp Messages ─────────────────────────────────────────

function getWAMessages(int $conversationId): array {
    $stmt = getDB()->prepare("
        SELECT * FROM wa_messages WHERE conversation_id = ? ORDER BY timestamp ASC
    ");
    $stmt->execute([$conversationId]);
    return $stmt->fetchAll();
}

function addWAMessage(int $conversationId, string $sender, string $body, array $opts = []): int {
    $db   = getDB();
    $stmt = $db->prepare("
        INSERT INTO wa_messages
            (conversation_id, sender, body, is_inquiry,
             extracted_check_in, extracted_check_out, extracted_guests, extracted_room, meta_message_id)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $conversationId,
        $sender,
        $body,
        $opts['is_inquiry']          ?? 0,
        $opts['extracted_check_in']  ?? '',
        $opts['extracted_check_out'] ?? '',
        $opts['extracted_guests']    ?? 0,
        $opts['extracted_room']      ?? '',
        $opts['meta_message_id']     ?? '',
    ]);
    $msgId = (int)$db->lastInsertId();

    // Update conversation last message
    $db->prepare("
        UPDATE wa_conversations
        SET last_message = ?, last_message_time = datetime('now'),
            unread_count = unread_count + ?
        WHERE id = ?
    ")->execute([
        mb_substr($body, 0, 120),
        $sender === 'guest' ? 1 : 0,
        $conversationId,
    ]);

    return $msgId;
}

// ── Inquiry parser (server-side mirror of JS parser) ──────────

function parseWAInquiry(string $text): array {
    $result = [];
    $year   = (int)date('Y');
    $monthMap = [
        'jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04','may'=>'05','jun'=>'06',
        'jul'=>'07','aug'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dec'=>'12',
    ];
    $dates = [];

    // DD/MM or DD/MM/YYYY
    preg_match_all('/(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?/', $text, $dmy, PREG_SET_ORDER);
    foreach ($dmy as $m) {
        $y = isset($m[3]) && $m[3] ? (strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3]) : $year;
        $d = DateTime::createFromFormat('Y-m-d', "$y-{$m[2]}-{$m[1]}");
        if ($d) $dates[] = $d;
    }

    // "21 april" or "april 21"
    preg_match_all('/(\d{1,2})(?:st|nd|rd|th)?\s+(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i', $text, $named, PREG_SET_ORDER);
    foreach ($named as $m) {
        $mon = $monthMap[strtolower($m[2])] ?? null;
        if ($mon) {
            $d = DateTime::createFromFormat('Y-m-d', "$year-$mon-" . str_pad($m[1], 2, '0', STR_PAD_LEFT));
            if ($d) $dates[] = $d;
        }
    }

    if (count($dates) >= 2) {
        usort($dates, fn($a, $b) => $a <=> $b);
        $result['check_in']  = $dates[0]->format('Y-m-d');
        $result['check_out'] = $dates[1]->format('Y-m-d');
    } elseif (count($dates) === 1) {
        $result['check_in'] = $dates[0]->format('Y-m-d');
    }

    if (preg_match('/(\d+)\s*(?:people|person|guests?|adults?|pax|of us)/i', $text, $gm) ||
        preg_match('/family\s+of\s+(\d+)/i', $text, $gm)) {
        $result['guests'] = (int)$gm[1];
    }

    if (preg_match('/cottage/i', $text))   $result['room'] = 'Cottage';
    if (preg_match('/villa/i',   $text))   $result['room'] = 'Villa';
    if (preg_match('/suite/i',   $text))   $result['room'] = 'Suite';
    if (preg_match('/deluxe/i',  $text))   $result['room'] = 'Deluxe Room';

    return $result;
}

// ── Settings ──────────────────────────────────────────────────

function getSetting(string $key, string $default = ''): string {
    $stmt = getDB()->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    return (string)($stmt->fetchColumn() ?: $default);
}

function setSetting(string $key, string $value): void {
    getDB()->prepare("
        INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')
    ")->execute([$key, $value]);
}

function isBookingInquiry(string $text): bool {
    $keywords = ['book','reserv','available','availab','check.?in','check.?out','stay','nights?','weekend','how much','rate','price','cost'];
    $l = strtolower($text);
    foreach ($keywords as $kw) {
        if (preg_match('/' . $kw . '/', $l)) return true;
    }
    return (bool)preg_match('/\d{1,2}[\/\-]\d{1,2}|\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i', $text);
}
