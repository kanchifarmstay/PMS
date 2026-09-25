<?php
/**
 * Bill / GST tax invoice generator — the rules, kept out of the page so the
 * tests can hold them.
 *
 * Money is integer PAISE everywhere in here. A float ₹ total rounded three
 * times (line, tax, grand total) drifts by a paisa, and on a tax invoice a
 * CGST + SGST that does not add up to the printed tax is the first thing an
 * accountant rejects.
 *
 * Accommodation is always CGST + SGST, never IGST: the place of supply for a
 * hotel room is where the property is (IGST Act s.12(3)), so a guest from
 * Bengaluru is still an intra-Tamil Nadu supply. That is why there is no IGST
 * toggle anywhere in this file.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-alerts.php';

const BILL_GST_RATES = [0, 5, 12, 18, 28];

/** Room tariffs at or below this per unit per day (pre-tax, in paise) take 5%, above it 18%. */
const BILL_ROOM_5PCT_CEILING_PAISE = 750000;

const BILL_ITEM_PRESETS = [
    'room'  => ['label' => 'Room tariff',        'sac' => '996311', 'gst' => 5],
    'extra' => ['label' => 'Extra guest charge', 'sac' => '996311', 'gst' => 5],
    'food'  => ['label' => 'Food & beverages',   'sac' => '996331', 'gst' => 5],
    'other' => ['label' => 'Other service',      'sac' => '999799', 'gst' => 18],
];

const BILL_PROFILE_DEFAULTS = [
    'trade_name' => 'Kanchi Farm Stay',
    'legal_name' => '',
    'address'    => "506, Satha Nagar, Chithathur Village,\nVembakkam, Near Alandur Sub Post Office,\nTiruvannamalai District, Tamil Nadu",
    'state'      => 'Tamil Nadu',
    'state_code' => '33',
    'gstin'      => '33BFYPP2186L1ZM',
    'phone1'     => '+91 6383726094',
    'phone2'     => '+91 8825775747',
    'email'      => 'ops@kanchifarmstay.com',
    'website'    => 'kanchifarmstay.com',
    'prefix'     => 'KFS',
    'footer'     => 'Thank you for staying with us.',
];

function billProfile(): array {
    $profile = [];
    foreach (BILL_PROFILE_DEFAULTS as $key => $default) {
        $profile[$key] = getSetting('bill_' . $key, $default);
    }
    return $profile;
}

function saveBillProfile(array $input): void {
    $gstin = strtoupper(trim((string)($input['gstin'] ?? '')));
    if ($gstin !== '' && !isValidGstin($gstin)) {
        throw new InvalidArgumentException('That GSTIN is not 15 characters in the GST format.');
    }
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($input['prefix'] ?? '')));
    if ($prefix === '' || strlen($prefix) > 6) {
        throw new InvalidArgumentException('Invoice prefix must be 1 to 6 letters or digits.');
    }
    foreach (BILL_PROFILE_DEFAULTS as $key => $_) {
        $value = trim((string)($input[$key] ?? ''));
        if ($key === 'gstin') $value = $gstin;
        if ($key === 'prefix') $value = $prefix;
        setSetting('bill_' . $key, $value);
    }
}

/** A GST invoice address is incomplete without a 6-digit PIN code. */
function billAddressNeedsPin(string $address): bool {
    return !preg_match('/\b[1-9][0-9]{5}\b|\b[1-9][0-9]{2}\s[0-9]{3}\b/', $address);
}

function isValidGstin(string $gstin): bool {
    return (bool)preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', strtoupper($gstin));
}

function rupeesToPaise(mixed $value): int {
    $clean = str_replace([',', '₹', ' '], '', (string)$value);
    if ($clean === '' || !is_numeric($clean)) return 0;
    return (int)round((float)$clean * 100);
}

