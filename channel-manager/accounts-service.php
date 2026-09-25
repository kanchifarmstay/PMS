<?php
/**
 * Accounts: the payment ledger per booking, refunds, collections and the GST
 * report.
 *
 * The ledger (`payments`) is the record of money; bookings.amount_paid is kept
 * equal to its net total so everything that already reads amount_paid (bills,
 * balances, WhatsApp, analytics) stays right without changes.
 *
 *   payment     money in              +amount
 *   refund      money back out        -amount
 *   correction  a fix typed into the  +/- amount (made automatically when
 *               booking edit form                  amount_paid is edited by hand)
 *
 * Entries are never deleted: a mistake is VOIDED with a reason and drops out
 * of every total. Money is integer paise.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bill-service.php';

const ACCT_METHODS = ['cash' => 'Cash', 'upi' => 'UPI', 'bank_transfer' => 'Bank transfer', 'card' => 'Card',
                      'online' => 'Online (Razorpay)', 'ota' => 'Collected by OTA', 'other' => 'Other'];
const ACCT_KINDS = ['payment' => 'Payment', 'refund' => 'Refund', 'correction' => 'Correction'];

function acctMethod(string $m): string {
    $m = strtolower(trim($m));
    return match ($m) { 'razorpay' => 'online', 'bank', 'neft', 'imps' => 'bank_transfer', default => isset(ACCT_METHODS[$m]) ? $m : 'other' };
}

/** Net money held for a booking, in paise: payments - refunds + corrections, voided excluded. */
function acctNetPaise(int $bookingId): int {
    $q = getDB()->prepare("SELECT COALESCE(SUM(CASE kind WHEN 'refund' THEN -amount_paise ELSE amount_paise END), 0)
        FROM payments WHERE booking_id = ? AND voided = 0");
    $q->execute([$bookingId]);
    return (int)$q->fetchColumn();
}

function acctLedger(int $bookingId): array {
    $q = getDB()->prepare('SELECT * FROM payments WHERE booking_id = ? ORDER BY paid_on, id');
    $q->execute([$bookingId]);
    return $q->fetchAll();
}

/** Write the ledger total back to the booking (amount_paid + payment_status). */
function acctApplyToBooking(int $bookingId): void {
    $b = getBookingById($bookingId);
    if (!$b) return;
    $paid = acctNetPaise($bookingId) / 100;
    $total = (float)$b['amount'];
    $status = $paid >= $total && $total > 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
    getDB()->prepare("UPDATE bookings SET amount_paid = ?, payment_status = ?, updated_at = datetime('now') WHERE id = ?")
        ->execute([$paid, $status, $bookingId]);
}

function acctInsert(int $bookingId, string $kind, int $amountPaise, string $method, string $reference, string $paidOn, string $note): int {
    getDB()->prepare("INSERT INTO payments (booking_id, kind, amount_paise, method, reference, paid_on, note) VALUES (?,?,?,?,?,?,?)")
        ->execute([$bookingId, $kind, $amountPaise, acctMethod($method), mb_substr(trim($reference), 0, 80), $paidOn, mb_substr(trim($note), 0, 300)]);
    return (int)getDB()->lastInsertId();
}

/**
 * Bring the ledger in line with bookings.amount_paid after something outside
 * the ledger changed it (add-booking form, edit form, Razorpay). The gap
 * becomes one entry: a payment when money went up, a correction when it went
 * down. Returns the entry id, or 0 when already in line.
 */
function acctSyncFromBooking(int $bookingId, string $note, ?string $reference = null): int {
    $b = getBookingById($bookingId);
    if (!$b || ($b['source'] ?? '') === 'blocked') return 0;
    $gap = (int)round((float)$b['amount_paid'] * 100) - acctNetPaise($bookingId);
    if ($gap === 0) return 0;
    $kind = $gap > 0 ? 'payment' : 'correction';
    $ref = $reference ?? (($b['source'] ?? '') === 'direct' ? (string)($b['booking_ref'] ?? '') : '');
    return acctInsert($bookingId, $kind, $gap, (string)($b['payment_method'] ?? 'cash'), $ref, date('Y-m-d'), $note);
}

/** One opening entry per booking that had money recorded before the ledger existed. Idempotent. */
function acctBackfillOpeningBalances(?int $onlyBookingId = null): int {
    $db = getDB();
    $q = $db->prepare("SELECT id, amount_paid, payment_method, created_at FROM bookings
        WHERE amount_paid > 0 AND source <> 'blocked' AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = bookings.id)"
        . ($onlyBookingId !== null ? ' AND id = ?' : ''));
    $q->execute($onlyBookingId !== null ? [$onlyBookingId] : []);
    $rows = $q->fetchAll();
    foreach ($rows as $r) {
        $on = ($ts = kfsDbTimestamp($r['created_at'] ?? '')) ? date('Y-m-d', $ts) : date('Y-m-d');
        acctInsert((int)$r['id'], 'payment', (int)round((float)$r['amount_paid'] * 100), (string)$r['payment_method'], '', $on,
            'Recorded before payment history');
    }
    return count($rows);
}

/**
 * Record a payment or refund typed on the payments page.
 * @return int the new entry id
 */
