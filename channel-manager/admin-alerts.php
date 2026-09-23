<?php
/**
 * WhatsApp alert to the admin numbers when a DIRECT booking is confirmed.
 *
 * Sent as an approved TEMPLATE from the property's own WhatsApp API number,
 * because free-form text only reaches somebody who messaged that number in
 * the last 24 hours, and an admin who has not is exactly who a new booking
 * must still reach.
 *
 * A direct booking is created by whichever of confirm_booking.php (the guest's
 * browser) or razorpay-webhook.php (Razorpay's server) arrives first, and both
 * call notifyAdminsOfDirectBooking(). bookings.admin_alert_sent_at is claimed
 * with a conditional UPDATE, so the second caller - or a Razorpay redelivery -
 * sends nothing. If every send fails the claim is released so the other path
 * can still try.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

const ADMIN_ALERT_MAX_AGE_SECONDS = 86400;

function adminAlertConfig(): array {
    return [
        'token'    => ADMIN_ALERT_WA_TOKEN,
        'phone_id' => ADMIN_ALERT_WA_PHONE_ID,
        'numbers'  => ADMIN_ALERT_WA_NUMBERS,
        'template' => ADMIN_ALERT_TEMPLATE,
        'language' => ADMIN_ALERT_TEMPLATE_LANG,
    ];
}

/** "+917200390283, 9028001639" -> ['917200390283', '919028001639']. A bare 10-digit number is Indian. */
function adminAlertNumbers(string $csv): array {
    $out = [];
    foreach (preg_split('/[,;\s]+/', $csv) ?: [] as $raw) {
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 10) $digits = '91' . $digits;
        if (strlen($digits) >= 11 && strlen($digits) <= 15) $out[$digits] = true;
    }
    // PHP turns numeric-string keys into ints; phone numbers must stay strings.
    return array_map('strval', array_keys($out));
}

/** Meta rejects a template variable containing a newline, a tab or 4+ spaces, and an empty one. */
function templateParam(mixed $value, int $max = 60): string {
    $text = trim((string)preg_replace('/\s+/u', ' ', (string)$value));
    if ($text === '') return '-';
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
}

/** The eight body variables of kfs_direct_booking_alert, in order. */
function adminBookingTemplateParams(array $b): array {
    $nights = max(1, (int)round((strtotime($b['check_out']) - strtotime($b['check_in'])) / 86400));
    $paid = (float)($b['amount_paid'] ?? 0) ?: (float)($b['amount'] ?? 0);
    return [
        str_pad((string)$b['id'], 4, '0', STR_PAD_LEFT),
        templateParam($b['guest_name'] ?? ''),
        templateParam(($b['guest_phone'] ?? '') ?: ($b['whatsapp_number'] ?? '')),
        templateParam($b['room_name'] ?? ''),
        date('D, d M Y', strtotime($b['check_in'])),
        date('D, d M Y', strtotime($b['check_out'])),
        (string)$nights,
        number_format($paid, $paid == floor($paid) ? 0 : 2),
    ];
}

function adminTemplatePayload(string $to, array $config, array $params): array {
    return [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'template',
        'template'          => [
            'name'       => $config['template'],
            'language'   => ['code' => $config['language']],
            'components' => [[
                'type'       => 'body',
                'parameters' => array_map(fn(string $p) => ['type' => 'text', 'text' => $p], $params),
            ]],
        ],
    ];
}

/** Real transport: POST to the Cloud API. Returns [ok, detail]. */
function sendWhatsAppCloudRequest(array $config, array $payload): array {
    $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode($config['phone_id']) . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $config['token']],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $json = json_decode($body, true);
    if ($code === 200 && !empty($json['messages'][0]['id'])) return [true, $json['messages'][0]['id']];
    $err = $json['error'] ?? [];
    return [false, $curlError ?: ('HTTP ' . $code . ' ' . ($err['code'] ?? '') . ' ' . ($err['message'] ?? substr($body, 0, 200)))];
}

/**
 * Send the alert for one booking. Safe to call more than once and from both
 * confirmation paths. Never throws: a WhatsApp failure must not turn a paid,
 * confirmed booking into an error page for the guest.
 *
 * @return array{status:string, sent:int, failed:int, errors:string[]}
 */
function notifyAdminsOfDirectBooking(int $bookingId, ?callable $transport = null, ?array $config = null): array {
    $result = ['status' => 'skipped', 'sent' => 0, 'failed' => 0, 'errors' => []];
    try {
        $config ??= adminAlertConfig();
        $numbers = adminAlertNumbers($config['numbers']);
        if ($config['token'] === '' || $config['phone_id'] === '' || !$numbers) {
            $result['status'] = 'not_configured';
            return $result;
        }
        $b = getBookingById($bookingId);
        if (!$b || ($b['source'] ?? '') !== 'direct' || ($b['status'] ?? '') !== 'confirmed') return $result;
        $created = kfsDbTimestamp($b['created_at'] ?? '');
        // A Razorpay redelivery for an old payment returns the old booking; do not alert on it now.
        if ($created === null || time() - $created > ADMIN_ALERT_MAX_AGE_SECONDS) return $result;

        $db = getDB();
        $claim = $db->prepare("UPDATE bookings SET admin_alert_sent_at = datetime('now')
            WHERE id = ? AND (admin_alert_sent_at IS NULL OR admin_alert_sent_at = '')");
        $claim->execute([$bookingId]);
        if ($claim->rowCount() !== 1) {
            $result['status'] = 'already_sent';
            return $result;
        }

        $transport ??= 'sendWhatsAppCloudRequest';
        $params = adminBookingTemplateParams($b);
        foreach ($numbers as $to) {
            [$ok, $detail] = $transport($config, adminTemplatePayload($to, $config, $params));
            if ($ok) {
                $result['sent']++;
            } else {
                $result['failed']++;
                $result['errors'][] = substr($to, 0, 4) . '…' . substr($to, -2) . ': ' . $detail;
            }
        }
        if ($result['sent'] === 0) {
            $db->prepare("UPDATE bookings SET admin_alert_sent_at = '' WHERE id = ?")->execute([$bookingId]);
        }
        $result['status'] = $result['sent'] > 0 ? 'sent' : 'failed';
        if ($result['errors']) error_log("Admin booking alert #{$bookingId}: " . implode(' | ', $result['errors']));
    } catch (Throwable $e) {
        error_log("Admin booking alert #{$bookingId} crashed: " . $e->getMessage());
        $result['status'] = 'error';
        $result['errors'][] = $e->getMessage();
    }
    return $result;
}

/**
 * Send after the HTTP response has gone out, so three WhatsApp calls never
 * delay the guest's confirmation screen or Razorpay's webhook acknowledgement.
 */
function deferAdminBookingAlert(int $bookingId): void {
    if ($bookingId <= 0) return;
    register_shutdown_function(static function () use ($bookingId): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        notifyAdminsOfDirectBooking($bookingId);
    });
}