function fmtPaise(int $paise, bool $symbol = true): string {
    $sign = $paise < 0 ? '-' : '';
    $abs  = abs($paise);
    $digits = (string)intdiv($abs, 100);
    // Indian grouping: last three digits, then pairs — 12,34,567.
    if (strlen($digits) > 3) {
        $head = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($digits, 0, -3));
        $digits = $head . ',' . substr($digits, -3);
    }
    return $sign . ($symbol ? '₹' : '') . $digits . '.' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
}

/** Indian financial year label for a date: 2026-09-23 -> "2026-27", 2027-02-01 -> "2026-27". */
function financialYear(string $date): string {
    $ts = strtotime($date);
    if ($ts === false) throw new InvalidArgumentException('Invalid invoice date.');
    $y = (int)date('Y', $ts);
    $start = (int)date('n', $ts) >= 4 ? $y : $y - 1;
    return $start . '-' . substr((string)($start + 1), -2);
}

/** "KFS/26-27/0001" — 14 characters; GST allows at most 16, unique per financial year. */
function formatInvoiceNumber(string $prefix, string $fy, int $seq): string {
    return $prefix . '/' . substr($fy, 2) . '/' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

/**
 * Default GST rate for a room line. The ceiling is on the value of supply, i.e.
 * the pre-tax tariff, so an inclusive ₹7,800 night (₹7,428.57 + 5%) is still 5%.
 */
function defaultRoomGstRate(int $perNightPaise, bool $inclusive): int {
    $preTax = $inclusive ? (int)round($perNightPaise * 100 / 105) : $perNightPaise;
    return $preTax <= BILL_ROOM_5PCT_CEILING_PAISE ? 5 : 18;
}

/**
 * Normalise raw form rows into items. Drops blank rows; refuses a rate the
 * law does not have rather than printing it.
 */
function normaliseBillItems(array $rows): array {
    $items = [];
    foreach ($rows as $row) {
        $desc = trim((string)($row['desc'] ?? ''));
        $rate = rupeesToPaise($row['rate'] ?? '');
        $qtyRaw = str_replace(',', '', trim((string)($row['qty'] ?? '1')));
        $qty  = is_numeric($qtyRaw) ? (float)$qtyRaw : 0.0;
        if ($desc === '' && $rate === 0) continue;
        if ($desc === '') throw new InvalidArgumentException('Every line with an amount needs a description.');
        if ($qty <= 0) throw new InvalidArgumentException("Quantity for \"{$desc}\" must be more than zero.");
        if ($rate < 0) throw new InvalidArgumentException("Rate for \"{$desc}\" cannot be negative.");
        $gst = (int)($row['gst'] ?? 0);
        if (!in_array($gst, BILL_GST_RATES, true)) throw new InvalidArgumentException("GST {$gst}% is not a GST rate.");
        $items[] = [
            'desc' => mb_substr($desc, 0, 200),
            'sac'  => preg_replace('/[^0-9]/', '', (string)($row['sac'] ?? '')),
            'qty'  => round($qty, 2),
            'rate' => $rate,
            'gst'  => $gst,
        ];
    }
    return $items;
}

/**
 * The arithmetic. Each line is split into taxable value and tax, the tax into
 * CGST and SGST (the odd paisa goes to SGST so the halves always sum to the
 * tax), and the grand total is rounded to the rupee with the round-off shown.
 */
function computeBill(array $items, bool $inclusive): array {
    $lines = [];
    $byRate = [];
    $taxable = $cgst = $sgst = 0;
    foreach ($items as $item) {
        $gross = (int)round($item['qty'] * $item['rate']);
        $r = (int)$item['gst'];
        if ($inclusive) {
            $lineTaxable = (int)round($gross * 100 / (100 + $r));
            $lineTax = $gross - $lineTaxable;
        } else {
            $lineTaxable = $gross;
            $lineTax = (int)round($gross * $r / 100);
        }
        $lineCgst = intdiv($lineTax, 2);
        $lineSgst = $lineTax - $lineCgst;
        $lines[] = $item + [
            'taxable' => $lineTaxable,
            'cgst'    => $lineCgst,
            'sgst'    => $lineSgst,
            'total'   => $lineTaxable + $lineTax,
        ];
        $key = $item['sac'] . '|' . $r;
        $byRate[$key] ??= ['sac' => $item['sac'], 'gst' => $r, 'taxable' => 0, 'cgst' => 0, 'sgst' => 0];
        $byRate[$key]['taxable'] += $lineTaxable;
        $byRate[$key]['cgst']    += $lineCgst;
        $byRate[$key]['sgst']    += $lineSgst;
        $taxable += $lineTaxable;
        $cgst += $lineCgst;
        $sgst += $lineSgst;
    }
    $exact = $taxable + $cgst + $sgst;
    $grand = (int)(round($exact / 100) * 100);
    return [
        'lines'       => $lines,
        'tax_summary' => array_values($byRate),
        'taxable'     => $taxable,
        'cgst'        => $cgst,
        'sgst'        => $sgst,
        'tax'         => $cgst + $sgst,
        'round_off'   => $grand - $exact,
        'grand'       => $grand,
    ];
}

function amountInWords(int $paise): string {
    $rupees = intdiv(abs($paise), 100);
    $p = abs($paise) % 100;
    $words = 'Rupees ' . ($rupees === 0 ? 'Zero' : _indianWords($rupees));
    if ($p > 0) $words .= ' and ' . _indianWords($p) . ' Paise';
    return $words . ' Only';
}

function _indianWords(int $n): string {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = fn(int $x): string => $x < 20 ? $ones[$x] : trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]);
    $parts = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$unit, $name]) {
        if ($n >= $unit) {
            $count = intdiv($n, $unit);
            $parts[] = ($count >= 100 ? _indianWords($count) : $two($count)) . ' ' . $name;
            $n %= $unit;
        }
    }
    if ($n > 0) $parts[] = $two($n);
    return implode(' ', $parts);
}

