<?php
/**
 * Shared by whatsapp-logs.php and the tests: labels, the one-time import of
 * sends recorded before the log existed, and the filter -> SQL translation.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/wa-templates.php';

const WA_LOG_PAGE_SIZE = 100;
const WA_LOG_LABELS = [
    'kfs_direct_booking_alert'    => 'New direct booking (admin)',
    'kfs_booking_confirmed'       => 'Booking confirmation',
    'kfs_invoice_ready'           => 'GST invoice',
    'kfs_booking_updated'         => 'Booking updated',
    'kfs_payment_received'        => 'Payment received',
    'kfs_balance_reminder'        => 'Balance reminder',
    'kfs_checkin_reminder'        => 'Check-in reminder',
    'kfs_booking_cancelled'       => 'Booking cancelled',
    'kfs_refund_processed'        => 'Refund processed',
    'kfs_admin_ota_booking'       => 'New OTA booking (admin)',
    'kfs_admin_booking_cancelled' => 'Cancellation (admin)',
    'kfs_admin_payment_received'  => 'Payment (admin)',
    'kfs_admin_daily_summary'     => 'Daily summary (admin)',
];
const WA_LOG_VISIBLE_STATUSES = ['sent', 'failed', 'blocked'];

function lh(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Sends made before this page existed were recorded only as a timestamp on the
 * booking or bill. Bring them in once each; a row that is already logged (same
 * booking and template, or same bill) is never duplicated.
 */
function waBackfillLegacyLogs(): void {
    $db = getDB();
    $db->exec("INSERT INTO wa_template_log (booking_id, template, recipient, status, detail, context, created_at)
        SELECT b.id, 'kfs_booking_confirmed', '', 'sent', '', 'automatic (before logs)', b.guest_confirm_sent_at
        FROM bookings b
        WHERE b.guest_confirm_sent_at <> '' AND NOT EXISTS (
            SELECT 1 FROM wa_template_log l WHERE l.booking_id = b.id AND l.template = 'kfs_booking_confirmed')");
    $db->exec("INSERT INTO wa_template_log (booking_id, template, recipient, status, detail, context, created_at)
        SELECT b.id, 'kfs_direct_booking_alert', 'admins', 'sent', '', 'automatic (before logs)', b.admin_alert_sent_at
        FROM bookings b
        WHERE b.admin_alert_sent_at <> '' AND NOT EXISTS (
            SELECT 1 FROM wa_template_log l WHERE l.booking_id = b.id AND l.template = 'kfs_direct_booking_alert')");
    $db->exec("INSERT INTO wa_template_log (booking_id, template, recipient, status, detail, context, created_at)
        SELECT b.booking_id, 'kfs_invoice_ready', b.wa_sent_to, 'sent', '', 'Bill ' || b.invoice_no, b.wa_sent_at
        FROM bills b
        WHERE b.wa_sent_at <> '' AND NOT EXISTS (
            SELECT 1 FROM wa_template_log l WHERE l.template = 'kfs_invoice_ready' AND l.context = 'Bill ' || b.invoice_no)");
}

/** An IST calendar date -> the UTC timestamp its start (or end) falls on, as stored in created_at. */
function waLogUtcBound(string $date, bool $end): ?string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    $dt = new DateTime($date . ($end ? ' 23:59:59' : ' 00:00:00'), new DateTimeZone(PROPERTY_TIMEZONE));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

function waLogFilters(array $in): array {
    $status = (string)($in['status'] ?? '');
    return [
        'status'   => in_array($status, ['sent', 'failed', 'blocked', 'all'], true) ? $status : '',
        'template' => isset(WA_LOG_LABELS[$in['template'] ?? '']) ? (string)$in['template'] : '',
        'q'        => mb_substr(trim((string)($in['q'] ?? '')), 0, 80),
        'from'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['from'] ?? '')) ? (string)$in['from'] : '',
        'to'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['to'] ?? '')) ? (string)$in['to'] : '',
    ];
}

/** @return array{0:string,1:array} WHERE clause and its parameters. */
function waLogWhere(array $f): array {
    $where = [];
    $args = [];
    if ($f['status'] === '' ) {
        $where[] = "status IN ('" . implode("','", WA_LOG_VISIBLE_STATUSES) . "')";
    } elseif ($f['status'] !== 'all') {
        $where[] = 'status = ?';
        $args[] = $f['status'];
    }
    if ($f['template'] !== '') { $where[] = 'template = ?'; $args[] = $f['template']; }
    if ($f['q'] !== '') {
        $digits = preg_replace('/\D/', '', $f['q']);
        $like = '%' . $f['q'] . '%';
        $clauses = ['detail LIKE ?', 'context LIKE ?'];
        array_push($args, $like, $like);
        if ($digits !== '') {
            $clauses[] = 'recipient LIKE ?';
            $args[] = '%' . $digits . '%';
            $clauses[] = 'booking_id = ?';
            $args[] = (int)ltrim($digits, '0');
        }
        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }
    if ($f['from'] !== '') { $where[] = 'created_at >= ?'; $args[] = waLogUtcBound($f['from'], false); }
    if ($f['to'] !== '')   { $where[] = 'created_at <= ?'; $args[] = waLogUtcBound($f['to'], true); }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $args];
}

function waLogIst(string $utc): string {
    $ts = kfsDbTimestamp($utc);
    return $ts === null ? '' : date('d M Y, g:i A', $ts);
}