function acctRecord(int $bookingId, string $kind, mixed $amountRupees, string $method, string $reference, string $paidOn, string $note): int {
    $b = getBookingById($bookingId);
    if (!$b) throw new InvalidArgumentException('Booking not found.');
    if (!in_array($kind, ['payment', 'refund'], true)) throw new InvalidArgumentException('Unknown entry type.');
    $paise = rupeesToPaise($amountRupees);
    if ($paise <= 0) throw new InvalidArgumentException('Enter an amount greater than zero.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) || strtotime($paidOn) === false) throw new InvalidArgumentException('Enter the date.');
    if ($paidOn > date('Y-m-d')) throw new InvalidArgumentException('The date cannot be in the future.');
    if ($kind === 'refund' && $paise > acctNetPaise($bookingId)) {
        throw new InvalidArgumentException('A refund cannot be more than the Rs. ' . waMoneyFromPaise(acctNetPaise($bookingId)) . ' held for this booking.');
    }
    if (!isset(ACCT_METHODS[acctMethod($method)])) throw new InvalidArgumentException('Choose how the money was paid.');
    $db = getDB();
    $owned = kfsBeginTransaction($db);
    try {
        $id = acctInsert($bookingId, $kind, $paise, $method, $reference, $paidOn, $note);
        if ($kind === 'payment') {
            // The latest method, so the "payment received" WhatsApp names it correctly.
            getDB()->prepare('UPDATE bookings SET payment_method = ? WHERE id = ?')->execute([acctMethod($method), $bookingId]);
        }
        acctApplyToBooking($bookingId);
        kfsCommitTransaction($db, $owned);
    } catch (Throwable $e) {
        kfsRollbackTransaction($db, $owned);
        throw $e;
    }
    return $id;
}

function acctVoid(int $entryId, string $reason): int {
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException('Say why this entry is being voided.');
    $q = getDB()->prepare('SELECT booking_id, voided FROM payments WHERE id = ?');
    $q->execute([$entryId]);
    $row = $q->fetch();
    if (!$row) throw new InvalidArgumentException('Entry not found.');
    if ((int)$row['voided'] === 1) throw new InvalidArgumentException('Already voided.');
    getDB()->prepare("UPDATE payments SET voided = 1, void_reason = ?, voided_at = datetime('now') WHERE id = ?")
        ->execute([mb_substr($reason, 0, 200), $entryId]);
    acctApplyToBooking((int)$row['booking_id']);
    return (int)$row['booking_id'];
}

function waMoneyFromPaise(int $paise): string {
    $r = $paise / 100;
    return number_format($r, $paise % 100 === 0 ? 0 : 2);
}

// ── Reports ──────────────────────────────────────────────────

/** Money in and out between two IST dates, by method. Amounts in paise. */
function acctCollections(string $from, string $to): array {
    $q = getDB()->prepare("SELECT p.*, b.guest_name, b.room_name, b.source FROM payments p JOIN bookings b ON b.id = p.booking_id
        WHERE p.voided = 0 AND p.paid_on BETWEEN ? AND ? ORDER BY p.paid_on, p.id");
    $q->execute([$from, $to]);
    $rows = $q->fetchAll();
    $byMethod = [];
    $in = $out = $corr = 0;
    foreach ($rows as $r) {
        $amt = (int)$r['amount_paise'];
        $signed = $r['kind'] === 'refund' ? -$amt : $amt;
        $byMethod[$r['method']] = ($byMethod[$r['method']] ?? 0) + $signed;
        if ($r['kind'] === 'payment') $in += $amt; elseif ($r['kind'] === 'refund') $out += $amt; else $corr += $amt;
    }
    arsort($byMethod);
    return ['rows' => $rows, 'by_method' => $byMethod, 'received' => $in, 'refunded' => $out, 'corrections' => $corr, 'net' => $in - $out + $corr];
}

/**
 * GST summary for a month of invoices: every invoice (cancelled ones listed
 * but excluded from the totals, as GST returns require), and totals by rate.
 */
function acctGstReport(string $month): array {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) throw new InvalidArgumentException('Month must be YYYY-MM.');
    $q = getDB()->prepare("SELECT * FROM bills WHERE substr(invoice_date, 1, 7) = ? ORDER BY fy, seq");
    $q->execute([$month]);
    $invoices = [];
    $byRate = [];
    $tot = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'total' => 0, 'count' => 0, 'cancelled' => 0];
    foreach ($q->fetchAll() as $row) {
        $data = json_decode($row['data'], true) ?: [];
        $c = computeBill($data['items'] ?? [], (bool)($data['inclusive'] ?? true));
        $cancelled = $row['status'] === 'cancelled';
        $invoices[] = ['id' => (int)$row['id'], 'invoice_no' => $row['invoice_no'], 'date' => $row['invoice_date'],
            'customer' => $data['guest']['name'] ?? $row['guest_name'], 'gstin' => $data['guest']['gstin'] ?? '',
            'taxable' => $c['taxable'], 'cgst' => $c['cgst'], 'sgst' => $c['sgst'], 'total' => $c['grand'],
            'status' => $row['status'], 'rates' => implode(', ', array_unique(array_map(fn($s) => $s['gst'] . '%', $c['tax_summary'])))];
        if ($cancelled) { $tot['cancelled']++; continue; }
        $tot['count']++;
        foreach (['taxable', 'cgst', 'sgst'] as $k) $tot[$k] += $c[$k];
        $tot['total'] += $c['grand'];
        foreach ($c['tax_summary'] as $s) {
            $k = $s['gst'] . '|' . $s['sac'];
            $byRate[$k] ??= ['gst' => $s['gst'], 'sac' => $s['sac'], 'taxable' => 0, 'cgst' => 0, 'sgst' => 0];
            foreach (['taxable', 'cgst', 'sgst'] as $f) $byRate[$k][$f] += $s[$f];
        }
    }
    ksort($byRate);
    return ['invoices' => $invoices, 'by_rate' => array_values($byRate), 'totals' => $tot];
}