/** A bill pre-filled from a booking. Nothing here is saved until the owner presses Save. */
function billDraftFromBooking(array $b): array {
    $nights = max(1, (int)round((strtotime($b['check_out']) - strtotime($b['check_in'])) / 86400));
    $amount = rupeesToPaise($b['amount'] ?? 0);
    // Keep the total exact: per-night only when the amount divides evenly.
    [$qty, $rate] = $amount % $nights === 0 ? [$nights, intdiv($amount, $nights)] : [1, $amount];
    $desc = 'Accommodation — ' . $b['room_name'] . ' (' . date('d M', strtotime($b['check_in']))
        . ' – ' . date('d M Y', strtotime($b['check_out'])) . ', ' . $nights . ' night' . ($nights === 1 ? '' : 's') . ')';
    return [
        'booking_id'   => (int)$b['id'],
        'invoice_date' => date('Y-m-d'),
        'guest' => [
            'name'    => (string)$b['guest_name'],
            'phone'   => (string)(($b['guest_phone'] ?? '') ?: ($b['whatsapp_number'] ?? '')),
            'email'   => (string)($b['guest_email'] ?? ''),
            'address' => '',
            'gstin'   => '',
        ],
        'stay' => [
            'room_name' => (string)$b['room_name'],
            'check_in'  => (string)$b['check_in'],
            'check_out' => (string)$b['check_out'],
            'ref'       => (string)($b['booking_ref'] ?? ''),
        ],
        'inclusive' => true,
        'items' => $amount > 0 ? [[
            'desc' => $desc, 'sac' => '996311', 'qty' => $qty, 'rate' => $rate,
            'gst' => defaultRoomGstRate(intdiv($amount, $nights), true),
        ]] : [],
        'paid'           => rupeesToPaise($b['amount_paid'] ?? 0),
        'payment_method' => (string)($b['payment_method'] ?? ''),
        'notes'          => '',
    ];
}

