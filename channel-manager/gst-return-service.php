<?php
/**
 * GST return figures (GSTR-1 and GSTR-3B) for a month or a quarter, built from
 * what actually happened - every stay and every food charge - rather than only
 * from the tax invoices someone remembered to raise. acctGstReport() in
 * accounts-service.php is the invoice list; this is the return.
 *
 * Rules it follows, so the page does not have to explain them twice:
 *   - A stay is a supply in the period its CHECK-OUT falls in (the invoice is
 *     raised at check-out). A food charge falls on its own date.
 *   - A booking with an issued bill linked to it is replaced by that bill, which
 *     carries its exact lines and rates and falls on the bill's date.
 *   - Booking and charge amounts are what the guest paid, GST included, the same
 *     assumption the bill page makes. Pass $inclusive = false if they are not.
 *   - Room rate is the per-night test in defaultRoomGstRate(): 5% up to
 *     Rs 7,500 a unit a night before tax, 18% above.
 *   - Everything is CGST + SGST: the place of supply for a room is the property.
 *   - Money is integer paise, and every line goes through computeBill(), so the
 *     return and the invoices round the same way.
 *
 * Supplies booked through an OTA appear in B2C like every other stay AND in
 * table 14 against that OTA's GSTIN; table 14 is reported in addition, not
 * instead.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bill-service.php';
require_once __DIR__ . '/charges-service.php';

/** Booking source => e-commerce operator name. Each operator's GSTIN is a setting (gst_eco_gstin_<key>). */
const GST_ECO_OPERATORS = ['airbnb' => 'Airbnb', 'booking.com' => 'Booking.com', 'agoda' => 'Agoda', 'makemytrip' => 'MakeMyTrip'];

const GST_SAC_NAMES = ['996311' => 'Accommodation services', '996331' => 'Food & beverage services', '999799' => 'Other services'];

function gstEcoGstins(): array {
    $out = [];
    foreach (GST_ECO_OPERATORS as $key => $_) $out[$key] = getSetting('gst_eco_gstin_' . $key, '');
    return $out;
}

function saveGstEcoGstins(array $input): void {
    foreach (GST_ECO_OPERATORS as $key => $name) {
        $g = strtoupper(trim((string)($input[$key] ?? '')));
        if ($g !== '' && !isValidGstin($g)) throw new InvalidArgumentException("The GSTIN for {$name} is not 15 characters in the GST format.");
        setSetting('gst_eco_gstin_' . $key, $g);
    }
}

/**
 * A return period: 'YYYY-MM' is one month, 'YYYY-MM:3' the quarter starting
 * that month. Returns [from, to, label, months[]].
 */
function gstPeriod(string $spec): array {
    if (!preg_match('/^(\d{4})-(\d{2})(?::(1|3))?$/', $spec, $m) || (int)$m[2] < 1 || (int)$m[2] > 12) {
        throw new InvalidArgumentException('Period must be YYYY-MM or YYYY-MM:3.');
    }
    $len = (int)($m[3] ?? 1);
    $from = sprintf('%s-%s-01', $m[1], $m[2]);
    $to = date('Y-m-t', strtotime($from . ' +' . ($len - 1) . ' months'));
    $months = [];
    for ($i = 0; $i < $len; $i++) $months[] = date('Y-m', strtotime($from . " +{$i} months"));
    $label = $len === 1 ? date('F Y', strtotime($from))
        : date('M', strtotime($from)) . '–' . date('M Y', strtotime($to)) . ' (Q' . gstFyQuarter($from) . ' FY ' . financialYear($from) . ')';
    return ['from' => $from, 'to' => $to, 'label' => $label, 'months' => $months];
}

/** Indian FY quarter of a date: Apr-Jun = 1 ... Jan-Mar = 4. */
function gstFyQuarter(string $date): int {
    return intdiv(((int)date('n', strtotime($date)) + 8) % 12, 3) + 1;
}

/** The quarter (as a 'YYYY-MM:3' spec) a date falls in. */
function gstQuarterSpec(string $date): string {
    $m = (int)date('n', strtotime($date));
    $start = $m - (($m - 1) % 3);
    return date('Y', strtotime($date)) . '-' . sprintf('%02d', $start) . ':3';
}

