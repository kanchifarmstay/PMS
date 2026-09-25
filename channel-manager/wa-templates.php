<?php
/**
 * The approved WhatsApp templates that are not a single fixed event:
 *
 *   automatic, on a PMS action
 *     kfs_booking_updated          guest   admin edits room / dates / total
 *     kfs_payment_received         guest   admin records a higher amount paid
 *     kfs_admin_payment_received   admins  (same)
 *     kfs_admin_booking_cancelled  admins  admin cancels a booking
 *   automatic, from cron.php (every 15 min, each once)
 *     kfs_checkin_reminder         guest   the day before check-in, after 10:00
 *     kfs_balance_reminder         guest   (same, only when a balance is due)
 *     kfs_admin_daily_summary      admins  every morning after 08:00
 *     kfs_admin_ota_booking        admins  a new OTA reservation after a sync
 *   by hand, from booking-whatsapp.php
 *     kfs_booking_cancelled, kfs_refund_processed - they carry a refund amount
 *     and reference the PMS does not store - plus resends of the others.
 *
 * kfs_direct_booking_alert (admin-alerts.php), kfs_booking_confirmed
 * (guest-whatsapp.php) and kfs_invoice_ready (bill-service.php) keep their own
 * modules.
 *
 * Guests are only messaged for bookings we own (GUEST_CONFIRM_SOURCES); an OTA
 * guest belongs to the OTA. Every automatic send is claimed in wa_template_log
 * by a dedupe key before it goes out and the claim is dropped if the send
 * fails, so a double submit, two cron runs or a retry never send twice.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-alerts.php';
require_once __DIR__ . '/guest-whatsapp.php';

const WA_CHECKIN_REMINDER_HOUR = 10;
const WA_DAILY_SUMMARY_HOUR = 8;
const WA_OTA_ALERTS_PER_RUN = 10;

function waConfig(): array {
    return ['token' => ADMIN_ALERT_WA_TOKEN, 'phone_id' => ADMIN_ALERT_WA_PHONE_ID,
            'numbers' => ADMIN_ALERT_WA_NUMBERS, 'language' => 'en'];
}

function waBookingNo(array $b): string { return str_pad((string)$b['id'], 4, '0', STR_PAD_LEFT); }
function waDate(string $d): string { return date('D, d M Y', strtotime($d)); }
function waMoney(float $v): string { return number_format($v, $v == floor($v) ? 0 : 2); }
function waBalance(array $b): float { return max(0.0, (float)($b['amount'] ?? 0) - (float)($b['amount_paid'] ?? 0)); }

function waDateRange(string $in, string $out): string {
    $a = strtotime($in); $b = strtotime($out);
    return date('M Y', $a) === date('M Y', $b) ? date('j', $a) . '-' . date('j M Y', $b) : date('j M', $a) . ' - ' . date('j M Y', $b);
}

function waPaymentMethod(string $m): string {
    return match ($m) {
        'cash' => 'Cash', 'upi' => 'UPI', 'bank_transfer' => 'Bank transfer', 'online', 'razorpay' => 'Online payment',
        'card' => 'Card', '' => 'Payment', default => ucwords(str_replace('_', ' ', $m)),
    };
}

function waSourceLabel(string $s): string {
    return match (strtolower($s)) {
        'airbnb' => 'Airbnb', 'booking.com' => 'Booking.com', 'agoda' => 'Agoda', 'makemytrip' => 'MakeMyTrip',
        'direct', 'razorpay' => 'Direct (website)', 'phone' => 'Phone', 'manual' => 'Manual', default => ucfirst($s),
    };
}

function waGuestNumber(array $b): ?string {
    if (!in_array($b['source'] ?? '', GUEST_CONFIRM_SOURCES, true) || isOtaSource($b['source'] ?? '')) return null;
    return whatsAppNumber((string)(($b['whatsapp_number'] ?? '') ?: ($b['guest_phone'] ?? '')));
}

function waPayload(string $template, string $to, array $params, ?string $urlSuffix, string $lang = 'en'): array {
    $components = [['type' => 'body', 'parameters' => array_map(
        fn($p) => ['type' => 'text', 'text' => templateParam($p)], $params)]];
    if ($urlSuffix !== null) {
        $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0',
                         'parameters' => [['type' => 'text', 'text' => $urlSuffix]]];
    }
    return ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template',
            'template' => ['name' => $template, 'language' => ['code' => $lang], 'components' => $components]];
}

/**
 * Send one template. With a dedupe key the send is claimed first and only one
 * caller ever gets through; without one (a manual send) it always goes and is
 * only logged.
 *
 * @return array{status:string, detail:string}
 */
