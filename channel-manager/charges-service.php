<?php
/**
 * Food & extra charges on a stay, running expenses, and the monthly summary
 * that replaces the "Master Monthly Summary" Google Sheet.
 *
 *   booking_charges  food / extras a guest ran up. bookings.amount stays the
 *                    room tariff, so the payment ledger is untouched. method
 *                    '' means not collected yet (it goes on the bill).
 *   expenses         money spent running the place, by category.
 *
 * Both are voided with a reason, never deleted. Money is integer paise.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/accounts-service.php';

const CHARGE_KINDS = ['food' => 'Food & beverages', 'extra' => 'Extra guest / activity', 'other' => 'Other'];

/** Edit this list to add a category; existing rows keep whatever key they were saved with. */
const EXPENSE_CATEGORIES = [
    'groceries'    => 'Groceries & vegetables',
    'meat_fish'    => 'Chicken, meat & fish',
    'gas_fuel'     => 'Gas & fuel',
    'staff'        => 'Staff wages',
    'electricity'  => 'Electricity',
    'water'        => 'Water',
    'maintenance'  => 'Repairs & maintenance',
    'housekeeping' => 'Housekeeping & laundry',
    'commission'   => 'OTA commission',
    'marketing'    => 'Marketing',
    'internet'     => 'Internet & phone',
    'transport'    => 'Transport',
    'other'        => 'Other',
];

function _chargeValidDate(string $d): void {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || strtotime($d) === false) throw new InvalidArgumentException('Enter the date.');
    if ($d > date('Y-m-d')) throw new InvalidArgumentException('The date cannot be in the future.');
}

function _chargeUser(): string {
    return function_exists('currentUser') ? (string)(currentUser()['username'] ?? '') : '';
}

// ── Charges on a booking ─────────────────────────────────────

