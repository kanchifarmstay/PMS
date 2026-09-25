<?php
/**
 * WhatsApp booking confirmation to the GUEST, sent as the approved template
 * kfs_booking_confirmed from the property's API number, with a "View
 * confirmation" button whose URL suffix is the signed booking-pdf.php link.
 *
 * Who gets it: bookings we own - a paid website booking (both Razorpay paths
 * call it) and a booking added in the admin panel with a guest phone. Never
 * an OTA booking: Airbnb / Booking.com / Agoda confirm with their own guests,
 * and an imported row has no phone we may message.
 *
 * Exactly once per booking: bookings.guest_confirm_sent_at is claimed with a
 * conditional UPDATE and released only if the send fails. Never throws.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-alerts.php';

const GUEST_CONFIRM_SOURCES = ['direct', 'phone', 'manual', 'razorpay'];

function guestConfirmConfig(): array {
    return [
        'token'    => ADMIN_ALERT_WA_TOKEN,
        'phone_id' => ADMIN_ALERT_WA_PHONE_ID,
        'template' => GUEST_CONFIRM_TEMPLATE,
        'language' => 'en',
    ];
}

function bookingPdfToken(int $id): string {
    return DOCUMENT_SIGNING_SECRET === '' ? '' : hash_hmac('sha256', 'pdf-' . $id, DOCUMENT_SIGNING_SECRET);
}

function guestConfirmationPayload(array $b, string $to, array $config): array {
    $paid = (float)($b['amount_paid'] ?? 0);
    $params = [
        templateParam($b['guest_name'] ?? ''),
        str_pad((string)$b['id'], 4, '0', STR_PAD_LEFT),
        templateParam($b['room_name'] ?? ''),
        date('D, d M Y', strtotime($b['check_in'])),
        date('D, d M Y', strtotime($b['check_out'])),
        number_format($paid, $paid == floor($paid) ? 0 : 2),
    ];
    return [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'template',
        'template'          => [
            'name'       => $config['template'],
            'language'   => ['code' => $config['language']],
            'components' => [
                ['type' => 'body', 'parameters' => array_map(fn(string $p) => ['type' => 'text', 'text' => $p], $params)],
                // The template's button URL is .../booking-pdf.php?{{1}}; this is the suffix.
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0',
                 'parameters' => [['type' => 'text', 'text' => 'id=' . (int)$b['id'] . '&token=' . bookingPdfToken((int)$b['id'])]]],
            ],
        ],
    ];
}

/** @return array{status:string, detail:string} */
function sendGuestBookingConfirmation(int $bookingId, ?callable $transport = null, ?array $config = null): array {
    try {
        $config ??= guestConfirmConfig();
        if ($config['token'] === '' || $config['phone_id'] === '') return ['status' => 'not_configured', 'detail' => ''];
        if (bookingPdfToken($bookingId) === '') return ['status' => 'not_configured', 'detail' => 'KFS_DOCUMENT_SIGNING_SECRET is not set'];
        $b = getBookingById($bookingId);
        if (!$b || ($b['status'] ?? '') !== 'confirmed' || !in_array($b['source'] ?? '', GUEST_CONFIRM_SOURCES, true) || isOtaSource($b['source'] ?? '')) {
            return ['status' => 'skipped', 'detail' => ''];
        }
        $created = kfsDbTimestamp($b['created_at'] ?? '');
        if ($created === null || time() - $created > ADMIN_ALERT_MAX_AGE_SECONDS) return ['status' => 'skipped', 'detail' => 'not a new booking'];
        $to = whatsAppNumber((string)(($b['whatsapp_number'] ?? '') ?: ($b['guest_phone'] ?? '')));
        if ($to === null) return ['status' => 'skipped', 'detail' => 'no valid guest phone'];

        $db = getDB();
        $claim = $db->prepare("UPDATE bookings SET guest_confirm_sent_at = datetime('now')
            WHERE id = ? AND (guest_confirm_sent_at IS NULL OR guest_confirm_sent_at = '')");
        $claim->execute([$bookingId]);
        if ($claim->rowCount() !== 1) return ['status' => 'already_sent', 'detail' => ''];

        $transport ??= 'sendWhatsAppCloudRequest';
        try {
            [$ok, $detail] = $transport($config, guestConfirmationPayload($b, $to, $config));
        } catch (Throwable $e) {
            [$ok, $detail] = [false, $e->getMessage()];
        }
        waLogSend($config['template'], $to, $ok ? 'sent' : 'failed', (string)$detail, $bookingId, 'automatic');
        if ($ok) return ['status' => 'sent', 'detail' => (string)$detail];
        $db->prepare("UPDATE bookings SET guest_confirm_sent_at = '' WHERE id = ?")->execute([$bookingId]);
        error_log("Guest booking confirmation #{$bookingId} failed: {$detail}");
        return ['status' => 'failed', 'detail' => (string)$detail];
    } catch (Throwable $e) {
        error_log("Guest booking confirmation #{$bookingId} crashed: " . $e->getMessage());
        return ['status' => 'error', 'detail' => $e->getMessage()];
    }
}

/** Send after the response has gone out, like deferAdminBookingAlert(). */
function deferGuestBookingConfirmation(int $bookingId): void {
    if ($bookingId <= 0) return;
    register_shutdown_function(static function () use ($bookingId): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        sendGuestBookingConfirmation($bookingId);
    });
}