function waSend(string $template, string $to, array $params, ?string $urlSuffix, ?int $bookingId, ?string $dedupeKey,
                ?callable $transport = null, ?array $config = null): array {
    $config ??= waConfig();
    if ($config['token'] === '' || $config['phone_id'] === '') return ['status' => 'not_configured', 'detail' => ''];
    if ($bookingId !== null) {
        $bk = getBookingById($bookingId);
        $admins = adminAlertNumbers((string)($config['numbers'] ?? ADMIN_ALERT_WA_NUMBERS));
        if ($bk && isOtaSource($bk['source'] ?? '') && !in_array($to, $admins, true)) {
            $why = 'Guests who booked through ' . waSourceLabel((string)$bk['source']) . ' are never messaged.';
            waLogSend($template, $to, 'blocked', $why, $bookingId, $dedupeKey === null ? 'manual' : 'automatic');
            return ['status' => 'blocked_ota', 'detail' => $why];
        }
    }
    $db = getDB();
    if ($dedupeKey !== null) {
        $claim = $db->prepare("INSERT OR IGNORE INTO wa_template_log (dedupe_key, booking_id, template, recipient, status)
            VALUES (?, ?, ?, ?, 'claimed')");
        $claim->execute([$dedupeKey, $bookingId, $template, $to]);
        if ($claim->rowCount() !== 1) return ['status' => 'duplicate', 'detail' => ''];
    }
    $transport ??= 'sendWhatsAppCloudRequest';
    try {
        [$ok, $detail] = $transport($config, waPayload($template, $to, $params, $urlSuffix, $config['language']));
    } catch (Throwable $e) {
        [$ok, $detail] = [false, $e->getMessage()];
    }
    if ($ok) {
        if ($dedupeKey !== null) {
            $db->prepare("UPDATE wa_template_log SET status='sent', detail=?, context='automatic' WHERE dedupe_key=?")->execute([(string)$detail, $dedupeKey]);
        } else {
            waLogSend($template, $to, 'sent', (string)$detail, $bookingId, 'manual');
        }
        return ['status' => 'sent', 'detail' => (string)$detail];
    }
    if ($dedupeKey !== null) $db->prepare("DELETE FROM wa_template_log WHERE dedupe_key=?")->execute([$dedupeKey]);
    // The claim was released so a later run can retry; the failure itself is kept for the logs page.
    waLogSend($template, $to, 'failed', (string)$detail, $bookingId, $dedupeKey === null ? 'manual' : 'automatic');
    error_log("WhatsApp {$template} to " . substr($to, 0, 4) . '…' . substr($to, -2) . " failed: {$detail}");
    return ['status' => 'failed', 'detail' => (string)$detail];
}

/** Same template to every admin number; one dedupe key per admin. */
function waSendAdmins(string $template, array $params, ?int $bookingId, ?string $keyBase,
                      ?callable $transport = null, ?array $config = null): array {
    $config ??= waConfig();
    $out = [];
    foreach (adminAlertNumbers($config['numbers']) as $to) {
        $out[$to] = waSend($template, $to, $params, null, $bookingId, $keyBase === null ? null : "{$keyBase}:{$to}", $transport, $config);
    }
    return $out;
}

// ── Guest templates, by name ─────────────────────────────────

function waGuestUpdatedParams(array $b): array {
    return [$b['guest_name'], waBookingNo($b), $b['room_name'], waDate($b['check_in']), waDate($b['check_out']), waMoney((float)$b['amount'])];
}
function waGuestPaymentParams(array $b, float $amount, string $method): array {
    return [$b['guest_name'], waMoney($amount), waBookingNo($b), waPaymentMethod($method), waMoney(waBalance($b))];
}
function waCheckinParams(array $b): array { return [$b['guest_name'], waDate($b['check_in']), $b['room_name']]; }
function waBalanceParams(array $b): array { return [$b['guest_name'], waBookingNo($b), waDate($b['check_in']), waMoney(waBalance($b))]; }
function waCancelledParams(array $b, float $refund): array {
    return [$b['guest_name'], waBookingNo($b), waDateRange($b['check_in'], $b['check_out']), waMoney($refund)];
}
function waRefundParams(array $b, float $amount, string $reference): array {
    return [$b['guest_name'], waMoney($amount), waBookingNo($b), $reference !== '' ? $reference : 'Booking #' . waBookingNo($b)];
}

// ── Events from the admin panel ──────────────────────────────

/** Called after edit_booking with the row before and after the save. Never throws. */
function waOnBookingEdited(array $before, array $after, ?callable $transport = null, ?array $config = null): array {
    $done = [];
    try {
        if (($after['source'] ?? '') === 'blocked') return $done;
        if (($before['status'] ?? '') !== 'cancelled' && ($after['status'] ?? '') === 'cancelled') {
            return ['cancel' => waOnBookingCancelled($after, $transport, $config)];
        }
        if (($after['status'] ?? '') !== 'confirmed') return $done;
        $to = waGuestNumber($after);

        $changed = false;
        foreach (['room_id', 'check_in', 'check_out'] as $f) $changed = $changed || (string)$before[$f] !== (string)$after[$f];
        $changed = $changed || abs((float)$before['amount'] - (float)$after['amount']) >= 0.01;
        if ($changed && $to !== null && $after['check_out'] >= date('Y-m-d')) {
            $key = 'updated:' . $after['id'] . ':' . md5($after['room_id'] . '|' . $after['check_in'] . '|' . $after['check_out'] . '|' . $after['amount']);
            $done['updated'] = waSend('kfs_booking_updated', $to, waGuestUpdatedParams($after), null, (int)$after['id'], $key, $transport, $config);
        }

        $paidNow = (float)$after['amount_paid'];
        $received = $paidNow - (float)$before['amount_paid'];
        if ($received >= 0.01) {
            $key = 'payment:' . $after['id'] . ':' . waMoney($paidNow);
            $method = (string)($after['payment_method'] ?? '');
            if ($to !== null) {
                $done['payment_guest'] = waSend('kfs_payment_received', $to, waGuestPaymentParams($after, $received, $method), null, (int)$after['id'], $key, $transport, $config);
            }
            $done['payment_admins'] = waSendAdmins('kfs_admin_payment_received',
                [waMoney($received), waBookingNo($after), $after['guest_name'], waPaymentMethod($method), waMoney(waBalance($after))],
                (int)$after['id'], $key . ':admin', $transport, $config);
        }
    } catch (Throwable $e) {
        error_log('WhatsApp booking-edit notifications crashed: ' . $e->getMessage());
        $done['error'] = $e->getMessage();
    }
    return $done;
}

/** Admins are told about every cancellation, whatever the source. Never throws. */
function waOnBookingCancelled(array $b, ?callable $transport = null, ?array $config = null): array {
    try {
        if (($b['source'] ?? '') === 'blocked') return [];
        return waSendAdmins('kfs_admin_booking_cancelled',
            [waBookingNo($b), $b['guest_name'], $b['room_name'], waDateRange($b['check_in'], $b['check_out']), waSourceLabel((string)$b['source'])],
            (int)$b['id'], 'cancel:' . $b['id'], $transport, $config);
    } catch (Throwable $e) {
        error_log('WhatsApp cancellation alert crashed: ' . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

/** Run a notification after the response is flushed, so admin actions stay instant. */
function waDefer(callable $fn): void {
    register_shutdown_function(static function () use ($fn): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        try { $fn(); } catch (Throwable $e) { error_log('Deferred WhatsApp send crashed: ' . $e->getMessage()); }
    });
}

// ── Scheduled jobs (cron.php) ────────────────────────────────

/** The physical rooms: every room id that is not a bundle of others. */
function waPhysicalRooms(): array {
    return array_values(array_filter(array_keys(ROOM_IDS), fn(string $id) => !isset(INVENTORY_COMPONENTS[$id])));
}

function waExpandRoom(string $roomId, int $depth = 0): array {
    if (!isset(INVENTORY_COMPONENTS[$roomId]) || $depth > 3) return [$roomId];
    $out = [];
    foreach (INVENTORY_COMPONENTS[$roomId] as $c) $out = array_merge($out, waExpandRoom($c, $depth + 1));
    return array_values(array_unique($out));
}

function waDailySummaryParams(string $today): array {
    $db = getDB();
    $bk = $db->prepare("SELECT * FROM bookings WHERE status='confirmed' AND source <> 'blocked' AND check_in <= ? AND check_out >= ?");
    $bk->execute([$today, $today]);
    $arrivals = $departures = 0;
    $balanceToday = 0.0;
    $occupied = [];
    foreach ($bk->fetchAll() as $b) {
        if ($b['check_in'] === $today) { $arrivals++; $balanceToday += waBalance($b); }
        if ($b['check_out'] === $today) $departures++;
        if ($b['check_in'] <= $today && $b['check_out'] > $today) foreach (waExpandRoom($b['room_id']) as $r) $occupied[$r] = true;
    }
    $ext = $db->prepare("SELECT platform, external_uid, room_id, check_in, check_out FROM external_blocks WHERE check_in <= ? AND check_out >= ?");
    $ext->execute([$today, $today]);
    $seenIn = $seenOut = [];
    foreach ($ext->fetchAll() as $x) {
        $k = $x['platform'] . '|' . $x['external_uid'] . '|' . $x['check_in'] . '|' . $x['check_out'];
        if ($x['check_in'] === $today && !isset($seenIn[$k])) { $seenIn[$k] = true; $arrivals++; }
        if ($x['check_out'] === $today && !isset($seenOut[$k])) { $seenOut[$k] = true; $departures++; }
        if ($x['check_in'] <= $today && $x['check_out'] > $today) foreach (waExpandRoom($x['room_id']) as $r) $occupied[$r] = true;
    }
    $rooms = waPhysicalRooms();
    $occ = count(array_intersect(array_keys($occupied), $rooms));
    return [waDate($today), (string)$arrivals, (string)$departures, $occ . ' of ' . count($rooms), waMoney($balanceToday)];
}

/**
 * New OTA reservations since the last sync. The first run only records what is already there.
 *
 * A reservation is recognised by its UID AND by platform + room + dates, because a feed's UID
 * is not stable: MakeMyTrip/Goibibo re-issue it every few hours for the same stay, and keying
 * on the UID alone re-announced one Wooden Cottage night to every admin four times on
 * 2026-09-25. Each claim row keeps its rooms in `detail` ("rooms=a,b") so a later UID for
 * the same room and dates is known. Two different reservations cannot hold one room on the
 * same dates, so matching on the room loses nothing.
 */
function waDetectNewOtaReservations(?callable $transport = null, ?array $config = null): array {
    $db = getDB();
    $today = date('Y-m-d');
    $rows = $db->prepare("SELECT platform, external_uid, room_id, check_in, check_out FROM external_blocks WHERE check_out > ? ORDER BY check_in");
    $rows->execute([$today]);
    $groups = [];
    foreach ($rows->fetchAll() as $r) {
        $k = 'ota:' . $r['platform'] . ':' . $r['external_uid'] . ':' . $r['check_in'] . ':' . $r['check_out'];
        $groups[$k] ??= $r + ['rooms' => []];
        $groups[$k]['rooms'][$r['room_id']] = true;
    }
    // Claims made before rooms were recorded: fill them in while their UID is still in the feed.
    $backfill = $db->prepare("UPDATE wa_template_log SET detail=? WHERE dedupe_key=? AND COALESCE(detail, '')=''");
    foreach ($groups as $key => $g) $backfill->execute([waOtaRoomsDetail($g), $key]);
    $known = [];
    foreach ($db->query("SELECT dedupe_key, detail FROM wa_template_log WHERE template='kfs_admin_ota_booking' AND dedupe_key LIKE 'ota:%' AND detail LIKE 'rooms=%'") as $p) {
        $parts = explode(':', $p['dedupe_key']);
        $dates = $parts[count($parts) - 2] . '|' . $parts[count($parts) - 1];
        foreach (explode(',', substr($p['detail'], 6)) as $room) $known[$parts[1] . '|' . $room . '|' . $dates] = true;
    }
    $seeded = getSetting('wa_ota_seeded') === '1';
    $claim = $db->prepare("INSERT OR IGNORE INTO wa_template_log (dedupe_key, template, status, detail) VALUES (?, 'kfs_admin_ota_booking', ?, ?)");
    $alerted = 0;
    $result = ['seeded' => 0, 'alerted' => 0];
    foreach ($groups as $key => $g) {
        $slots = array_map(fn($room) => $g['platform'] . '|' . $room . '|' . $g['check_in'] . '|' . $g['check_out'], array_keys($g['rooms']));
        if (!$seeded) { $claim->execute([$key, 'seeded', waOtaRoomsDetail($g)]); $result['seeded']++; continue; }
        if ($alerted >= WA_OTA_ALERTS_PER_RUN) break;
        if (array_filter($slots, fn($s) => isset($known[$s]))) continue;
        $claim->execute([$key, 'claimed', waOtaRoomsDetail($g)]);
        if ($claim->rowCount() !== 1) continue;
        foreach ($slots as $s) $known[$s] = true;
        $names = array_map(fn($id) => $id === GROUP_INVENTORY_ID ? 'Whole farm stay' : (ROOM_IDS[$id] ?? $id), array_keys($g['rooms']));
        $platform = waSourceLabel($g['platform']);
        $sends = waSendAdmins('kfs_admin_ota_booking',
            [$platform, implode(', ', $names), waDate($g['check_in']), waDate($g['check_out']), 'Not shared by ' . $platform],
            null, null, $transport, $config);
        if (!array_filter($sends, fn($s) => $s['status'] === 'sent')) {
            $db->prepare("DELETE FROM wa_template_log WHERE dedupe_key=?")->execute([$key]);
            foreach ($slots as $s) unset($known[$s]);
            continue;
        }
        $db->prepare("UPDATE wa_template_log SET status='sent' WHERE dedupe_key=?")->execute([$key]);
        $alerted++;
    }
    if (!$seeded) setSetting('wa_ota_seeded', '1');
    $result['alerted'] = $alerted;
    return $result;
}

function waOtaRoomsDetail(array $group): string {
    $rooms = array_keys($group['rooms']);
    sort($rooms);
    return 'rooms=' . implode(',', $rooms);
}

/**
 * Everything cron.php runs. Each part is isolated so one failure never stops
 * the calendar sync or the other parts.
 */
function waRunScheduledJobs(?int $now = null, ?callable $transport = null, ?array $config = null): array {
    $now ??= time();
    $today = date('Y-m-d', $now);
    $hour = (int)date('G', $now);
    $out = [];
    try {
        $out['ota'] = waDetectNewOtaReservations($transport, $config);
    } catch (Throwable $e) { $out['ota_error'] = $e->getMessage(); }

    if ($hour >= WA_CHECKIN_REMINDER_HOUR) {
        try {
            $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
            $q = getDB()->prepare("SELECT * FROM bookings WHERE status='confirmed' AND check_in = ?");
            $q->execute([$tomorrow]);
            $out['checkin'] = $out['balance'] = 0;
            foreach ($q->fetchAll() as $b) {
                $to = waGuestNumber($b);
                if ($to === null) continue;
                if (waSend('kfs_checkin_reminder', $to, waCheckinParams($b), null, (int)$b['id'], "checkin:{$b['id']}:{$b['check_in']}", $transport, $config)['status'] === 'sent') $out['checkin']++;
                if (waBalance($b) >= 1 && waSend('kfs_balance_reminder', $to, waBalanceParams($b), null, (int)$b['id'], "balance:{$b['id']}:{$b['check_in']}", $transport, $config)['status'] === 'sent') $out['balance']++;
            }
        } catch (Throwable $e) { $out['reminder_error'] = $e->getMessage(); }
    }

    if ($hour >= WA_DAILY_SUMMARY_HOUR) {
        try {
            $sends = waSendAdmins('kfs_admin_daily_summary', waDailySummaryParams($today), null, "summary:{$today}", $transport, $config);
            $out['summary'] = count(array_filter($sends, fn($s) => $s['status'] === 'sent'));
        } catch (Throwable $e) { $out['summary_error'] = $e->getMessage(); }
    }
    return $out;
}

function waLogForBooking(int $bookingId): array {
    $q = getDB()->prepare("SELECT template, recipient, status, created_at FROM wa_template_log WHERE booking_id = ? AND status = 'sent' ORDER BY id DESC LIMIT 50");
    $q->execute([$bookingId]);
    return $q->fetchAll();
}