function chargeAdd(int $bookingId, string $kind, string $description, mixed $amountRupees, string $chargedOn, string $method, string $importRef = ''): int {
    if (!getBookingById($bookingId)) throw new InvalidArgumentException('Booking not found.');
    if (!isset(CHARGE_KINDS[$kind])) throw new InvalidArgumentException('Choose what the charge is for.');
    $paise = rupeesToPaise($amountRupees);
    if ($paise <= 0) throw new InvalidArgumentException('Enter an amount greater than zero.');
    _chargeValidDate($chargedOn);
    $method = trim($method) === '' ? '' : acctMethod($method);
    getDB()->prepare('INSERT INTO booking_charges (booking_id, kind, description, amount_paise, charged_on, method, import_ref, created_by) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$bookingId, $kind, mb_substr(trim($description), 0, 200), $paise, $chargedOn, $method, $importRef, _chargeUser()]);
    return (int)getDB()->lastInsertId();
}

/** Mark an uncollected charge as paid. */
function chargeCollect(int $chargeId, string $method): int {
    $q = getDB()->prepare('SELECT booking_id, voided, method FROM booking_charges WHERE id = ?');
    $q->execute([$chargeId]);
    $row = $q->fetch();
    if (!$row || (int)$row['voided'] === 1) throw new InvalidArgumentException('Charge not found.');
    if ($row['method'] !== '') throw new InvalidArgumentException('Already marked as paid.');
    getDB()->prepare('UPDATE booking_charges SET method = ? WHERE id = ?')->execute([acctMethod($method), $chargeId]);
    return (int)$row['booking_id'];
}

function chargeVoid(int $chargeId, string $reason): int {
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException('Say why this charge is being voided.');
    $q = getDB()->prepare('SELECT booking_id, voided FROM booking_charges WHERE id = ?');
    $q->execute([$chargeId]);
    $row = $q->fetch();
    if (!$row) throw new InvalidArgumentException('Charge not found.');
    if ((int)$row['voided'] === 1) throw new InvalidArgumentException('Already voided.');
    getDB()->prepare('UPDATE booking_charges SET voided = 1, void_reason = ? WHERE id = ?')->execute([mb_substr($reason, 0, 200), $chargeId]);
    return (int)$row['booking_id'];
}

function chargesForBooking(int $bookingId): array {
    $q = getDB()->prepare('SELECT * FROM booking_charges WHERE booking_id = ? ORDER BY charged_on, id');
    $q->execute([$bookingId]);
    return $q->fetchAll();
}

/** ['total' => paise, 'unpaid' => paise] over live charges. */
function chargeTotals(int $bookingId): array {
    $q = getDB()->prepare("SELECT COALESCE(SUM(amount_paise), 0), COALESCE(SUM(CASE WHEN method = '' THEN amount_paise ELSE 0 END), 0)
        FROM booking_charges WHERE booking_id = ? AND voided = 0");
    $q->execute([$bookingId]);
    [$total, $unpaid] = $q->fetch(PDO::FETCH_NUM);
    return ['total' => (int)$total, 'unpaid' => (int)$unpaid];
}

// ── Expenses ─────────────────────────────────────────────────

function expenseAdd(string $spentOn, string $category, string $description, mixed $amountRupees, string $method, string $note = '', string $importRef = ''): int {
    _chargeValidDate($spentOn);
    if (!isset(EXPENSE_CATEGORIES[$category])) throw new InvalidArgumentException('Choose a category.');
    $paise = rupeesToPaise($amountRupees);
    if ($paise <= 0) throw new InvalidArgumentException('Enter an amount greater than zero.');
    if (trim($method) === '') throw new InvalidArgumentException('Choose how it was paid.');
    getDB()->prepare('INSERT INTO farm_expenses (spent_on, category, description, amount_paise, method, note, import_ref, created_by) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$spentOn, $category, mb_substr(trim($description), 0, 200), $paise, acctMethod($method), mb_substr(trim($note), 0, 300), $importRef, _chargeUser()]);
    return (int)getDB()->lastInsertId();
}

function expenseVoid(int $id, string $reason): void {
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException('Say why this expense is being voided.');
    $q = getDB()->prepare('UPDATE farm_expenses SET voided = 1, void_reason = ? WHERE id = ? AND voided = 0');
    $q->execute([mb_substr($reason, 0, 200), $id]);
    if ($q->rowCount() !== 1) throw new InvalidArgumentException('Expense not found, or already voided.');
}

/** Expenses between two dates. Voided ones are listed (struck out) but never totalled. */
function expensesBetween(string $from, string $to): array {
    $q = getDB()->prepare('SELECT * FROM farm_expenses WHERE spent_on BETWEEN ? AND ? ORDER BY spent_on DESC, id DESC');
    $q->execute([$from, $to]);
    $rows = $q->fetchAll();
    $byCat = [];
    $total = 0;
    foreach ($rows as $r) {
        if ((int)$r['voided'] === 1) continue;
        $total += (int)$r['amount_paise'];
        $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + (int)$r['amount_paise'];
    }
    arsort($byCat);
    return ['rows' => $rows, 'by_category' => $byCat, 'total' => $total];
}

// ── Monthly summary ──────────────────────────────────────────

/**
 * The year by month. Room revenue and pending are by CHECK-IN month (as the
 * sheet did); food by the date it was charged; expenses by the date spent.
 * Cancelled bookings and blocks are left out. Amounts in paise.
 */
function monthlySummary(int $year): array {
    $db = getDB();
    $blank = ['bookings' => 0, 'room' => 0, 'received' => 0, 'pending' => 0, 'food' => 0, 'food_unpaid' => 0, 'expenses' => 0];
    $months = [];
    for ($m = 1; $m <= 12; $m++) $months[sprintf('%04d-%02d', $year, $m)] = $blank;
    $y = (string)$year;
    $bySource = $byRoom = $byCategory = [];

    $q = $db->prepare("SELECT substr(check_in, 1, 7) AS ym, source, room_id, room_name, amount, amount_paid FROM bookings
        WHERE status = 'confirmed' AND source <> 'blocked' AND substr(check_in, 1, 4) = ?");
    $q->execute([$y]);
    foreach ($q->fetchAll() as $b) {
        if (!isset($months[$b['ym']])) continue;
        $amt = (int)round((float)$b['amount'] * 100);
        $paid = (int)round((float)$b['amount_paid'] * 100);
        $months[$b['ym']]['bookings']++;
        $months[$b['ym']]['room'] += $amt;
        $months[$b['ym']]['received'] += min($amt, $paid);
        $months[$b['ym']]['pending'] += max(0, $amt - $paid);
        $src = strtolower((string)$b['source']);
        $bySource[$src] ??= ['bookings' => 0, 'room' => 0];
        $bySource[$src]['bookings']++;
        $bySource[$src]['room'] += $amt;
        $byRoom[$b['room_id']] ??= ['name' => ROOM_IDS[$b['room_id']] ?? $b['room_name'], 'bookings' => 0, 'room' => 0];
        $byRoom[$b['room_id']]['bookings']++;
        $byRoom[$b['room_id']]['room'] += $amt;
    }

    $q = $db->prepare("SELECT substr(c.charged_on, 1, 7) AS ym, c.amount_paise, c.method FROM booking_charges c
        JOIN bookings b ON b.id = c.booking_id
        WHERE c.voided = 0 AND b.status = 'confirmed' AND substr(c.charged_on, 1, 4) = ?");
    $q->execute([$y]);
    foreach ($q->fetchAll() as $c) {
        if (!isset($months[$c['ym']])) continue;
        $months[$c['ym']]['food'] += (int)$c['amount_paise'];
        if ($c['method'] === '') $months[$c['ym']]['food_unpaid'] += (int)$c['amount_paise'];
    }

    $q = $db->prepare("SELECT substr(spent_on, 1, 7) AS ym, category, amount_paise FROM farm_expenses WHERE voided = 0 AND substr(spent_on, 1, 4) = ?");
    $q->execute([$y]);
    foreach ($q->fetchAll() as $e) {
        if (!isset($months[$e['ym']])) continue;
        $months[$e['ym']]['expenses'] += (int)$e['amount_paise'];
        $byCategory[$e['category']] = ($byCategory[$e['category']] ?? 0) + (int)$e['amount_paise'];
    }

    $total = $blank;
    foreach ($months as $ym => $row) {
        foreach ($blank as $k => $_) $total[$k] += $row[$k];
        $months[$ym] = _summaryFinish($row);
    }
    uasort($bySource, fn($a, $b) => $b['room'] <=> $a['room']);
    uasort($byRoom, fn($a, $b) => $b['room'] <=> $a['room']);
    arsort($byCategory);
    return ['months' => $months, 'total' => _summaryFinish($total), 'by_source' => $bySource, 'by_room' => $byRoom, 'by_category' => $byCategory];
}

/** Revenue = room + food; net = revenue - expenses. The sheet subtracted the wrong things here. */
function _summaryFinish(array $row): array {
    $row['revenue'] = $row['room'] + $row['food'];
    $row['net'] = $row['revenue'] - $row['expenses'];
    $row['margin'] = $row['revenue'] > 0 ? $row['net'] / $row['revenue'] : null;
    return $row;
}
