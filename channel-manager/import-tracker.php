<?php
/**
 * One-time import of the Google Sheet tracker into the PMS. Command line only.
 *
 *   php import-tracker.php bookings.csv expenses.csv            dry run: prints the plan, writes nothing
 *   php import-tracker.php bookings.csv expenses.csv --apply    does it
 *
 * The CSVs come from tools/tracker_to_csv.py. The dry run executes exactly the
 * same code inside a transaction and rolls it back, so the plan it prints is
 * what --apply will do. Safe to re-run: every row is keyed by its sheet
 * position, and anything already imported is skipped.
 *
 * Per booking row (one per room when a row covered several rooms):
 *   status=review          skipped until the CSV is fixed by hand
 *   check-out after today  skipped: future stays are entered in the PMS, where
 *                          availability and the OTA calendars are checked
 *   overlaps a booking already in the PMS for that room
 *                          -> MATCH (same guest name): only blanks are filled -
 *                             a zero amount, the food bill, money received when
 *                             none is recorded
 *                          -> CONFLICT (another name): nothing is written; both
 *                             names are printed so a person decides
 *   status=skip            ignored (set by hand once a row is dealt with)
 *   otherwise              -> NEW booking, marked as already confirmed and
 *                             checked out so nothing is ever sent for it (sheet
 *                             rows carry no phone number in any case)
 *
 * Money received: the sheet's own figure where it has one (Sept, Oct). Where
 * it has none, a past stay is recorded as paid in full with a note saying it
 * was assumed - leaving it unpaid would show months of finished stays as money
 * still owed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/accounts-service.php';
require_once __DIR__ . '/charges-service.php';

const TRACKER_ASSUMED_NOTE = 'Assumed paid in full - the Google Sheet recorded no payment for this stay';

function trackerReadCsv(string $path): array {
    if (!is_file($path)) throw new RuntimeException("Not found: {$path}");
    $fh = fopen($path, 'r');
    $head = fgetcsv($fh, null, ',', '"', '');
    $rows = [];
    while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
        if ($r === [null]) continue;
        $rows[] = array_combine($head, array_pad($r, count($head), ''));
    }
    fclose($fh);
    return $rows;
}

/** Split paise across n parts; the remainder goes on the first so the total is exact. */
function trackerSplit(int $paise, int $n): array {
    $each = intdiv($paise, $n);
    $parts = array_fill(0, $n, $each);
    $parts[0] += $paise - $each * $n;
    return $parts;
}