function gstReturn(string $spec, bool $inclusive = true): array {
    $p = gstPeriod($spec);
    $db = getDB();

    // Issued bills, and which bookings they stand in for.
    $billed = [];
    foreach ($db->query("SELECT booking_id FROM bills WHERE status = 'issued' AND booking_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $bid) {
        $billed[(int)$bid] = true;
    }
    $q = $db->prepare("SELECT * FROM bills WHERE invoice_date BETWEEN ? AND ? ORDER BY fy, seq");
    $q->execute([$p['from'], $p['to']]);
    $bills = $q->fetchAll();

    $lines = [];   // the register: one row per taxable line
    $checks = [];  // things a person should look at before filing
    $otaStays = []; // source => booking ids, for one reminder per OTA
    $add = function (array $base, array $items, bool $incl) use (&$lines): void {
        $c = computeBill($items, $incl);
        foreach ($c['lines'] as $l) {
            $lines[] = $base + ['sac' => $l['sac'], 'gst' => (int)$l['gst'], 'desc' => $l['desc'],
                'taxable' => $l['taxable'], 'cgst' => $l['cgst'], 'sgst' => $l['sgst']];
        }
    };

    // 1. Stays that checked out in the period and are not covered by a bill.
    $q = $db->prepare("SELECT * FROM bookings WHERE status = 'confirmed' AND source <> 'blocked' AND check_out BETWEEN ? AND ? ORDER BY check_out, id");
    $q->execute([$p['from'], $p['to']]);
    foreach ($q->fetchAll() as $b) {
        if (isset($billed[(int)$b['id']])) continue;
        $amount = rupeesToPaise($b['amount'] ?? 0);
        $src = strtolower((string)$b['source']);
        $ref = ['booking_id' => (int)$b['id'], 'guest' => (string)$b['guest_name'], 'room' => (string)$b['room_name'], 'source' => $src];
        if ($amount <= 0) {
            $checks[] = $ref + ['why' => 'No amount on this booking, so it adds nothing to the return. Enter what the guest paid.'];
            continue;
        }
        $nights = max(1, (int)round((strtotime($b['check_out']) - strtotime($b['check_in'])) / 86400));
        $rate = defaultRoomGstRate(intdiv($amount, $nights), $inclusive);
        $add($ref + ['date' => $b['check_out'], 'kind' => 'room', 'invoice_no' => '', 'customer_gstin' => ''],
            [['desc' => 'Room — ' . $b['room_name'], 'sac' => '996311', 'qty' => 1, 'rate' => $amount, 'gst' => $rate]], $inclusive);
        if ($rate === 18) {
            $checks[] = $ref + ['why' => 'Taxed at 18%: ' . fmtPaise(intdiv($amount, $nights)) . ' a night is over Rs 7,500 for one unit. If this was several rooms, the test is per room and it may be 5% - correct the booking or raise a bill.'];
        }
        if (isset(GST_ECO_OPERATORS[$src])) $otaStays[$src][] = (int)$b['id'];
    }
    // One reminder per OTA, not one per stay.
    foreach ($otaStays as $src => $ids) {
        $n = count($ids);
        $checks[] = ['booking_id' => 0, 'guest' => $n . ' ' . GST_ECO_OPERATORS[$src] . ' stay' . ($n === 1 ? '' : 's'), 'room' => '#' . implode(', #', $ids), 'source' => $src,
            'why' => 'each amount must be what the guest paid (before commission), not your payout. Check them against the ' . GST_ECO_OPERATORS[$src] . ' statement for this period.'];
    }

    // 2. Food and extras charged in the period, on stays not covered by a bill.
    $q = $db->prepare("SELECT c.*, b.guest_name, b.room_name FROM booking_charges c JOIN bookings b ON b.id = c.booking_id
        WHERE c.voided = 0 AND c.charged_on BETWEEN ? AND ? ORDER BY c.charged_on, c.id");
    $q->execute([$p['from'], $p['to']]);
    foreach ($q->fetchAll() as $c) {
        if (isset($billed[(int)$c['booking_id']])) continue;
        $preset = BILL_ITEM_PRESETS[$c['kind']] ?? BILL_ITEM_PRESETS['other'];
        // Food is served by the farm stay itself, never through the OTA, so it is not a table 14 supply.
        $add(['booking_id' => (int)$c['booking_id'], 'guest' => (string)$c['guest_name'], 'room' => (string)$c['room_name'], 'source' => '',
              'date' => $c['charged_on'], 'kind' => $c['kind'], 'invoice_no' => '', 'customer_gstin' => ''],
            [['desc' => $preset['label'] . ($c['description'] !== '' ? ' — ' . $c['description'] : ''), 'sac' => $preset['sac'], 'qty' => 1,
              'rate' => (int)$c['amount_paise'], 'gst' => $preset['gst']]], $inclusive);
    }

    // 3. Bills dated in the period. A bill linked to a booking replaced it above.
    //    An unlinked bill is counted only when it is B2B (the guest's GSTIN must reach
    //    table 4); an unlinked B2C bill is taken to be a copy of a stay already counted.
    $docs = ['from' => '', 'to' => '', 'total' => 0, 'cancelled' => 0];
    foreach ($bills as $row) {
        $docs['from'] = $docs['from'] ?: $row['invoice_no'];
        $docs['to'] = $row['invoice_no'];
        $docs['total']++;
        if ($row['status'] === 'cancelled') { $docs['cancelled']++; continue; }
        $data = json_decode($row['data'], true) ?: [];
        $gstin = strtoupper(trim((string)($data['guest']['gstin'] ?? '')));
        $linked = !empty($row['booking_id']);
        if (!$linked && $gstin === '') continue;
        $guest = (string)($data['guest']['name'] ?? $row['guest_name']);
        $src = '';
        if ($linked) {
            $src = strtolower((string)(getBookingById((int)$row['booking_id'])['source'] ?? ''));
        } else {
            $checks[] = ['booking_id' => 0, 'guest' => $guest, 'room' => (string)($data['stay']['room_name'] ?? ''), 'source' => '',
                'why' => 'Business invoice ' . $row['invoice_no'] . ' is not linked to a booking, so its stay is probably ALSO in B2C. Subtract it from B2C by hand, or re-issue the bill from the booking.'];
        }
        $add(['booking_id' => (int)($row['booking_id'] ?? 0), 'guest' => $guest, 'room' => (string)($data['stay']['room_name'] ?? ''),
              'source' => $src, 'date' => $row['invoice_date'], 'kind' => 'bill', 'invoice_no' => $row['invoice_no'], 'customer_gstin' => $gstin],
            $data['items'] ?? [], (bool)($data['inclusive'] ?? true));
    }

    // Totals and the tables of the return.
    $zero = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0];
    $sum = function (array &$into, array $l): void { foreach (['taxable', 'cgst', 'sgst'] as $k) $into[$k] += $l[$k]; };
    $total = $zero;
    $byMonth = array_fill_keys($p['months'], $zero);
    $b2cs = []; $b2b = []; $hsn = ['b2b' => [], 'b2c' => []]; $eco = [];
    foreach ($lines as $l) {
        $sum($total, $l);
        $sum($byMonth[substr($l['date'], 0, 7)], $l);
        $isB2b = $l['customer_gstin'] !== '';
        if ($isB2b) {
            $k = $l['invoice_no'];
            $b2b[$k] ??= ['invoice_no' => $k, 'date' => $l['date'], 'gstin' => $l['customer_gstin'], 'guest' => $l['guest'], 'rates' => []] + $zero;
            $b2b[$k]['rates'][$l['gst']] = true;
            $sum($b2b[$k], $l);
        } else {
            $b2cs[$l['gst']] ??= ['gst' => $l['gst']] + $zero;
            $sum($b2cs[$l['gst']], $l);
        }
        $tab = $isB2b ? 'b2b' : 'b2c';
        $hk = $l['sac'] . '|' . $l['gst'];
        $hsn[$tab][$hk] ??= ['sac' => $l['sac'], 'gst' => $l['gst'], 'name' => GST_SAC_NAMES[$l['sac']] ?? ''] + $zero;
        $sum($hsn[$tab][$hk], $l);
        if (isset(GST_ECO_OPERATORS[$l['source']]) && $l['sac'] === '996311') {
            $eco[$l['source']] ??= ['key' => $l['source'], 'name' => GST_ECO_OPERATORS[$l['source']], 'count' => 0] + $zero;
            $sum($eco[$l['source']], $l);
            $eco[$l['source']]['count']++;
        }
    }
    ksort($b2cs);
    ksort($hsn['b2b']);
    ksort($hsn['b2c']);
    $stays = count(array_unique(array_column(array_filter($lines, fn($l) => $l['kind'] === 'room' || $l['kind'] === 'bill'), 'booking_id')));

    return [
        'period'   => $p,
        'total'    => $total + ['tax' => $total['cgst'] + $total['sgst']],
        'by_month' => $byMonth,
        'b2cs'     => array_values($b2cs),
        'b2b'      => array_values($b2b),
        'hsn'      => ['b2b' => array_values($hsn['b2b']), 'b2c' => array_values($hsn['b2c'])],
        'eco'      => array_values($eco),
        'docs'     => $docs,
        'stays'    => $stays,
        'lines'    => $lines,
        'checks'   => $checks,
    ];
}