function emptyBillDraft(): array {
    return [
        'booking_id' => null, 'invoice_date' => date('Y-m-d'),
        'guest' => ['name' => '', 'phone' => '', 'email' => '', 'address' => '', 'gstin' => ''],
        'stay'  => ['room_name' => '', 'check_in' => '', 'check_out' => '', 'ref' => ''],
        'inclusive' => true, 'items' => [], 'paid' => 0, 'payment_method' => '', 'notes' => '',
    ];
}

/** Turn a POSTed form into a validated bill document. */
function billFromInput(array $in): array {
    $name = trim((string)($in['guest_name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('Guest / customer name is required.');
    $date = (string)($in['invoice_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
        throw new InvalidArgumentException('Invoice date is required.');
    }
    $guestGstin = strtoupper(trim((string)($in['guest_gstin'] ?? '')));
    if ($guestGstin !== '' && !isValidGstin($guestGstin)) {
        throw new InvalidArgumentException('Guest GSTIN is not in the GST format — leave it blank for an individual.');
    }
    $items = normaliseBillItems((array)($in['items'] ?? []));
    if (!$items) throw new InvalidArgumentException('Add at least one line to bill.');
    $bookingId = (int)($in['booking_id'] ?? 0);
    return [
        'booking_id'   => $bookingId > 0 ? $bookingId : null,
        'invoice_date' => $date,
        'guest' => [
            'name'    => mb_substr($name, 0, 120),
            'phone'   => mb_substr(trim((string)($in['guest_phone'] ?? '')), 0, 40),
            'email'   => mb_substr(trim((string)($in['guest_email'] ?? '')), 0, 120),
            'address' => mb_substr(trim((string)($in['guest_address'] ?? '')), 0, 300),
            'gstin'   => $guestGstin,
        ],
        'stay' => [
            'room_name' => mb_substr(trim((string)($in['room_name'] ?? '')), 0, 120),
            'check_in'  => (string)($in['check_in'] ?? ''),
            'check_out' => (string)($in['check_out'] ?? ''),
            'ref'       => mb_substr(trim((string)($in['booking_ref'] ?? '')), 0, 80),
        ],
        'inclusive'      => !empty($in['inclusive']),
        'items'          => $items,
        'paid'           => max(0, rupeesToPaise($in['paid'] ?? 0)),
        'payment_method' => mb_substr(trim((string)($in['payment_method'] ?? '')), 0, 40),
        'notes'          => mb_substr(trim((string)($in['notes'] ?? '')), 0, 1000),
    ];
}

/**
 * Issue a new invoice, or re-save an existing one under the SAME number.
 * The business profile is snapshotted into the bill so a later change of
 * address does not rewrite invoices already handed to guests.
 */
function saveBill(array $bill, ?int $id = null): int {
    $db = getDB();
    $totals = computeBill($bill['items'], $bill['inclusive']);
    $fy = financialYear($bill['invoice_date']);
    $owned = kfsBeginTransaction($db);
    try {
        if ($id) {
            $existing = getBill($id);
            if (!$existing) throw new InvalidArgumentException('Bill not found.');
            if ($existing['status'] === 'cancelled') throw new InvalidArgumentException('A cancelled invoice cannot be edited.');
            if ($existing['fy'] !== $fy) {
                throw new InvalidArgumentException("Invoice {$existing['invoice_no']} belongs to FY {$existing['fy']}; its date must stay in that year.");
            }
            $bill['business'] = $existing['data']['business'] ?? billProfile();
            $db->prepare("UPDATE bills SET booking_id=?, invoice_date=?, guest_name=?, total_paise=?, data=?, updated_at=datetime('now') WHERE id=?")
               ->execute([$bill['booking_id'], $bill['invoice_date'], $bill['guest']['name'], $totals['grand'], json_encode($bill, JSON_UNESCAPED_UNICODE), $id]);
        } else {
            $bill['business'] = billProfile();
            $seqStmt = $db->prepare("SELECT COALESCE(MAX(seq), 0) + 1 FROM bills WHERE fy = ?");
            $seqStmt->execute([$fy]);
            $seq = (int)$seqStmt->fetchColumn();
            $number = formatInvoiceNumber($bill['business']['prefix'] ?: 'KFS', $fy, $seq);
            $db->prepare("INSERT INTO bills (invoice_no, fy, seq, booking_id, invoice_date, guest_name, total_paise, data) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$number, $fy, $seq, $bill['booking_id'], $bill['invoice_date'], $bill['guest']['name'], $totals['grand'], json_encode($bill, JSON_UNESCAPED_UNICODE)]);
            $id = (int)$db->lastInsertId();
        }
        kfsCommitTransaction($db, $owned);
    } catch (Throwable $e) {
        kfsRollbackTransaction($db, $owned);
        throw $e;
    }
    return $id;
}

/** Invoices are cancelled, never deleted: a gap in the series is what GST scrutiny asks about. */
function cancelBill(int $id): void {
    getDB()->prepare("UPDATE bills SET status='cancelled', updated_at=datetime('now') WHERE id=?")->execute([$id]);
}

function getBill(int $id): ?array {
    $stmt = getDB()->prepare("SELECT * FROM bills WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['data'] = json_decode($row['data'], true) ?: [];
    return $row;
}

function listBills(int $limit = 200): array {
    $stmt = getDB()->prepare("SELECT id, invoice_no, booking_id, invoice_date, guest_name, total_paise, status FROM bills ORDER BY fy DESC, seq DESC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function billToken(int $id): string {
    return DOCUMENT_SIGNING_SECRET === '' ? '' : hash_hmac('sha256', 'bill-' . $id, DOCUMENT_SIGNING_SECRET);
}

/* ── Send the invoice on WhatsApp ──────────────────────────────
 * The bill page's "Send on WhatsApp" posts here. It sends the approved
 * template kfs_invoice_ready from the property's API number to the guest,
 * with the signed bill link as the button's URL suffix. A template, not free
 * text, because a guest who has not messaged the number in 24h can only be
 * reached with one.
 */

/** Guest phone as WhatsApp wants it: digits with country code; a bare 10-digit number is Indian. */
function billWhatsAppNumber(string $phone): ?string {
    return whatsAppNumber($phone);
}

function billStayLabel(array $bill): string {
    $room = trim((string)($bill['stay']['room_name'] ?? ''));
    $in = (string)($bill['stay']['check_in'] ?? '');
    $out = (string)($bill['stay']['check_out'] ?? '');
    $dates = '';
    if ($in !== '' && $out !== '') {
        $a = strtotime($in); $b = strtotime($out);
        $dates = date('M Y', $a) === date('M Y', $b)
            ? date('j', $a) . '-' . date('j M Y', $b)
            : date('j M', $a) . ' - ' . date('j M Y', $b);
    }
    $label = trim($room . ($room !== '' && $dates !== '' ? ', ' : '') . $dates);
    return $label !== '' ? $label : 'Invoice dated ' . date('d M Y', strtotime((string)$bill['invoice_date']));
}

function billInvoiceTemplatePayload(array $row, string $to, string $template, string $lang): array {
    $bill = $row['data'];
    $grand = computeBill($bill['items'], (bool)$bill['inclusive'])['grand'];
    $total = fmtPaise($grand, false);
    if (str_ends_with($total, '.00')) $total = substr($total, 0, -3);
    $params = [
        templateParam($bill['guest']['name'] ?? ''),
        (string)$row['invoice_no'],
        templateParam(billStayLabel($bill)),
        $total,
    ];
    return [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'template',
        'template'          => [
            'name'       => $template,
            'language'   => ['code' => $lang],
            'components' => [
                ['type' => 'body', 'parameters' => array_map(fn(string $p) => ['type' => 'text', 'text' => $p], $params)],
                // The template's button URL is .../bill.php?{{1}}; this is the suffix.
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0',
                 'parameters' => [['type' => 'text', 'text' => 'id=' . (int)$row['id'] . '&token=' . billToken((int)$row['id'])]]],
            ],
        ],
    ];
}

/** A bill linked to an Airbnb / Booking.com / Agoda / MakeMyTrip booking. Those guests are never messaged. */
function billIsForOtaBooking(array $row): bool {
    $bookingId = (int)($row['data']['booking_id'] ?? $row['booking_id'] ?? 0);
    if ($bookingId <= 0) return false;
    $b = getBookingById($bookingId);
    return $b !== null && isOtaSource($b['source'] ?? '');
}

/** Meta's error codes, said the way the owner needs to hear them. */
function billWhatsAppErrorText(string $detail): string {
    return match (true) {
        str_contains($detail, '132001') => 'The invoice template is not approved by Meta yet. Use "Open chat" for now.',
        str_contains($detail, '131026') => 'This number does not appear to be on WhatsApp.',
        str_contains($detail, '131047') => 'WhatsApp refused the message outside the 24-hour window.',
        str_contains($detail, '131042') => 'Meta refused the send: the WhatsApp account has a payment method problem.',
        str_contains($detail, '190')    => 'The WhatsApp token was rejected. Check KFS_WA_TOKEN in kfs.env.',
        default => 'WhatsApp send failed: ' . $detail,
    };
}

/** @return array{ok:bool, message:string, to?:string} */
function sendBillOnWhatsApp(int $id, ?callable $transport = null, ?array $config = null): array {
    $row = getBill($id);
    if (!$row) return ['ok' => false, 'message' => 'Bill not found.'];
    if ($row['status'] === 'cancelled') return ['ok' => false, 'message' => 'A cancelled invoice is not sent.'];
    if (billIsForOtaBooking($row)) return ['ok' => false, 'message' => 'This bill is for a booking made through a booking platform. Those guests are not messaged on WhatsApp — print or email the bill instead.'];
    $config ??= ['token' => ADMIN_ALERT_WA_TOKEN, 'phone_id' => ADMIN_ALERT_WA_PHONE_ID,
                 'template' => INVOICE_WA_TEMPLATE, 'language' => 'en'];
    if ($config['token'] === '' || $config['phone_id'] === '') {
        return ['ok' => false, 'message' => 'WhatsApp is not configured (KFS_WA_TOKEN / KFS_WA_PHONE_ID).'];
    }
    if (billToken($id) === '') return ['ok' => false, 'message' => 'Guest links are off: KFS_DOCUMENT_SIGNING_SECRET is not set.'];
    $to = billWhatsAppNumber((string)($row['data']['guest']['phone'] ?? ''));
    if ($to === null) return ['ok' => false, 'message' => 'This bill has no valid guest phone number. Edit the bill and add one.'];

    $transport ??= 'sendWhatsAppCloudRequest';
    try {
        [$ok, $detail] = $transport($config, billInvoiceTemplatePayload($row, $to, $config['template'], $config['language']));
    } catch (Throwable $e) {
        [$ok, $detail] = [false, $e->getMessage()];
    }
    $db = getDB();
    $linkedBooking = (int)($row['data']['booking_id'] ?? 0) ?: null;
    waLogSend($config['template'], $to, $ok ? 'sent' : 'failed', (string)$detail, $linkedBooking, 'Bill ' . $row['invoice_no']);
    if ($ok) {
        $db->prepare("UPDATE bills SET wa_sent_at = datetime('now'), wa_sent_to = ?, wa_last_error = '' WHERE id = ?")->execute([$to, $id]);
        return ['ok' => true, 'message' => 'Invoice sent on WhatsApp to +' . $to . '.', 'to' => $to];
    }
    $db->prepare("UPDATE bills SET wa_last_error = ? WHERE id = ?")->execute([mb_substr((string)$detail, 0, 300), $id]);
    error_log("Invoice WhatsApp send failed for bill #{$id}: {$detail}");
    return ['ok' => false, 'message' => billWhatsAppErrorText((string)$detail), 'to' => $to];
}