/** A booking already in the PMS that occupies this room on any of these nights. */
function trackerFindExisting(string $roomId, string $checkIn, string $checkOut): ?array {
    $q = getDB()->prepare("SELECT * FROM bookings WHERE room_id = ? AND source <> 'blocked' AND is_sync_imported = 0
        AND uid NOT LIKE 'sheet-%' AND check_in < ? AND check_out > ? ORDER BY (status = 'confirmed') DESC, id LIMIT 1");
    $q->execute([$roomId, $checkOut, $checkIn]);
    return $q->fetch() ?: null;
}

/**
 * Same guest? The sheet often has a short name ("gopi") where the OTA has the
 * full one ("Gopinath R"), so any word of four or more letters in common counts.
 */
function trackerSameGuest(string $sheet, string $pms): bool {
    $words = fn(string $s) => array_filter(preg_split('/[^a-z]+/', strtolower($s)) ?: [], fn($w) => strlen($w) >= 3);
    $a = $words($sheet);
    $b = $words($pms);
    $flatA = implode('', $a);
    $flatB = implode('', $b);
    foreach ($a as $w) {
        if (strlen($w) >= 4 && str_contains($flatB, substr($w, 0, 4))) return true;
    }
    foreach ($b as $w) {
        if (strlen($w) >= 4 && str_contains($flatA, substr($w, 0, 4))) return true;
    }
    return false;
}

function trackerUid(string $key, int $i): string {
    return 'sheet-' . strtolower($key) . '-' . $i . '@kanchifarmstay.com';
}

function trackerPaidMethod(string $method): string {
    return $method === 'ota' ? 'other' : $method;   // food is paid at the farm, never through the OTA
}

/** @return list<array{action: string, booking_id: ?int, detail: string}>, one per room in the row */
function trackerImportBooking(array $row, string $today): array {
    $key = $row['key'];
    $rooms = array_values(array_filter(explode(';', (string)$row['rooms'])));
    if ($row['status'] === 'review') return [['action' => 'review', 'booking_id' => null, 'detail' => $row['issues']]];
    if ($row['status'] === 'skip') return [['action' => 'skip', 'booking_id' => null, 'detail' => 'marked skip in the CSV']];
    if (!in_array($row['status'], ['confirmed', 'cancelled'], true)) return [['action' => 'review', 'booking_id' => null, 'detail' => "status '{$row['status']}'"]];
    if (!$rooms || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['check_in']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['check_out']) || $row['check_out'] <= $row['check_in']) {
        return [['action' => 'review', 'booking_id' => null, 'detail' => 'room or dates missing']];
    }
    if ($row['check_out'] > $today) return [['action' => 'future', 'booking_id' => null, 'detail' => 'check-out ' . $row['check_out'] . ': enter it in the PMS']];
    foreach ($rooms as $room) {
        if (!isset(ROOM_IDS[$room])) return [['action' => 'review', 'booking_id' => null, 'detail' => "unknown room id {$room}"]];
    }
    $n = count($rooms);
    $cancelled = $row['status'] === 'cancelled';
    $amounts = trackerSplit(rupeesToPaise($row['amount'] ?: 0), $n);
    $receivedKnown = trim((string)$row['received']) !== '';
    $received = trackerSplit($receivedKnown ? rupeesToPaise($row['received']) : 0, $n);
    $food = rupeesToPaise($row['food'] ?: 0);
    $paidOn = min($row['check_out'], $today);
    $split = $n > 1 ? 'Split from one sheet row covering ' . implode(' + ', array_map(fn($r) => ROOM_IDS[$r], $rooms))
        . ' (Rs. ' . waMoneyFromPaise(array_sum($amounts)) . ' in total).' : '';
    $out = [];

    foreach ($rooms as $i => $room) {
        $ref = 'sheet:' . $key . '-' . $i;
        $uid = trackerUid($key, $i);
        $q = getDB()->prepare('SELECT id FROM bookings WHERE uid = ?');
        $q->execute([$uid]);
        if ($q->fetchColumn()) { $out[] = ['action' => 'done', 'booking_id' => null, 'detail' => 'already imported']; continue; }

        $existing = trackerFindExisting($room, $row['check_in'], $row['check_out']);
        if ($existing && !trackerSameGuest($row['guest_name'], (string)$existing['guest_name'])) {
            $out[] = ['action' => 'conflict', 'booking_id' => (int)$existing['id'], 'detail' => sprintf('%s %s→%s is booking #%d for "%s" in the PMS, sheet says "%s": not touched',
                ROOM_IDS[$room], $row['check_in'], $row['check_out'], (int)$existing['id'], $existing['guest_name'], $row['guest_name'])];
            continue;
        }
        if ($existing) {
            $id = (int)$existing['id'];
            $chk = getDB()->prepare('SELECT 1 FROM payments WHERE reference = ? UNION SELECT 1 FROM booking_charges WHERE import_ref = ?');
            $chk->execute([$ref, $ref]);
            if ($chk->fetchColumn()) { $out[] = ['action' => 'done', 'booking_id' => $id, 'detail' => 'already filled']; continue; }
            $did = [];
            acctBackfillOpeningBalances($id);
            if ((float)$existing['amount'] <= 0 && $amounts[$i] > 0 && !$cancelled) {
                getDB()->prepare("UPDATE bookings SET amount = ?, updated_at = datetime('now') WHERE id = ?")->execute([$amounts[$i] / 100, $id]);
                $did[] = 'amount Rs. ' . waMoneyFromPaise($amounts[$i]);
            }
            if ($i === 0 && $food > 0 && chargeTotals($id)['total'] === 0) {
                chargeAdd($id, 'food', 'Food bill from Google Sheet', $food / 100, $paidOn, trackerPaidMethod($row['method']), $ref);
                $did[] = 'food Rs. ' . waMoneyFromPaise($food);
            }
            $owed = $receivedKnown ? $received[$i] : (int)round((float)getBookingById($id)['amount'] * 100);
            if (!$cancelled && $existing['status'] === 'confirmed' && acctNetPaise($id) === 0 && $owed > 0) {
                acctInsert($id, 'payment', $owed, $row['method'], $ref, $paidOn, $receivedKnown ? 'From Google Sheet' : TRACKER_ASSUMED_NOTE);
                $did[] = 'received Rs. ' . waMoneyFromPaise($owed) . ($receivedKnown ? '' : ' (assumed)');
            }
            acctApplyToBooking($id);
            $out[] = ['action' => 'match', 'booking_id' => $id, 'detail' => '#' . $id . ': ' . ($did ? 'filled ' . implode(', ', $did) : 'nothing to fill')];
            continue;
        }

        $notes = trim('Imported from the Google Sheet (' . str_replace('-r', ' tab, row ', $key) . ').'
            . ($row['entered_by'] !== '' ? ' Entered by ' . $row['entered_by'] . '.' : '')
            . ($row['notes'] !== '' ? ' ' . ucfirst($row['notes']) . '.' : '') . ' ' . $split);
        $id = addBooking([
            'room_id' => $room, 'room_name' => ROOM_IDS[$room], 'check_in' => $row['check_in'], 'check_out' => $row['check_out'],
            'guest_name' => $row['guest_name'], 'source' => $row['source'] ?: 'manual', 'amount' => $amounts[$i] / 100,
            'payment_method' => acctMethod($row['method']), 'status' => $cancelled ? 'cancelled' : 'confirmed',
            'uid' => $uid, 'notes' => $notes,
        ]);
        if ($id === false) {
            $q = getDB()->prepare("SELECT uid, guest_name FROM bookings WHERE room_id = ? AND check_in = ? AND check_out = ? AND status = 'confirmed' LIMIT 1");
            $q->execute([$room, $row['check_in'], $row['check_out']]);
            $other = $q->fetch() ?: ['uid' => '', 'guest_name' => ''];
            $from = preg_match('/^sheet-(.+)-r(\d+)-\d+@/', (string)$other['uid'], $m) ? 'sheet row ' . ucfirst($m[1]) . '-r' . $m[2] : 'the PMS';
            $out[] = ['action' => 'review', 'booking_id' => null, 'detail' => sprintf('%s %s is already taken by "%s" (%s): correct the room in the CSV',
                ROOM_IDS[$room], $row['check_in'], $other['guest_name'], $from)];
            continue;
        }
        // Claim both one-time WhatsApp slots so nothing can ever go out for a finished stay.
        getDB()->prepare("UPDATE bookings SET guest_confirm_sent_at = 'sheet-import', admin_alert_sent_at = 'sheet-import',
            stay_status = ? WHERE id = ?")->execute([$cancelled ? 'expected' : 'checked_out', $id]);
        $did = [];
        if (!$cancelled) {
            $paid = $receivedKnown ? $received[$i] : $amounts[$i];
            if ($paid > 0) {
                acctInsert($id, 'payment', $paid, $row['method'], $ref, $paidOn, $receivedKnown ? 'From Google Sheet' : TRACKER_ASSUMED_NOTE);
                $did[] = 'received Rs. ' . waMoneyFromPaise($paid) . ($receivedKnown ? '' : ' (assumed)');
            }
            if ($i === 0 && $food > 0) {
                chargeAdd($id, 'food', 'Food bill from Google Sheet', $food / 100, $paidOn, trackerPaidMethod($row['method']), $ref);
                $did[] = 'food Rs. ' . waMoneyFromPaise($food);
            }
            acctApplyToBooking($id);
        }
        $out[] = ['action' => $cancelled ? 'new-cancelled' : 'new', 'booking_id' => $id,
            'detail' => ROOM_IDS[$room] . ' ' . $row['check_in'] . ' Rs. ' . waMoneyFromPaise($amounts[$i]) . ($did ? ', ' . implode(', ', $did) : '')];
    }
    return $out;
}

function trackerImportExpense(array $row): array {
    if ($row['status'] !== 'ok') return ['action' => 'review', 'detail' => $row['issues'] ?: "status '{$row['status']}'"];
    $ref = 'sheet:' . $row['key'];
    $q = getDB()->prepare('SELECT id FROM farm_expenses WHERE import_ref = ?');
    $q->execute([$ref]);
    if ($q->fetchColumn()) return ['action' => 'done', 'detail' => 'already imported'];
    expenseAdd($row['spent_on'], $row['category'], $row['description'], $row['amount'], 'other', 'From Google Sheet', $ref);
    return ['action' => 'new', 'detail' => $row['spent_on'] . ' ' . $row['description'] . ' Rs. ' . $row['amount']];
}

/** The whole import in one transaction; rolled back unless $apply. */
function trackerImport(array $bookings, array $expenses, bool $apply, string $today): array {
    $db = getDB();
    $owned = kfsBeginTransaction($db);
    if (!$owned) throw new RuntimeException('Already inside a transaction.');
    try {
        $result = ['bookings' => [], 'expenses' => [], 'summary' => []];
        foreach ($bookings as $row) $result['bookings'][$row['key']] = trackerImportBooking($row, $today);
        foreach ($expenses as $row) $result['expenses'][$row['key']] = trackerImportExpense($row);
        $years = array_values(array_unique(array_filter(array_map(fn($r) => (int)substr((string)$r['check_in'], 0, 4), $bookings))));
        sort($years);
        foreach ($years ?: [(int)substr($today, 0, 4)] as $y) $result['summary'][$y] = monthlySummary($y);
    } catch (Throwable $e) {
        kfsRollbackTransaction($db, $owned);
        throw $e;
    }
    $apply ? kfsCommitTransaction($db, $owned) : kfsRollbackTransaction($db, $owned);
    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $args = array_slice($argv, 1);
    $apply = in_array('--apply', $args, true);
    $files = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
    if (count($files) < 1) { fwrite(STDERR, "usage: php import-tracker.php bookings.csv [expenses.csv] [--apply]\n"); exit(2); }
    $today = date('Y-m-d');
    $r = trackerImport(trackerReadCsv($files[0]), isset($files[1]) ? trackerReadCsv($files[1]) : [], $apply, $today);

    $count = [];
    foreach ($r['bookings'] as $key => $results) {
        foreach ($results as $x) {
            $count[$x['action']] = ($count[$x['action']] ?? 0) + 1;
            printf("%-12s %-13s %s\n", $key, strtoupper($x['action']), $x['detail']);
        }
    }
    foreach ($r['expenses'] as $key => $x) {
        $count['expense ' . $x['action']] = ($count['expense ' . $x['action']] ?? 0) + 1;
        printf("%-12s %-13s %s\n", $key, 'EXP ' . strtoupper($x['action']), $x['detail']);
    }
    foreach ($r['summary'] as $y => $s) {
        echo "\nPMS monthly summary for {$y} after this import:\n";
        printf("  %-8s %8s %12s %10s %10s %10s %12s\n", 'month', 'bookings', 'room', 'pending', 'food', 'expenses', 'net');
        foreach ($s['months'] as $ym => $m) {
            if ($m['bookings'] === 0 && $m['expenses'] === 0) continue;
            printf("  %-8s %8d %12s %10s %10s %10s %12s\n", $ym, $m['bookings'], waMoneyFromPaise($m['room']), waMoneyFromPaise($m['pending']),
                waMoneyFromPaise($m['food']), waMoneyFromPaise($m['expenses']), waMoneyFromPaise($m['net']));
        }
    }
    echo "\n" . implode(', ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($count), $count)) . "\n";
    echo $apply ? "APPLIED.\n" : "Dry run - nothing was written. Re-run with --apply to import.\n";
}
