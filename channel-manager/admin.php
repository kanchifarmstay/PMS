<?php
/**
 * Kanchi Farm Stay — Intelligent PMS Dashboard v2
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/booking-service.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/demand-engine.php';

startSecureSession();

// ── Auth ─────────────────────────────────────────────────────
$loginError = '';
if (($_POST['action'] ?? '') === 'login') {
    requireValidCsrfToken($_POST['csrf_token'] ?? null);
    if (verifyAdminPassword((string)($_POST['password'] ?? ''))) {
        $_SESSION['admin_logged_in'] = true;
        session_regenerate_id(true);
        header('Location: admin.php'); exit;
    }
    $loginError = 'Incorrect password.';
}
// ── POST handlers (authenticated) ────────────────────────────
if (!empty($_SESSION['admin_logged_in'])) {
    $act = $_POST['action'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireValidCsrfToken($_POST['csrf_token'] ?? null);
    }

    if ($act === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: admin.php'); exit;
    }

    if ($act === 'add_booking') {
        $rid    = $_POST['room_id'];
        $total  = (float)($_POST['amount'] ?? 0);
        $paid   = (float)($_POST['amount_paid'] ?? 0);
        $pmeth  = $_POST['payment_method'] ?? 'cash';
        $pstatus = $paid >= $total && $total > 0 ? 'paid' : ($paid > 0 ? 'deposit_only' : 'unpaid');
        $data = [
            'room_id'         => $rid,
            'room_name'       => ROOM_IDS[$rid] ?? $rid,
            'check_in'        => $_POST['check_in'],
            'check_out'       => $_POST['check_out'],
            'guest_name'      => trim($_POST['guest_name'] ?: 'Blocked'),
            'guest_email'     => trim($_POST['guest_email'] ?? ''),
            'guest_phone'     => trim($_POST['guest_phone'] ?? ''),
            'whatsapp_number' => trim($_POST['whatsapp_number'] ?? $_POST['guest_phone'] ?? ''),
            'source'          => $_POST['source'] ?? 'phone',
            'booking_ref'     => trim($_POST['booking_ref'] ?? ''),
            'amount'          => $total,
            'amount_paid'     => $paid,
            'payment_method'  => $pmeth,
            'payment_status'  => $pstatus,
            'notes'           => trim($_POST['notes'] ?? ''),
            'status'          => 'confirmed',
        ];
        try {
            $id = createConfirmedBooking($data);
        } catch (Throwable $e) {
            header('Location: admin.php?section=bookings&flash=' . urlencode($e->getMessage())); exit;
        }
        if ($id) {
            sendWhatsAppNotification(buildBookingMessage(array_merge($data, ['id' => $id])));
            // Send confirmation to guest if WhatsApp number provided and Meta API configured
            if (!empty($data['whatsapp_number']) && META_WA_TOKEN) {
                sendMetaWABookingConfirmation(array_merge($data, ['id' => $id]));
            }
            // If converted from a WA conversation, link it
            if (!empty($_POST['wa_conversation_id'])) {
                $cid = (int)$_POST['wa_conversation_id'];
                updateConversation($cid, ['status' => 'confirmed', 'booking_id' => $id]);
                addWAMessage($cid, 'system', "✅ Booking #" . str_pad($id, 4, '0', STR_PAD_LEFT) . " confirmed for {$data['room_name']} · " . $data['check_in'] . " → " . $data['check_out']);
            }
        }
        header('Location: admin.php?section=bookings&flash=Booking+added'); exit;
    }

    if ($act === 'wa_reply') {
        $cid  = (int)($_POST['conversation_id'] ?? 0);
        $body = trim($_POST['body'] ?? '');
        if ($cid && $body) sendWAReply($cid, $body);
        header('Location: admin.php?section=wa_inbox&conv=' . $cid . '&flash=Message+sent'); exit;
    }

    if ($act === 'wa_update_status') {
        $cid = (int)($_POST['conversation_id'] ?? 0);
        $st  = $_POST['status'] ?? 'awaiting_reply';
        if ($cid) updateConversation($cid, ['status' => $st]);
        header('Location: admin.php?section=wa_inbox&conv=' . $cid); exit;
    }

    if ($act === 'wa_add_manual') {
        $phone = preg_replace('/\D/', '', trim($_POST['phone'] ?? ''));
        $name  = trim($_POST['guest_name'] ?? 'Unknown Guest');
        $msg   = trim($_POST['first_message'] ?? '');
        if ($phone) {
            $conv  = getOrCreateConversation($phone, $name);
            if ($msg) addWAMessage((int)$conv['id'], 'guest', $msg, ['is_inquiry' => isBookingInquiry($msg) ? 1 : 0]);
        }
        header('Location: admin.php?section=wa_inbox&conv=' . ($conv['id'] ?? '') . '&flash=Conversation+created'); exit;
    }

    if ($act === 'block_date') {
        $rid = $_POST['room_id'];
        $data = [
            'room_id'   => $rid,
            'room_name' => ROOM_IDS[$rid] ?? $rid,
            'check_in'  => $_POST['check_in'],
            'check_out' => $_POST['check_out'],
            'guest_name'=> 'Blocked',
            'source'    => 'blocked',
            'status'    => 'confirmed',
            'amount'    => 0,
        ];
        try {
            createConfirmedBooking($data);
        } catch (Throwable $e) {
            header('Location: admin.php?section=calendar&view=month&flash=' . urlencode($e->getMessage())); exit;
        }
        header('Location: admin.php?section=calendar&view=month&flash=Date+blocked+across+all+channels'); exit;
    }

    if ($act === 'edit_booking') {
        $id = (int)($_POST['id'] ?? 0);
        $returnSec = trim((string)($_POST['return_section'] ?? 'bookings'));
        if (!in_array($returnSec, ['bookings', 'calendar', 'overview', 'day', 'week', 'month', 'blocked'], true)) {
            $returnSec = 'bookings';
        }
        if (!$id) {
            header('Location: admin.php?section=' . $returnSec . '&flash=' . urlencode('Invalid booking ID.'));
            exit;
        }
        $rid = trim((string)($_POST['room_id'] ?? ''));
        $total = max(0.0, (float)($_POST['amount'] ?? 0));
        $paid = max(0.0, (float)($_POST['amount_paid'] ?? 0));
        $pmeth = trim((string)($_POST['payment_method'] ?? 'cash'));
        $pstatus = $paid >= $total && $total > 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
        $status = trim((string)($_POST['status'] ?? 'confirmed'));
        $data = [
            'room_id'         => $rid,
            'room_name'       => ROOM_IDS[$rid] ?? $rid,
            'check_in'        => trim((string)($_POST['check_in'] ?? '')),
            'check_out'       => trim((string)($_POST['check_out'] ?? '')),
            'guest_name'      => trim((string)($_POST['guest_name'] ?? 'Guest')),
            'guest_email'     => trim((string)($_POST['guest_email'] ?? '')),
            'guest_phone'     => trim((string)($_POST['guest_phone'] ?? '')),
            'whatsapp_number' => trim((string)($_POST['whatsapp_number'] ?? $_POST['guest_phone'] ?? '')),
            'source'          => trim((string)($_POST['source'] ?? 'direct')),
            'booking_ref'     => trim((string)($_POST['booking_ref'] ?? '')),
            'amount'          => $total,
            'amount_paid'     => $paid,
            'payment_method'  => $pmeth,
            'payment_status'  => $pstatus,
            'status'          => $status,
            'notes'           => trim((string)($_POST['notes'] ?? '')),
        ];
        try {
            updateConfirmedBooking($id, $data);
        } catch (Throwable $e) {
            header('Location: admin.php?section=' . $returnSec . '&flash=' . urlencode($e->getMessage()));
            exit;
        }
        header('Location: admin.php?section=' . $returnSec . '&flash=Booking+updated+successfully');
        exit;
    }

    if ($act === 'delete_booking') {
        $id = (int)($_POST['id'] ?? 0);
        $returnSec = trim((string)($_POST['return_section'] ?? 'bookings'));
        if (!in_array($returnSec, ['bookings', 'calendar', 'overview', 'day', 'week', 'month', 'blocked'], true)) {
            $returnSec = 'bookings';
        }
        if ($id > 0) deleteBooking($id);
        header('Location: admin.php?section=' . $returnSec . '&flash=Booking+deleted');
        exit;
    }

    if ($act === 'cancel_booking') {
        $id = (int)($_POST['id'] ?? 0);
        $returnSec = trim((string)($_POST['return_section'] ?? 'bookings'));
        if (!in_array($returnSec, ['bookings', 'calendar', 'overview', 'day', 'week', 'month', 'blocked'], true)) {
            $returnSec = 'bookings';
        }
        if ($id > 0) cancelBooking($id);
        header('Location: admin.php?section=' . $returnSec . '&flash=Booking+cancelled');
        exit;
    }

    if ($act === 'add_calendar') {
        $roomId = trim((string)($_POST['room_id'] ?? ''));
        $platform = strtolower(trim((string)($_POST['platform'] ?? '')));
        $url = trim((string)($_POST['ical_url'] ?? ''));
        if (!isValidRoomId($roomId) || !in_array($platform, SUPPORTED_ICAL_PLATFORMS, true) || !isSafeCalendarUrl($url)) {
            header('Location: admin.php?section=channels&flash=Invalid+calendar+details'); exit;
        }
        addExternalCalendar($roomId, $platform, $url);
        header('Location: admin.php?section=channels&flash=Calendar+added'); exit;
    }
    if ($act === 'delete_calendar') { deleteExternalCalendar((int)$_POST['id']); header('Location: admin.php?section=channels&flash=Removed'); exit; }

    if ($act === 'bulk_add_calendars') {
        $platform = strtolower(trim($_POST['platform'] ?? ''));
        $urls     = $_POST['urls'] ?? [];
        $added    = 0;
        foreach ($urls as $roomId => $url) {
            $url = trim($url);
            if (!$url || !isSafeCalendarUrl($url)) continue;
            if (!isValidRoomId($roomId) || !in_array($platform, SUPPORTED_ICAL_PLATFORMS, true)) continue;
            addExternalCalendar($roomId, $platform, $url);
            $added++;
        }
        header('Location: admin.php?section=channels&flash=' . urlencode("Connected {$added} {$platform} channel(s). Click Sync Now to import bookings.")); exit;
    }

    if ($act === 'update_base_rate') {
        foreach ($_POST['rates'] as $rid => $price) {
            upsertRoomRate($rid, (float)$price);
        }
        // Also save platform rates if submitted together
        foreach ($_POST['platform_rates'] ?? [] as $rid => $platforms) {
            foreach ($platforms as $platform => $rate) {
                if ($rate !== '') upsertPlatformRate($rid, $platform, (float)$rate);
            }
        }
        header('Location: admin.php?section=pricing&flash=Rates+updated'); exit;
    }

    if ($act === 'update_platform_rates') {
        foreach ($_POST['platform_rates'] ?? [] as $rid => $platforms) {
            foreach ($platforms as $platform => $rate) {
                if ($rate !== '') upsertPlatformRate($rid, $platform, (float)$rate);
            }
        }
        header('Location: admin.php?section=pricing&flash=Platform+rates+saved'); exit;
    }

    if ($act === 'save_discount_rules') {
        $types    = $_POST['rule_type']   ?? [];
        $labels   = $_POST['rule_label']  ?? [];
        $values   = $_POST['rule_value']  ?? [];
        $units    = $_POST['rule_unit']   ?? [];
        $mins     = $_POST['rule_min']    ?? [];
        $aheads   = $_POST['rule_ahead']  ?? [];
        $enableds = $_POST['rule_enabled'] ?? [];
        foreach ($types as $i => $rtype) {
            upsertDiscountRule(
                '__all__', $rtype,
                $labels[$i]   ?? $rtype,
                (float)($values[$i] ?? 0),
                $units[$i]    ?? 'pct',
                (int)($mins[$i]   ?? 1),
                (int)($aheads[$i] ?? 0),
                isset($enableds[$i]) ? 1 : 0
            );
        }
        header('Location: admin.php?section=pricing&flash=Discount+rules+saved'); exit;
    }

    if ($act === 'approve_suggestion') {
        $approvedPrice = (float)($_POST['approved_price'] ?? $_POST['suggested_price']);
        approveSuggestion((int)$_POST['id'], $approvedPrice, trim($_POST['notes'] ?? ''));
        header('Location: admin.php?section=pricing&flash=Suggestion+approved'); exit;
    }

    if ($act === 'dismiss_suggestion') {
        dismissSuggestion((int)$_POST['id'], trim($_POST['notes'] ?? ''));
        header('Location: admin.php?section=pricing&flash=Suggestion+dismissed'); exit;
    }

    if ($act === 'approve_all_suggestions') {
        $pending = getPricingSuggestions('pending');
        foreach ($pending as $s) approveSuggestion((int)$s['id'], (float)$s['suggested_price'], 'Bulk approved');
        header('Location: admin.php?section=pricing&flash=' . count($pending) . '+suggestions+approved'); exit;
    }

    if ($act === 'generate_suggestions') {
        $seeded = seedDemandEvents();
        $n = generatePricingSuggestions();
        header('Location: admin.php?section=pricing&flash=Generated+' . $n . '+new+suggestions'); exit;
    }

    if ($act === 'reseed_events') {
        $n = seedDemandEvents();
        header('Location: admin.php?section=pricing&flash=Re-seeded+' . $n . '+demand+events'); exit;
    }
}

// ── Data ──────────────────────────────────────────────────────
$section = $_GET['section'] ?? 'dashboard';
$flash   = htmlspecialchars($_GET['flash'] ?? '');
$rooms   = ROOM_IDS;

// Day-view date (defaults to today)
$dayDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayDate)) $dayDate = date('Y-m-d');

// Unified calendar view tab (day | week | month | year)
if ($section === 'day')     $calView = 'day';
elseif ($section === 'week') $calView = 'week';
elseif ($section === 'overview') $calView = 'month';
else $calView = $_GET['view'] ?? 'day';

if (!empty($_SESSION['admin_logged_in'])) {
    $allBookings  = getAllBookings();
    $extCals      = getExternalCalendars();
    $waUnread     = getWAUnreadTotal();
    $waConvs      = getWAConversations();

    $confirmedBookings = array_values(array_filter($allBookings, fn($b) => $b['status'] === 'confirmed'));
    $confirmed = expandCalendarEntriesToRelatedInventory(array_merge($confirmedBookings, getExternalBlockCalendarEntries()));
    $upcoming   = array_filter($confirmedBookings, fn($b) => $b['check_out'] >= date('Y-m-d'));
    $thisMonth  = array_filter($confirmedBookings, fn($b) => substr($b['check_in'],0,7) === date('Y-m'));

    $totalRev   = array_sum(array_column(
        array_filter($thisMonth, fn($b) => in_array($b['source'], ['direct','razorpay'])),
        'amount'
    ));

    $totalNights = 0;
    foreach ($confirmedBookings as $b) {
        $n = max(0, (int)ceil((strtotime($b['check_out']) - strtotime($b['check_in'])) / 86400));
        $totalNights += $n;
    }
    $occupancy = count($rooms) > 0 ? min(100, round($totalNights / (count($rooms) * 30) * 100)) : 0;

    $byPlatform = [];
    foreach ($confirmedBookings as $b) {
        $src = $b['source'] ?? 'direct';
        $byPlatform[$src] = ($byPlatform[$src] ?? 0) + 1;
    }
    arsort($byPlatform);

    $nextWeek  = date('Y-m-d', strtotime('+7 days'));
    $arrivals  = array_values(array_filter($upcoming, fn($b) => empty($b['is_external_block']) && $b['check_in'] <= $nextWeek && $b['check_in'] >= date('Y-m-d')));
    usort($arrivals, fn($a,$b) => strcmp($a['check_in'], $b['check_in']));
    $departures = array_values(array_filter($upcoming, fn($b) => empty($b['is_external_block']) && $b['check_out'] <= $nextWeek && $b['check_out'] >= date('Y-m-d')));
    usort($departures, fn($a,$b) => strcmp($a['check_out'], $b['check_out']));

    // Gantt 60 days
    $ganttDays  = 60;
    $ganttStart = new DateTime('today');
    $ganttDates = [];
    for ($i = 0; $i < $ganttDays; $i++) {
        $d = clone $ganttStart; $d->modify("+{$i} days");
        $ganttDates[] = $d->format('Y-m-d');
    }
    $bookingsByRoom = [];
    foreach ($rooms as $rid => $_) $bookingsByRoom[$rid] = [];
    foreach ($confirmed as $b) {
        if (isset($bookingsByRoom[$b['room_id']])) $bookingsByRoom[$b['room_id']][] = $b;
    }

    // Demand events indexed by date
    $demandByDate = getDemandEventsByDate();

    // iCal export URLs
    $icalUrls = [];
    foreach (SUPPORTED_ICAL_PLATFORMS as $destination) {
        foreach ($rooms as $rid => $_) {
            $icalUrls[$destination][$rid] = SITE_URL . '/channel-manager/ical-export.php?room=' . urlencode($rid)
                . '&destination=' . urlencode($destination) . '&token=' . urlencode(ICAL_TOKEN);
        }
    }

    $syncTimes = array_values(array_filter(array_column($extCals, 'last_synced')));
    $lastAutoSync = $syncTimes === [] ? '' : max($syncTimes);

    // Pending suggestions count for badge
    $pendingSuggestions = getPricingSuggestions('pending');
    $pendingCount = count($pendingSuggestions);

    // Room rates map
    $ratesMap = [];
    foreach (getRoomRates() as $r) $ratesMap[$r['room_id']] = $r['base_price'];

    // Platform rates map  [room_id][platform] => rate
    $platformRates  = getPlatformRates();
    // Discount rules
    $discountRules  = [];
    foreach (getDiscountRules() as $dr) $discountRules[$dr['rule_type']] = $dr;

    // Revenue data
    $revenueMonthly = getRevenueByPeriod('monthly');
    $projection     = getRevenueProjection();
}

// ── Helpers ───────────────────────────────────────────────────
function sourceColor(string $s): string {
    return match(strtolower(trim($s))) {
        'airbnb'                 => '#FF5A5F',
        'booking.com','booking'  => '#003580',
        'agoda'                  => '#EB1A23',
        'makemytrip'             => '#E8262D',
        'direct','razorpay'      => '#2e7d32',
        'manual'                 => '#6d4c41',
        'blocked'                => '#e53e3e',
        default                  => '#546e7a',
    };
}
function sourceName(string $s): string {
    return match(strtolower(trim($s))) {
        'booking.com','booking'  => 'Booking.com',
        'airbnb'                 => 'Airbnb',
        'agoda'                  => 'Agoda',
        'makemytrip'             => 'MakeMyTrip',
        'direct'                 => 'Direct',
        'razorpay'               => 'Direct (Razorpay)',
        'manual'                 => 'Manual / Phone',
        'blocked'                => 'Blocked',
        default                  => ucfirst($s),
    };
}
function badge(string $s): string {
    $c = sourceColor($s);
    return "<span class='badge' style='background:$c'>".htmlspecialchars(sourceName($s))."</span>";
}
function nights(string $ci, string $co): int {
    return max(0, (int)ceil((strtotime($co) - strtotime($ci)) / 86400));
}
function bookingOnDay(array $bookings, string $date): ?array {
    foreach ($bookings as $b) {
        if ($date >= $b['check_in'] && $date < $b['check_out']) return $b;
    }
    return null;
}
function demandIcon(string $level): string {
    return match($level) {
        'gold'   => '🥇',
        'high'   => '🔥',
        'medium' => '📈',
        default  => '⭐',
    };
}
function demandBadgeStyle(string $level): string {
    return match($level) {
        'gold'   => 'background:#f59e0b;color:#fff',
        'high'   => 'background:#ef4444;color:#fff',
        'medium' => 'background:#3b82f6;color:#fff',
        default  => 'background:#6b7280;color:#fff',
    };
}
function fmt(float $n): string { return '₹' . number_format($n, 0); }
function bookingJson(array $b): string {
    $bTotal   = (float)($b['amount']      ?? 0);
    $bPaid    = (float)($b['amount_paid'] ?? 0);
    $bBalance = max(0, $bTotal - $bPaid);
    $pstatus  = $bBalance <= 0 && $bTotal > 0 ? 'paid' : ($bPaid > 0 ? 'partial' : 'unpaid');
    return htmlspecialchars(json_encode([
        'id'              => (int)($b['id'] ?? 0),
        'room_id'         => (string)($b['room_id'] ?? ''),
        'room_name'       => (string)($b['room_name'] ?? (ROOM_IDS[$b['room_id'] ?? ''] ?? '')),
        'check_in'        => (string)($b['check_in'] ?? ''),
        'check_out'       => (string)($b['check_out'] ?? ''),
        'guest_name'      => (string)($b['guest_name'] ?? 'Guest'),
        'guest_phone'     => (string)($b['guest_phone'] ?? ''),
        'whatsapp_number' => (string)($b['whatsapp_number'] ?? ($b['guest_phone'] ?? '')),
        'guest_email'     => (string)($b['guest_email'] ?? ''),
        'source'          => (string)($b['source'] ?? 'direct'),
        'booking_ref'     => (string)($b['booking_ref'] ?? ''),
        'amount'          => $bTotal,
        'amount_paid'     => $bPaid,
        'payment_method'  => (string)($b['payment_method'] ?? 'cash'),
        'payment_status'  => (string)($b['payment_status'] ?? $pstatus),
        'status'          => (string)($b['status'] ?? 'confirmed'),
        'notes'           => (string)($b['notes'] ?? ''),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}
function commissionForSource(string $src): float {
    $c = OTA_COMMISSIONS;
    return ($c[strtolower($src)] ?? 0) / 100;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>PMS Dashboard — Kanchi Farm Stay</title>
<!-- PWA -->
<link rel="manifest" href="admin-manifest.json">
<meta name="theme-color" content="#4a7c59">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="KFS Admin">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<style>
:root {
    --sidebar-bg: #1a2e1a;
    --sidebar-text: #c8dfc8;
    --sidebar-active: #4a7c59;
    --primary: #4a7c59;
    --primary-dark: #2e5c3a;
    --bg: #f0f4f0;
    --card: #ffffff;
    --border: #e2e8e2;
    --text: #1a2e1a;
    --text-muted: #5a7060;
    --danger: #c62828;
    --warn: #e65100;
    --info: #01579b;
    --gold: #f59e0b;
    --high: #ef4444;
    --medium: #3b82f6;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
a { color: inherit; text-decoration: none; }

/* Login */
.login-page { min-height:100vh; display:flex; align-items:center; justify-content:center; background: linear-gradient(135deg,#1a2e1a 0%,#2e5c3a 100%); }
.login-card { background:#fff; border-radius:16px; padding:2.5rem 2.25rem; width:360px; box-shadow:0 20px 60px rgba(0,0,0,.25); }
.login-logo { text-align:center; margin-bottom:1.75rem; }
.login-logo .icon { font-size:2.5rem; display:block; }
.login-logo h1 { font-size:1.3rem; font-weight:700; color:var(--text); margin-top:.4rem; }
.login-logo p  { font-size:.82rem; color:var(--text-muted); }
.login-card label { font-size:.8rem; font-weight:600; color:var(--text-muted); display:block; margin-bottom:.35rem; }
.login-card input[type=password] { width:100%; padding:.75rem 1rem; border:1.5px solid var(--border); border-radius:8px; font-size:1rem; margin-bottom:1rem; }
.login-card input:focus { outline:none; border-color:var(--primary); }
.login-err { background:#fdecea; color:#c62828; border-radius:7px; padding:.5rem .85rem; font-size:.83rem; margin-bottom:.85rem; }
.btn-login { width:100%; padding:.8rem; background:var(--primary); color:#fff; border:none; border-radius:8px; font-size:1rem; font-weight:600; cursor:pointer; }
.btn-login:hover { background:var(--primary-dark); }

/* Layout */
.layout { display:flex; min-height:100vh; }
.sidebar { width:240px; background:var(--sidebar-bg); color:var(--sidebar-text); display:flex; flex-direction:column; flex-shrink:0; }
.sidebar-brand { padding:1.5rem 1.25rem 1rem; border-bottom:1px solid rgba(255,255,255,.08); }
.sidebar-brand .name { font-size:1rem; font-weight:700; color:#fff; }
.sidebar-brand .sub  { font-size:.72rem; color:#8ab898; margin-top:.15rem; }
.sidebar-nav { padding:1rem 0; flex:1; }
.nav-item { display:flex; align-items:center; gap:.75rem; padding:.7rem 1.25rem; font-size:.88rem; font-weight:500; color:var(--sidebar-text); cursor:pointer; border-left:3px solid transparent; transition:all .15s; position:relative; }
.nav-item:hover { background:rgba(255,255,255,.06); color:#fff; }
.nav-item.active { background:rgba(74,124,89,.25); border-left-color:#6abf85; color:#fff; }
.nav-icon { font-size:1.1rem; width:22px; text-align:center; }
.nav-badge { background:#ef4444; color:#fff; font-size:.65rem; font-weight:700; border-radius:99px; padding:.1rem .4rem; margin-left:auto; }
.sidebar-bottom { padding:1rem 1.25rem; border-top:1px solid rgba(255,255,255,.08); font-size:.8rem; }
.sidebar-bottom a { color:#8ab898; }
.sidebar-bottom a:hover { color:#fff; }

.main { flex:1; display:flex; flex-direction:column; overflow:hidden; }
.topbar { background:var(--card); border-bottom:1px solid var(--border); padding:.85rem 1.75rem; display:flex; align-items:center; justify-content:space-between; }
.topbar-title { font-size:1.05rem; font-weight:700; }
.topbar-right { display:flex; align-items:center; gap:1rem; font-size:.83rem; color:var(--text-muted); }
.sync-btn { background:var(--info); color:#fff; border:none; border-radius:7px; padding:.4rem 1rem; font-size:.82rem; font-weight:600; cursor:pointer; }
.sync-btn:hover { opacity:.85; }
.content { flex:1; overflow-y:auto; padding:1.5rem 1.75rem; }
.flash { background:#e8f5e9; color:#1b5e20; border:1px solid #a5d6a7; border-radius:8px; padding:.65rem 1rem; margin-bottom:1.25rem; font-size:.88rem; font-weight:500; }

/* Toast notifications */
.toast-container { position:fixed; top:18px; right:18px; z-index:10000; display:flex; flex-direction:column; gap:.65rem; width:min(390px,calc(100vw - 32px)); pointer-events:none; }
.toast { --toast-color:var(--info); display:grid; grid-template-columns:auto 1fr auto; gap:.7rem; align-items:start; background:#fff; border:1px solid var(--border); border-left:4px solid var(--toast-color); border-radius:10px; padding:.8rem .85rem; box-shadow:0 12px 32px rgba(20,45,25,.18); pointer-events:auto; animation:toastIn .22s ease-out; }
.toast.toast-success { --toast-color:#2e7d32; }
.toast.toast-warning { --toast-color:#d97706; }
.toast.toast-error { --toast-color:#c62828; }
.toast.toast-info { --toast-color:#01579b; }
.toast-icon { width:25px; height:25px; border-radius:50%; display:grid; place-items:center; background:color-mix(in srgb,var(--toast-color) 12%,white); color:var(--toast-color); font-size:.78rem; font-weight:800; }
.toast-title { color:var(--text); font-size:.86rem; font-weight:750; line-height:1.25; }
.toast-message { color:var(--text-muted); font-size:.78rem; line-height:1.4; margin-top:.15rem; }
.toast-close { border:0; background:none; color:#829087; cursor:pointer; font-size:1rem; line-height:1; padding:.15rem; }
.toast-close:hover { color:var(--text); }
.toast.is-leaving { animation:toastOut .18s ease-in forwards; }
@keyframes toastIn { from { opacity:0; transform:translateX(18px); } to { opacity:1; transform:translateX(0); } }
@keyframes toastOut { from { opacity:1; transform:translateX(0); } to { opacity:0; transform:translateX(18px); } }
@media (max-width:600px) { .toast-container { top:12px; right:16px; } }
@media (prefers-reduced-motion:reduce) { .toast,.toast.is-leaving { animation:none; } }

/* Stat cards */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.5rem; }
.stat-card { background:var(--card); border-radius:12px; padding:1.25rem 1.35rem; box-shadow:0 1px 4px rgba(0,0,0,.06); border:1px solid var(--border); }
.stat-card .stat-icon { font-size:1.6rem; margin-bottom:.6rem; }
.stat-card .stat-val  { font-size:1.8rem; font-weight:800; color:var(--primary-dark); line-height:1; }
.stat-card .stat-lbl  { font-size:.75rem; color:var(--text-muted); margin-top:.35rem; font-weight:500; }
.stat-card .stat-sub  { font-size:.72rem; color:var(--text-muted); margin-top:.2rem; }
.stat-card a.stat-link { text-decoration:none; display:block; }
.stat-card a.stat-link .stat-val { transition:color .15s; }
.stat-card a.stat-link:hover .stat-val { color:var(--accent); text-decoration:underline; text-underline-offset:4px; }
.stat-card a.stat-link:hover { background:none; }
.stat-card .stat-arrow { font-size:.7rem; color:var(--accent); opacity:0; margin-left:4px; transition:opacity .15s; }
.stat-card a.stat-link:hover .stat-arrow { opacity:1; }

/* Panels */
.panel { background:var(--card); border-radius:12px; border:1px solid var(--border); box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:1.25rem; overflow:hidden; }
.panel-hd { padding:.9rem 1.35rem; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
.panel-hd h3 { font-size:.92rem; font-weight:700; }
.panel-hd .sub { font-size:.78rem; color:var(--text-muted); margin-top:.1rem; }
.panel-bd { padding:1.25rem 1.35rem; }

/* Tables */
.tbl-wrap { overflow-x:auto; }
table.tbl { width:100%; border-collapse:collapse; font-size:.85rem; }
.tbl th { background:#f7faf7; font-size:.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.04em; padding:.6rem 1rem; border-bottom:1px solid var(--border); text-align:left; white-space:nowrap; }
.tbl td { padding:.65rem 1rem; border-bottom:1px solid #f3f6f3; vertical-align:middle; }
.tbl tr:last-child td { border-bottom:none; }
.tbl tr:hover td { background:#f7faf7; }
.tbl .muted { color:var(--text-muted); font-size:.8rem; }

/* Badges */
.badge { display:inline-block; padding:.2rem .65rem; border-radius:20px; font-size:.73rem; font-weight:600; color:#fff; white-space:nowrap; }
.status-confirmed { color:#1b5e20; background:#e8f5e9; padding:.2rem .6rem; border-radius:12px; font-size:.75rem; font-weight:600; }
.status-cancelled { color:#b71c1c; background:#fdecea; padding:.2rem .6rem; border-radius:12px; font-size:.75rem; font-weight:600; }

/* Buttons */
.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.45rem 1rem; border-radius:7px; border:none; font-size:.83rem; font-weight:600; cursor:pointer; transition:opacity .15s; }
.btn:hover { opacity:.85; }
.btn-primary { background:var(--primary); color:#fff; }
.btn-success { background:#16a34a; color:#fff; }
.btn-danger  { background:#c62828; color:#fff; padding:.3rem .75rem; font-size:.78rem; }
.btn-warn    { background:#e65100; color:#fff; padding:.3rem .75rem; font-size:.78rem; }
.btn-grey    { background:#607d8b; color:#fff; padding:.3rem .75rem; font-size:.78rem; }
.btn-copy    { background:var(--primary); color:#fff; padding:.35rem .9rem; }
.btn-gold    { background:var(--gold); color:#fff; }
.btn-sm      { padding:.25rem .6rem; font-size:.76rem; }

/* Forms */
.form-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(185px,1fr)); gap:.8rem; }
.form-grid.wide-last > :last-child { grid-column:1/-1; }
.fld label { font-size:.76rem; font-weight:600; color:var(--text-muted); display:block; margin-bottom:.3rem; }
.fld input, .fld select, .fld textarea { width:100%; padding:.55rem .85rem; border:1.5px solid var(--border); border-radius:7px; font-size:.86rem; color:var(--text); background:#fff; }
.fld input:focus, .fld select:focus, .fld textarea:focus { outline:none; border-color:var(--primary); }
.fld textarea { resize:vertical; min-height:56px; }

/* Dashboard grid */
.dash-grid { display:grid; grid-template-columns:1fr 320px; gap:1.25rem; }
@media(max-width:900px) { .dash-grid { grid-template-columns:1fr; } .stats-row { grid-template-columns:repeat(2,1fr); } }

/* Platform breakdown */
.platform-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:.75rem; }
.platform-card { border-radius:10px; padding:.9rem 1rem; color:#fff; }
.platform-card .pc-count { font-size:1.8rem; font-weight:800; line-height:1; }
.platform-card .pc-name  { font-size:.75rem; font-weight:500; margin-top:.3rem; opacity:.9; }

/* Arrivals */
.arrival-list { display:flex; flex-direction:column; gap:.6rem; }
.arrival-item { display:flex; align-items:center; gap:.85rem; padding:.65rem .9rem; background:#f7faf7; border-radius:8px; border:1px solid var(--border); }
.arrival-date { font-size:.72rem; font-weight:700; color:var(--primary-dark); background:#d7eedd; padding:.25rem .5rem; border-radius:5px; white-space:nowrap; }
.arrival-info { flex:1; min-width:0; }
.arrival-name { font-size:.88rem; font-weight:600; }
.arrival-sub  { font-size:.76rem; color:var(--text-muted); }

/* Week at a glance */
.week-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:.6rem; margin-bottom:1.25rem; }
.week-day { background:var(--card); border-radius:10px; border:1px solid var(--border); padding:.75rem .6rem; min-height:120px; position:relative; }
.week-day.today { border-color:var(--primary); background:#f0faf3; }
.week-day.has-demand { border-color:var(--gold); }
.week-day .wd-hdr { text-align:center; margin-bottom:.5rem; }
.week-day .wd-dow { font-size:.7rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; }
.week-day .wd-date { font-size:1.1rem; font-weight:800; color:var(--text); }
.week-day.today .wd-date { color:var(--primary); }
.week-event { font-size:.67rem; padding:.15rem .4rem; border-radius:4px; margin-top:.25rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.week-checkin  { background:#e8f5e9; color:#1b5e20; }
.week-checkout { background:#fce4ec; color:#880e4f; }
.week-stay     { background:#e3f2fd; color:#0d47a1; }
.week-demand   { font-size:.66rem; padding:.1rem .35rem; border-radius:4px; margin-top:.2rem; }

/* Gantt */
.gantt-wrap { overflow-x:auto; border-radius:10px; border:1px solid var(--border); }
.gantt-table { border-collapse:collapse; min-width:100%; font-size:.8rem; }
.gantt-room-col { width:150px; min-width:150px; }
.gantt-day-col  { min-width:36px; width:36px; }
.gantt-table thead th { background:#f7faf7; border-bottom:1px solid var(--border); border-right:1px solid #eef2ee; padding:0; }
.gantt-hdr-room { padding:.6rem .9rem; text-align:left; font-size:.76rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; }
.gantt-hdr-day  { padding:.35rem .1rem; text-align:center; }
.gantt-hdr-day .d-num { font-size:.78rem; font-weight:700; color:var(--text); display:block; }
.gantt-hdr-day .d-dow { font-size:.62rem; color:var(--text-muted); display:block; }
.gantt-hdr-day .d-dem { font-size:.6rem; display:block; line-height:1; }
.gantt-hdr-day.today-col { background:#e8f5e9; }
.gantt-hdr-day.today-col .d-num { color:var(--primary-dark); }
.gantt-hdr-day.weekend-col { background:#fafbfa; }
.gantt-hdr-day.demand-gold { background:#fef3c7; }
.gantt-hdr-day.demand-high { background:#fee2e2; }
.gantt-hdr-day.demand-medium { background:#dbeafe; }
.gantt-table tbody td { border-right:1px solid #eef2ee; border-bottom:1px solid #eef2ee; padding:0; height:44px; }
.gantt-room-label { padding:.5rem .9rem; font-size:.82rem; font-weight:700; white-space:nowrap; background:#fafbfa; color:var(--text); position:sticky; left:0; z-index:2; border-right:2px solid var(--border); }
.gantt-day-free { background:#fff; cursor:pointer; }
.gantt-day-free:hover { background:#e8f5e9; }
.gantt-day-free.today-day { background:#f0faf3; }
.gantt-day-free.weekend-day { background:#fafbfa; }
.gantt-day-free.demand-gold { background:#fffbeb; }
.gantt-day-free.demand-high { background:#fff5f5; }
.gantt-booking { padding:0 .5rem; vertical-align:middle; }
.gantt-bk-inner { height:28px; border-radius:4px; display:flex; align-items:center; padding:0 .5rem; overflow:hidden; white-space:nowrap; }
.gantt-bk-inner .bk-guest { font-size:.72rem; font-weight:600; color:rgba(255,255,255,.95); overflow:hidden; text-overflow:ellipsis; }
.gantt-bk-inner .bk-src   { font-size:.62rem; color:rgba(255,255,255,.75); margin-left:.35rem; }
.gantt-day-past { background:#f8f8f8; }

/* Legend */
.gantt-legend { display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:.85rem; }
.gl-item { display:flex; align-items:center; gap:.35rem; font-size:.76rem; color:var(--text-muted); }
.gl-dot { width:12px; height:12px; border-radius:3px; }

/* iCal */
.ical-list { display:flex; flex-direction:column; gap:.85rem; }
.ical-row  { display:flex; align-items:center; gap:.85rem; flex-wrap:wrap; }
.ical-room-lbl { font-size:.83rem; font-weight:700; min-width:155px; }
.ical-url-box { flex:1; font-size:.76rem; background:#f7faf7; border:1px solid var(--border); border-radius:7px; padding:.45rem .8rem; color:var(--text-muted); word-break:break-all; font-family:monospace; }

/* Search */
.search-bar { display:flex; align-items:center; gap:.75rem; margin-bottom:1rem; flex-wrap:wrap; }
.search-bar input, .search-bar select { padding:.5rem .85rem; border:1.5px solid var(--border); border-radius:7px; font-size:.85rem; }
.search-bar input { min-width:220px; }

/* Channels */
.status-synced { color:#1b5e20; font-size:.78rem; }
.status-never  { color:var(--warn); font-size:.78rem; }

/* How-to */
.howto { background:#fffde7; border:1px solid #ffe082; border-radius:10px; padding:1.1rem 1.25rem; font-size:.85rem; line-height:1.7; }
.howto h4 { font-size:.9rem; margin-bottom:.5rem; }
.howto code { background:#fff8e1; border:1px solid #ffe082; border-radius:4px; padding:.1rem .4rem; font-size:.82rem; }

/* Pricing suggestion cards */
.suggestion-card { border:1px solid var(--border); border-radius:10px; padding:1rem 1.1rem; margin-bottom:.75rem; background:#fff; display:flex; align-items:flex-start; gap:1rem; }
.suggestion-card.gold-card { border-color:var(--gold); background:#fffbeb; }
.suggestion-card.high-card { border-color:#fca5a5; background:#fff5f5; }
.sc-badge { padding:.3rem .6rem; border-radius:6px; font-size:.75rem; font-weight:700; white-space:nowrap; flex-shrink:0; }
.sc-body { flex:1; min-width:0; }
.sc-reason { font-size:.85rem; font-weight:600; margin-bottom:.3rem; }
.sc-meta   { font-size:.78rem; color:var(--text-muted); margin-bottom:.6rem; }
.sc-price-row { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
.sc-price-old { font-size:.82rem; color:var(--text-muted); text-decoration:line-through; }
.sc-price-new { font-size:1rem; font-weight:700; color:var(--primary-dark); }
.sc-pct { font-size:.78rem; color:#16a34a; font-weight:600; }
.sc-actions { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }

/* Analytics */
.analytics-tabs { display:flex; gap:.5rem; margin-bottom:1rem; }
.atab { padding:.4rem .9rem; border-radius:7px; border:1.5px solid var(--border); background:#fff; font-size:.82rem; font-weight:600; cursor:pointer; color:var(--text-muted); }
.atab.active { background:var(--primary); color:#fff; border-color:var(--primary); }
.chart-wrap { position:relative; height:260px; }
.proj-card { background:var(--card); border-radius:10px; border:1px solid var(--border); padding:1rem 1.2rem; }
.proj-row { display:flex; justify-content:space-between; align-items:center; padding:.45rem 0; border-bottom:1px solid #f3f6f3; font-size:.88rem; }
.proj-row:last-child { border-bottom:none; font-weight:700; }
.proj-lbl { color:var(--text-muted); }
.proj-val { font-weight:600; color:var(--text); }
.proj-total { color:var(--primary-dark); font-size:1.05rem; }
.demand-events-list { display:flex; flex-direction:column; gap:.5rem; max-height:320px; overflow-y:auto; }
.de-item { display:flex; align-items:center; gap:.6rem; padding:.45rem .7rem; background:#f7faf7; border-radius:7px; font-size:.82rem; }
.de-date { font-weight:700; min-width:75px; color:var(--text); }
.de-name { flex:1; }

/* Room occupancy bars */
.occ-row { display:flex; align-items:center; gap:.85rem; margin-bottom:.6rem; }
.occ-lbl { font-size:.8rem; font-weight:600; min-width:115px; }
.occ-bar-wrap { flex:1; background:#e8f0e8; border-radius:99px; height:8px; }
.occ-bar { background:var(--primary); border-radius:99px; height:8px; }
.occ-pct { font-size:.78rem; color:var(--text-muted); min-width:36px; text-align:right; }

/* Mini calendar */
.mini-cal { width:100%; border-collapse:collapse; font-size:.82rem; }
.mini-cal th { text-align:center; font-size:.72rem; font-weight:700; color:var(--text-muted); padding:.35rem 0; }
.mini-cal td { text-align:center; padding:.25rem; }
.mini-cal td .dc { width:30px; height:30px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-weight:500; font-size:.82rem; transition:all .15s; position:relative; }
.mini-cal td .dc:hover { background:var(--primary); color:#fff; }
.mini-cal td .dc.today { background:var(--primary); color:#fff; font-weight:700; }
.mini-cal td .dc.has-booking { font-weight:700; color:var(--primary-dark); }
.mini-cal td .dc.has-booking::after { content:''; width:5px; height:5px; background:var(--primary); border-radius:50%; position:absolute; bottom:1px; left:50%; transform:translateX(-50%); }
.mini-cal td .dc.has-booking.today::after { background:#fff; }
.mini-cal td .dc.is-demand { background:#fef3c7; color:#92400e; }
.mini-cal td .dc.is-demand:hover { background:var(--gold); color:#fff; }
.mini-cal td .dc.other-month { color:#ccc; }
.mini-cal td .dc.selected { outline:2px solid var(--primary); outline-offset:2px; }
.cal-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:.6rem; }
.cal-nav .cal-title { font-size:.88rem; font-weight:700; }
.cal-nav button { background:none; border:1px solid var(--border); border-radius:6px; padding:.2rem .5rem; cursor:pointer; font-size:.85rem; color:var(--text); }
.cal-nav button:hover { background:var(--primary); color:#fff; border-color:var(--primary); }

/* Day view */
.day-nav { display:flex; align-items:center; gap:.75rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.day-nav .day-lbl { font-size:1.1rem; font-weight:800; color:var(--text); }
.day-nav a.day-btn { background:var(--card); border:1.5px solid var(--border); border-radius:8px; padding:.35rem .8rem; font-size:.82rem; font-weight:600; color:var(--text); display:inline-flex; align-items:center; gap:.3rem; }
.day-nav a.day-btn:hover { background:var(--primary); color:#fff; border-color:var(--primary); }
.day-nav .today-pill { background:var(--primary); color:#fff; border-radius:20px; padding:.25rem .75rem; font-size:.75rem; font-weight:700; }
.day-section { margin-bottom:1.25rem; }
.day-section-hd { font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin-bottom:.5rem; padding:.4rem .6rem; background:#f7faf7; border-radius:6px; border-left:3px solid var(--primary); }
.guest-card { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:1rem 1.1rem; margin-bottom:.6rem; display:flex; gap:1rem; align-items:flex-start; }
.guest-card.checkin-card  { border-left:4px solid #16a34a; }
.guest-card.stay-card     { border-left:4px solid #3b82f6; }
.guest-card.checkout-card { border-left:4px solid #e65100; }
.gc-icon { font-size:1.4rem; flex-shrink:0; }
.gc-body { flex:1; min-width:0; }
.gc-name { font-size:.95rem; font-weight:700; margin-bottom:.2rem; }
.gc-meta { font-size:.8rem; color:var(--text-muted); line-height:1.7; }
.gc-amount { font-size:1rem; font-weight:800; color:var(--primary-dark); white-space:nowrap; }
.day-empty { text-align:center; padding:2rem; color:var(--text-muted); font-size:.88rem; }
.day-stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:.75rem; margin-bottom:1.25rem; }
.day-stat { background:var(--card); border-radius:10px; padding:1rem; border:1px solid var(--border); text-align:center; }
.day-stat .ds-val { font-size:1.6rem; font-weight:800; color:var(--primary-dark); }
.day-stat .ds-lbl { font-size:.72rem; color:var(--text-muted); margin-top:.2rem; }

/* ── Mobile bottom navigation bar ──────────────────────────── */
.mob-nav {
    display: none;
    position: fixed;
    bottom: 0; left: 0; right: 0;
    z-index: 999;
    background: var(--sidebar-bg);
    border-top: 1px solid rgba(255,255,255,.1);
    padding-bottom: env(safe-area-inset-bottom, 0);
    box-shadow: 0 -4px 20px rgba(0,0,0,.25);
}
.mob-nav-inner {
    display: flex;
    align-items: stretch;
}
.mob-nav a {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 8px 4px;
    min-height: 56px;
    color: var(--sidebar-text);
    text-decoration: none;
    font-size: .6rem;
    font-weight: 600;
    gap: 3px;
    transition: background .15s;
    -webkit-tap-highlight-color: transparent;
}
.mob-nav a .mn-icon { font-size: 1.3rem; line-height: 1; }
.mob-nav a.active, .mob-nav a:active { background: var(--sidebar-active); color: #fff; }
.mob-nav a.mn-sync {
    background: var(--primary);
    color: #fff;
    border-radius: 0;
    font-size: .65rem;
}

/* Mobile hamburger toggle */
.mob-menu-btn {
    display: none;
    position: fixed;
    top: 12px; right: 12px;
    z-index: 1100;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: 10px;
    width: 44px; height: 44px;
    font-size: 1.3rem;
    cursor: pointer;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
}
.mob-sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.5);
    z-index: 900;
}
.mob-sidebar-overlay.open { display: block; }

/* ── Tablet / Mobile responsive ─────────────────────────────── */
@media(max-width:900px) {
    .dash-grid { grid-template-columns:1fr; }
    .stats-row { grid-template-columns:repeat(2,1fr); }
}

@media(max-width:768px) {
    /* Layout */
    .layout { flex-direction: column; }
    .sidebar {
        position: fixed;
        top: 0; left: -260px; bottom: 0;
        width: 260px;
        z-index: 1000;
        transition: left .25s ease;
        overflow-y: auto;
    }
    .sidebar.mob-open { left: 0; }
    .main { margin-left: 0 !important; width: 100%; }

    /* Show mobile elements */
    .mob-nav { display: block; }
    .mob-menu-btn { display: flex; }

    /* Push content above bottom nav */
    .main { padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px)); }

    /* Stats */
    .stats-row {
        grid-template-columns: repeat(2,1fr);
        gap: .75rem;
    }
    .stat-card { padding: 1rem; }
    .stat-card .stat-val { font-size: 1.5rem; }

    /* Week view — horizontal scroll cards instead of grid */
    .week-grid {
        display: flex !important;
        overflow-x: auto;
        gap: .75rem;
        padding-bottom: .5rem;
        scroll-snap-type: x mandatory;
        -webkit-overflow-scrolling: touch;
    }
    .week-day-card {
        min-width: 160px;
        flex-shrink: 0;
        scroll-snap-align: start;
    }

    /* Day stats */
    .day-stats-row { grid-template-columns: repeat(2,1fr); gap: .75rem; }

    /* Tables — horizontal scroll */
    .tbl-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .tbl { min-width: 600px; }
    .tbl th, .tbl td { white-space: nowrap; }

    /* All action buttons — minimum 44px touch target */
    .btn, button, input[type="submit"],
    .sync-btn, .btn-login {
        min-height: 44px;
        padding-left: 16px;
        padding-right: 16px;
    }

    /* Inputs */
    input, select, textarea {
        font-size: 16px !important;
        min-height: 44px;
    }

    /* Panel headers */
    .panel-hd {
        flex-wrap: wrap;
        gap: .5rem;
        padding: 1rem 1.25rem;
    }
    .panel-hd h3 { font-size: .95rem; }

    /* Forms */
    .form-row-3 { grid-template-columns: 1fr !important; }

    /* Guest cards in day view */
    .guest-card { padding: 1rem; }

    /* Topbar */
    .topbar { padding: 12px 1rem; }
    .topbar .tb-title { font-size: .9rem; }
    .topbar .tb-right { gap: .5rem; }
    .topbar .tb-right .tb-link { display: none; }

    /* Mini calendar */
    .mini-cal { font-size: .75rem; }

    /* Chart container */
    .chart-container {
        position: relative;
        height: 260px !important;
        overflow: hidden;
    }

    /* Hide sidebar nav items text on very small */
    .dash-grid { grid-template-columns: 1fr; }
}

@media(max-width:480px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
    .stat-card .stat-val { font-size: 1.35rem; }
    .week-day-card { min-width: 140px; }
    .main { padding: 1rem; }
}

/* ── Payment badges ─────────────────────────────────────────── */
.pay-pill { display:inline-block; padding:.15rem .55rem; border-radius:20px; font-size:.7rem; font-weight:700; white-space:nowrap; }
/* Clickable table rows */
.clickable-row { cursor:pointer; transition:background .12s; }
.clickable-row:hover { background:#f0faf3 !important; }
/* Booking detail modal */
.bk-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:.6rem 1.2rem; font-size:.88rem; }
.bk-detail-grid .bk-lbl { color:var(--text-muted); font-size:.76rem; margin-bottom:.1rem; }
.bk-detail-grid .bk-val { font-weight:600; color:var(--text); }
.bk-detail-full { grid-column:1/-1; }
.pay-paid   { background:#dcfce7; color:#166534; }
.pay-partial{ background:#fef9c3; color:#854d0e; }
.pay-unpaid { background:#fee2e2; color:#991b1b; }
.balance-cell { font-size:.78rem; color:#e65100; font-weight:700; }
.pdf-link { display:inline-flex; align-items:center; gap:.25rem; font-size:.75rem; color:var(--primary); border:1px solid var(--primary); border-radius:5px; padding:.15rem .5rem; white-space:nowrap; }
.pdf-link:hover { background:var(--primary); color:#fff; }
.wa-link { display:inline-flex; align-items:center; gap:.25rem; font-size:.75rem; color:#16a34a; border:1px solid #16a34a; border-radius:5px; padding:.15rem .5rem; white-space:nowrap; margin-left:.25rem; }
.wa-link:hover { background:#16a34a; color:#fff; }

/* ── Nights counter ─────────────────────────────────────────── */
.nights-display { display:inline-block; background:var(--primary); color:#fff; border-radius:6px; padding:.2rem .6rem; font-size:.78rem; font-weight:700; margin-left:.5rem; }

/* ── FAB (Floating Action Button) ───────────────────────────── */
.fab {
    position: fixed;
    bottom: 88px; right: 24px;
    z-index: 800;
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--primary-dark);
    color: #fff;
    border: none;
    border-radius: 14px;
    padding: 13px 18px;
    font-size: .9rem;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(0,0,0,.25);
    transition: all .2s;
}
.fab:hover { background: var(--primary); transform: translateY(-2px); box-shadow: 0 6px 28px rgba(0,0,0,.3); }
.fab .fab-shortcut { font-size: .7rem; opacity: .65; background: rgba(255,255,255,.2); padding: .1rem .4rem; border-radius: 5px; }
@media(max-width:768px) {
    .fab { bottom: 78px; right: 16px; padding: 11px 14px; font-size: .83rem; }
    .fab .fab-shortcut { display: none; }
}

/* ── Quick-Book Modal ────────────────────────────────────────── */
.modal-overlay {
    display: none;
    position: fixed; inset: 0; z-index: 1200;
    background: rgba(0,0,0,.55);
    align-items: center; justify-content: center;
    padding: 16px;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: #fff;
    border-radius: 16px;
    width: 100%; max-width: 660px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,.3);
    animation: slideUp .2s ease;
}
@keyframes slideUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
.modal-hd {
    padding: 1.1rem 1.4rem;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; background: #fff; z-index: 1;
}
.modal-hd h3 { font-size: 1rem; font-weight: 700; }
.modal-close { background: none; border: none; font-size: 1.3rem; cursor: pointer; color: var(--text-muted); padding: .25rem .5rem; border-radius: 6px; }
.modal-close:hover { background: #f3f4f6; color: var(--text); }
.modal-bd { padding: 1.25rem 1.4rem; }
.modal-ft { padding: .85rem 1.4rem; border-top: 1px solid var(--border); display: flex; gap: .75rem; justify-content: flex-end; background: #f9fafb; border-radius: 0 0 16px 16px; }
.source-tabs { display: flex; gap: .4rem; margin-bottom: 1rem; flex-wrap: wrap; }
.source-tab { border: 1.5px solid var(--border); background: #fff; border-radius: 8px; padding: .4rem .9rem; font-size: .82rem; font-weight: 600; cursor: pointer; color: var(--text-muted); }
.source-tab.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .75rem; }
.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
.section-label { font-size: .73rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; margin: 1rem 0 .5rem; }
.pay-method-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: .4rem; margin-bottom: .75rem; }
.pay-method-btn { border: 1.5px solid var(--border); background: #fff; border-radius: 7px; padding: .4rem; font-size: .75rem; font-weight: 600; cursor: pointer; color: var(--text-muted); text-align: center; }
.pay-method-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }

/* ── WhatsApp Inbox ──────────────────────────────────────────── */
.wa-layout { display: grid; grid-template-columns: 300px 1fr; height: calc(100vh - 140px); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; background: #fff; }
.wa-sidebar { border-right: 1px solid var(--border); display: flex; flex-direction: column; overflow: hidden; }
.wa-sidebar-hd { padding: .85rem 1rem; border-bottom: 1px solid var(--border); }
.wa-sidebar-hd h3 { font-size: .88rem; font-weight: 700; margin-bottom: .5rem; }
.wa-search { width: 100%; padding: .45rem .75rem; border: 1.5px solid var(--border); border-radius: 8px; font-size: .83rem; }
.wa-filters { display: flex; gap: .3rem; margin-top: .5rem; flex-wrap: wrap; }
.wa-filter { border: 1px solid var(--border); background: #fff; border-radius: 20px; padding: .2rem .65rem; font-size: .72rem; font-weight: 600; cursor: pointer; color: var(--text-muted); }
.wa-filter.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.wa-conv-list { flex: 1; overflow-y: auto; }
.wa-conv-item { padding: .75rem 1rem; border-bottom: 1px solid #f3f6f3; cursor: pointer; display: flex; gap: .65rem; align-items: flex-start; transition: background .1s; }
.wa-conv-item:hover { background: #f7faf7; }
.wa-conv-item.active { background: #e8f5e9; border-left: 3px solid var(--primary); }
.wa-conv-avatar { width: 38px; height: 38px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1rem; font-weight: 700; flex-shrink: 0; }
.wa-conv-body { flex: 1; min-width: 0; }
.wa-conv-top { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: .15rem; }
.wa-conv-name { font-size: .85rem; font-weight: 700; }
.wa-conv-time { font-size: .68rem; color: var(--text-muted); white-space: nowrap; }
.wa-conv-preview { font-size: .78rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wa-conv-footer { display: flex; justify-content: space-between; align-items: center; margin-top: .25rem; }
.wa-status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
.wa-unread-badge { background: var(--primary); color: #fff; font-size: .66rem; font-weight: 700; border-radius: 20px; padding: .1rem .4rem; min-width: 18px; text-align: center; }

.wa-thread { display: flex; flex-direction: column; overflow: hidden; }
.wa-thread-hd { padding: .75rem 1.1rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: #fff; flex-shrink: 0; }
.wa-thread-info { display: flex; align-items: center; gap: .6rem; }
.wa-thread-name { font-size: .95rem; font-weight: 700; }
.wa-thread-phone { font-size: .78rem; color: var(--text-muted); }
.wa-thread-actions { display: flex; gap: .4rem; flex-wrap: wrap; }

.wa-messages { flex: 1; overflow-y: auto; padding: 1rem; background: #e5ded8 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60'%3E%3Ccircle cx='30' cy='30' r='1' fill='rgba(0,0,0,.04)'/%3E%3C/svg%3E"); display: flex; flex-direction: column; gap: .6rem; }
.wa-msg { max-width: 72%; display: flex; flex-direction: column; }
.wa-msg.guest { align-self: flex-start; }
.wa-msg.staff { align-self: flex-end; }
.wa-msg.system { align-self: center; max-width: 85%; }
.wa-bubble { padding: .55rem .85rem; border-radius: 10px; font-size: .85rem; line-height: 1.45; box-shadow: 0 1px 2px rgba(0,0,0,.1); }
.wa-msg.guest  .wa-bubble { background: #fff; border-radius: 0 10px 10px 10px; color: #111827; }
.wa-msg.staff  .wa-bubble { background: #dcf8c6; border-radius: 10px 0 10px 10px; color: #111827; }
.wa-msg.system .wa-bubble { background: rgba(255,255,255,.65); color: #555; font-size: .78rem; text-align: center; border-radius: 10px; }
.wa-msg-time { font-size: .67rem; color: rgba(0,0,0,.45); margin-top: .2rem; }
.wa-msg.staff .wa-msg-time { text-align: right; }

.wa-inquiry-banner { margin: .35rem 0; padding: .55rem .85rem; background: #fffbeb; border: 1px solid #f59e0b; border-radius: 8px; font-size: .8rem; display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.wa-inquiry-banner strong { color: #92400e; }
.wa-inquiry-info { flex: 1; min-width: 0; color: #92400e; }
.wa-inquiry-book { background: var(--primary); color: #fff; border: none; border-radius: 6px; padding: .3rem .75rem; font-size: .78rem; font-weight: 700; cursor: pointer; white-space: nowrap; }

.wa-composer { padding: .75rem 1rem; border-top: 1px solid var(--border); background: #f7faf7; flex-shrink: 0; }
.wa-tpl-strip { display: flex; gap: .35rem; overflow-x: auto; padding-bottom: .4rem; scrollbar-width: none; }
.wa-tpl-strip::-webkit-scrollbar { display: none; }
.wa-tpl-btn { border: 1px solid var(--border); background: #fff; border-radius: 20px; padding: .25rem .7rem; font-size: .75rem; font-weight: 600; cursor: pointer; white-space: nowrap; color: var(--primary); flex-shrink: 0; }
.wa-tpl-btn:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
.wa-composer-row { display: flex; gap: .5rem; margin-top: .4rem; align-items: flex-end; }
.wa-composer-row textarea { flex: 1; border: 1.5px solid var(--border); border-radius: 10px; padding: .6rem .85rem; font-size: .88rem; resize: none; min-height: 44px; max-height: 120px; font-family: inherit; }
.wa-composer-row textarea:focus { outline: none; border-color: var(--primary); }
.wa-send-btn { background: var(--primary); color: #fff; border: none; border-radius: 50%; width: 42px; height: 42px; font-size: 1.1rem; cursor: pointer; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
.wa-send-btn:hover { background: var(--primary-dark); }
.wa-empty { display: flex; align-items: center; justify-content: center; height: 100%; color: var(--text-muted); font-size: .9rem; flex-direction: column; gap: .5rem; }

.wa-status-badge { font-size: .7rem; font-weight: 700; padding: .15rem .5rem; border-radius: 20px; }
.ws-new_inquiry  { background: #fef9c3; color: #854d0e; }
.ws-awaiting_reply { background: #dbeafe; color: #1e40af; }
.ws-confirmed    { background: #dcfce7; color: #166534; }
.ws-urgent       { background: #fee2e2; color: #991b1b; }
.ws-closed       { background: #f3f4f6; color: #6b7280; }

@media(max-width:768px) {
    .wa-layout { grid-template-columns: 1fr; height: auto; }
    .wa-thread { display: none; }
    .wa-thread.wa-active { display: flex; height: calc(100vh - 160px); }
    .wa-sidebar { height: 50vh; }
}

/* ── Calendar view tabs ─────────────────────────────────── */
.cal-view-tabs {
  display: flex;
  gap: .35rem;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: .35rem;
  margin-bottom: 1.25rem;
  width: fit-content;
}
.cvt-btn {
  padding: .45rem 1.1rem;
  border-radius: 7px;
  font-size: .82rem;
  font-weight: 600;
  color: var(--text-muted);
  cursor: pointer;
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: .4rem;
  border: none;
  background: transparent;
  transition: all .15s;
}
.cvt-btn:hover { background: var(--bg); color: var(--text); }
.cvt-btn.active {
  background: var(--primary);
  color: #fff;
  box-shadow: 0 2px 8px rgba(46,125,50,.3);
}

/* ── Year view grid ─────────────────────────────────────── */
.year-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;
}
.year-month-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
}
.ymc-hd {
  background: var(--primary);
  color: #fff;
  font-size: .8rem;
  font-weight: 700;
  padding: .45rem .75rem;
  text-align: center;
}
.ymc-body { padding: .4rem .5rem .5rem; }
.ymc-cal { width: 100%; border-collapse: collapse; }
.ymc-cal th {
  font-size: .6rem;
  color: var(--text-muted);
  text-align: center;
  padding: .15rem 0;
  font-weight: 600;
}
.ydc {
  width: 100%;
  aspect-ratio: 1;
  font-size: .62rem;
  text-align: center;
  border-radius: 4px;
  cursor: pointer;
  color: var(--text);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background .1s;
}
.ydc:hover { background: var(--primary-light); }
.ydc.ydc-today { background: var(--primary); color: #fff; font-weight: 700; border-radius: 50%; }
.ydc.ydc-booked { background: #bbf7d0; color: #14532d; font-weight: 600; }
.ydc.ydc-blocked { background: #fecaca; color: #7f1d1d; font-weight: 600; }
.ydc.ydc-past { opacity: .4; cursor: default; }
.ydc.ydc-demand { outline: 2px solid #f59e0b; outline-offset: -2px; }
.year-legend {
  display: flex;
  gap: 1.2rem;
  flex-wrap: wrap;
  align-items: center;
  margin-bottom: 1rem;
  font-size: .78rem;
  color: var(--text-muted);
}
.yl-dot {
  width: 12px; height: 12px;
  border-radius: 3px;
  display: inline-block;
  margin-right: .3rem;
}
@media (max-width: 900px) {
  .year-grid { grid-template-columns: repeat(2, 1fr); }
  .cal-view-tabs { width: 100%; justify-content: space-between; }
  .cvt-btn { padding: .4rem .7rem; font-size: .75rem; }
}
@media (max-width: 520px) {
  .year-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<?php if (empty($_SESSION['admin_logged_in'])): ?>
<!-- ═══ LOGIN ═══ -->
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <span class="icon">🏡</span>
      <h1>PMS Dashboard</h1>
      <p>Kanchi Farm Stay</p>
    </div>
    <?php if ($loginError): ?><div class="login-err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
    <form method="POST">
    <?= csrfField() ?>
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="username" value="admin" autocomplete="username">
      <label>Admin Password</label>
      <input type="password" name="password" autofocus autocomplete="current-password" placeholder="Enter password">
      <button type="submit" class="btn-login">Sign In →</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ═══ APP ═══ -->
<div class="layout">

<!-- Sidebar -->
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="name">Kanchi Farm Stay</div>
    <div class="sub">Intelligent PMS v2</div>
  </div>
  <nav class="sidebar-nav">
    <?php
    $navItems = [
      'dashboard' => ['📊', 'Dashboard',        'admin.php?section=dashboard', 0],
      'calendar'  => ['📅', 'Calendar',         'admin.php?section=calendar&view=day&date=' . date('Y-m-d'), 0],
      'bookings'  => ['📋', 'Bookings',          'admin.php?section=bookings', 0],
      'blocked'   => ['🚫', 'Blocked Dates',     'admin.php?section=blocked', 0],
      'demand'    => ['🥇', 'High-Demand Dates', 'admin.php?section=demand', 0],
      'wa_inbox'  => ['💬', 'WA Inbox',          'admin.php?section=wa_inbox', $waUnread],
      'pricing'   => ['💡', 'Pricing',           'admin.php?section=pricing', $pendingCount],
      'analytics' => ['📈', 'Analytics',         'admin.php?section=analytics', 0],
      'channels'  => ['🔗', 'Channels',          'admin.php?section=channels', 0],
      'export'    => ['📤', 'iCal Export',       'admin.php?section=export', 0],
    ];
    $currentSec = $section ?? 'dashboard';
    $navActive = in_array($currentSec, ['day','week','overview','calendar']) ? 'calendar' : $currentSec;
    foreach ($navItems as $key => [$icon, $label, $url, $cnt]):
    ?>
      <a href="<?= $url ?>" class="nav-item <?= $navActive === $key ? 'active' : '' ?>">
        <span class="nav-icon"><?= $icon ?></span>
        <?= $label ?>
        <?php if ($cnt > 0): ?><span class="nav-badge"><?= $cnt ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-bottom">
    <a href="/">← View website</a><br>
    <form method="POST" style="display:inline">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="logout">
      <button type="submit" style="border:0;background:none;padding:0;color:#fca5a5;cursor:pointer">Logout</button>
    </form>
  </div>
</aside>

<!-- Main -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title"><?php
      $calView = $_GET['view'] ?? 'day';
      echo match($section) {
        'dashboard' => '📊 Dashboard',
        'day'       => '📅 Calendar — Day View · ' . date('D, d M Y', strtotime($dayDate)),
        'week'      => '📅 Calendar — Week View',
        'calendar'  => match($calView) {
          'week'  => '📅 Calendar — Week View',
          'month' => '📅 Calendar — Month View',
          'year'  => '📅 Calendar — Year View',
          default => '📅 Calendar — Day View · ' . date('D, d M Y', strtotime($dayDate)),
        },
        'bookings'  => '📋 Bookings',
        'overview'  => '📊 Overview',
        'blocked'   => '🚫 Blocked Dates',
        'demand'    => '🥇 High-Demand Dates',
        'wa_inbox'  => '💬 WhatsApp Inbox',
        'pricing'   => '💡 Pricing & Suggestions',
        'analytics' => '📈 Analytics & Forecasting',
        'channels'  => '🔗 Channel Sync',
        'export'    => '📤 iCal Export',
        default     => 'Dashboard',
      };
    ?></div>
    <div class="topbar-right">
      <a href="channel-dashboard.php" style="padding:6px 12px;background:#7c3aed;color:#fff;border-radius:7px;font-size:.78rem;font-weight:700;text-decoration:none;margin-right:6px">📅 Channel View</a>
      <span><?= date('D, d M Y') ?></span>
      <button class="sync-btn" id="syncBtn" onclick="runSync()">⟳ Sync Now</button>
    </div>
  </div>

  <div class="content">
    <?php if ($flash): ?><div class="flash">✓ <?= $flash ?></div><?php endif; ?>

<!-- ══════════════════════════════════════════════
     DASHBOARD
════════════════════════════════════════════════ -->
<?php if ($section === 'dashboard'): ?>

<?php
// Deduplicate arrivals + departures → one record per booking, sorted by check-in
$activityIds = [];
$activity    = [];
foreach (array_merge($arrivals, $departures) as $b) {
    if (!in_array($b['id'], $activityIds)) {
        $activityIds[] = $b['id'];
        $activity[]    = $b;
    }
}
usort($activity, fn($a, $b) => strcmp($a['check_in'], $b['check_in']));

// Revenue for the week (check-ins only, avoids double-counting)
$weekRev = array_sum(array_column(
    array_filter($arrivals, fn($b) => ($b['amount'] ?? 0) > 0),
    'amount'
));
?>

<div class="stats-row">
  <div class="stat-card">
    <div class="stat-icon">📋</div>
    <a class="stat-link" href="admin.php?section=bookings" title="View all upcoming bookings">
      <div class="stat-val"><?= count(array_filter($confirmedBookings, fn($b) => $b['check_out'] >= date('Y-m-d') && $b['source'] !== 'blocked')) ?><span class="stat-arrow">→</span></div>
    </a>
    <div class="stat-lbl">Active / Upcoming Bookings</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">✈️</div>
    <a class="stat-link" href="admin.php?section=calendar&view=day&date=<?= date('Y-m-d') ?>" title="View today's day view">
      <div class="stat-val"><?= count($arrivals) ?><span class="stat-arrow">→</span></div>
    </a>
    <div class="stat-lbl">Check-ins Next 7 Days</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">💰</div>
    <a class="stat-link" href="admin.php?section=analytics" title="View revenue analytics">
      <div class="stat-val"><?= fmt($totalRev) ?><span class="stat-arrow">→</span></div>
    </a>
    <div class="stat-lbl">Direct Revenue This Month</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">📈</div>
    <a class="stat-link" href="admin.php?section=calendar&view=month" title="View occupancy details">
      <div class="stat-val"><?= $occupancy ?>%<span class="stat-arrow">→</span></div>
    </a>
    <div class="stat-lbl">Avg Occupancy (30 days)</div>
    <?php if ($pendingCount > 0): ?>
    <div class="stat-sub" style="color:#e65100;font-weight:600">⚡ <?= $pendingCount ?> pricing suggestion<?= $pendingCount>1?'s':'' ?> pending</div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Property Status Board ──────────────────────────────── -->
<?php
$today    = date('Y-m-d');
$propStatus = [];
foreach ($rooms as $rid => $rname) {
    // Find the active booking for this room today (or the next upcoming one)
    $activeBooking = null;
    $nextBooking   = null;
    foreach ($confirmed as $b) {
        if ($b['room_id'] !== $rid) continue;
        // Currently occupied: check_in <= today < check_out
        if ($b['check_in'] <= $today && $b['check_out'] > $today) {
            if (!$activeBooking || $b['check_in'] > $activeBooking['check_in'])
                $activeBooking = $b;
        }
        // Next upcoming booking after today
        if ($b['check_in'] > $today) {
            if (!$nextBooking || $b['check_in'] < $nextBooking['check_in'])
                $nextBooking = $b;
        }
    }
    $propStatus[$rid] = ['active' => $activeBooking, 'next' => $nextBooking, 'name' => $rname];
}

// Count by status for the summary line
$totalFree    = count(array_filter($propStatus, fn($p) => !$p['active']));
$totalOccupied = count($propStatus) - $totalFree;
?>
<div class="panel" style="margin-bottom:1rem">
  <div class="panel-hd">
    <div>
      <h3>🏠 Property Status — Today</h3>
      <div class="sub"><?= date('l, d M Y') ?> &nbsp;·&nbsp; <?= $totalOccupied ?> occupied &nbsp;·&nbsp; <?= $totalFree ?> available</div>
    </div>
    <a href="admin.php?section=calendar&view=month" class="btn btn-grey btn-sm">📅 Full Calendar →</a>
  </div>
  <div class="panel-bd" style="padding:.75rem">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:.65rem">
      <?php foreach ($propStatus as $rid => $ps):
        $active = $ps['active'];
        $next   = $ps['next'];

        if ($active) {
            $src       = $active['source'];
            $bgColor   = sourceColor($src);
            $isDirectSrc = in_array($src, ['direct','phone','whatsapp','walk_in','manual','razorpay']);
            $statusLabel = $isDirectSrc ? '🔒 Direct Booking' : '🔒 ' . sourceName($src);
            $statusBg  = $bgColor;
            $textColor = '#fff';
            $checkoutStr = date('d M', strtotime($active['check_out']));
            $nightsLeft  = (int)ceil((strtotime($active['check_out']) - strtotime($today)) / 86400);
        } else {
            $statusLabel = '✅ Available';
            $statusBg    = '#e8f5e9';
            $textColor   = '#2e7d32';
            $bgColor     = '#fff';
        }
      ?>
      <div onclick="window.location.href='admin.php?section=calendar&view=month'" style="border:2px solid <?= $active ? $bgColor : '#d1fae5' ?>;border-radius:10px;overflow:hidden;cursor:pointer;transition:box-shadow .15s" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,.12)'" onmouseout="this.style.boxShadow='none'">
        <!-- Room name header -->
        <div style="background:<?= $active ? $bgColor : '#f0fdf4' ?>;padding:.45rem .75rem;font-weight:700;font-size:.78rem;color:<?= $active ? $textColor : '#166534' ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          <?= htmlspecialchars($ps['name']) ?>
        </div>
        <!-- Status body -->
        <div style="padding:.6rem .75rem;background:#fff">
          <div style="display:inline-block;background:<?= $statusBg ?>;color:<?= $textColor ?>;padding:.2rem .5rem;border-radius:5px;font-size:.72rem;font-weight:700;margin-bottom:.4rem">
            <?= $statusLabel ?>
          </div>
          <?php if ($active): ?>
            <div style="font-size:.82rem;font-weight:600;color:#1a202c;margin-bottom:.15rem"><?= htmlspecialchars($active['guest_name']) ?></div>
            <div style="font-size:.75rem;color:#718096">
              <?= date('d M', strtotime($active['check_in'])) ?> → <?= $checkoutStr ?>
              &nbsp;·&nbsp; <?= $nightsLeft ?> night<?= $nightsLeft != 1 ? 's' : '' ?> left
            </div>
            <?php if ($next): ?>
            <div style="margin-top:.35rem;padding-top:.35rem;border-top:1px dashed #e2e8f0;font-size:.72rem;color:#a0aec0">
              Next: <?= htmlspecialchars($next['guest_name']) ?> · <?= date('d M', strtotime($next['check_in'])) ?>
            </div>
            <?php endif; ?>
          <?php else: ?>
            <?php if ($next): ?>
            <div style="font-size:.75rem;color:#718096">
              Free until <strong><?= date('d M', strtotime($next['check_in'])) ?></strong>
            </div>
            <div style="font-size:.72rem;color:#a0aec0;margin-top:.2rem">
              Next: <?= htmlspecialchars($next['guest_name']) ?> (<?= sourceName($next['source']) ?>)
            </div>
            <?php else: ?>
            <div style="font-size:.75rem;color:#48bb78">No upcoming bookings</div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Pricing alert -->
<?php if ($pendingCount > 0): ?>
<div class="panel" style="border-color:var(--gold);margin-bottom:1rem">
  <div class="panel-hd" style="background:#fffbeb"><h3>💡 <?= $pendingCount ?> Pricing Suggestions Pending</h3></div>
  <div class="panel-bd" style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
    <p style="font-size:.85rem;color:var(--text-muted);margin:0;flex:1">Review AI-generated pricing recommendations based on Muhurathams, festivals &amp; holidays.</p>
    <a href="admin.php?section=pricing" class="btn btn-gold">Review Suggestions →</a>
  </div>
</div>
<?php endif; ?>

<!-- Arrivals & Departures — single record per booking -->
<div class="panel">
  <div class="panel-hd">
    <div>
      <h3>📅 Arrivals &amp; Departures — Next 7 Days</h3>
      <div class="sub">✈️ <?= count($arrivals) ?> check-in<?= count($arrivals)!=1?'s':'' ?> &nbsp;·&nbsp; 🚪 <?= count($departures) ?> check-out<?= count($departures)!=1?'s':'' ?></div>
    </div>
    <?php if ($weekRev > 0): ?>
    <div style="text-align:right;font-size:.82rem;line-height:1.4">
      <div style="font-weight:700;color:var(--primary-dark);font-size:.95rem"><?= fmt($weekRev) ?></div>
      <div style="color:var(--text-muted)">revenue this week</div>
    </div>
    <?php endif; ?>
  </div>
  <?php if (empty($activity)): ?>
  <div class="panel-bd"><p style="color:var(--text-muted);font-size:.85rem">No arrivals or departures in the next 7 days.</p></div>
  <?php else: ?>
  <div class="tbl-wrap">
    <table class="tbl" style="table-layout:auto">
      <thead>
        <tr><th>Guest</th><th>Property</th><th>Check-in</th><th>Check-out</th><th>Nts</th><th>Source</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php foreach ($activity as $b):
          $today    = date('Y-m-d');
          $isArriv  = $b['check_in']  >= $today && $b['check_in']  <= date('Y-m-d', strtotime('+7 days'));
          $isDepart = $b['check_out'] >= $today && $b['check_out'] <= date('Y-m-d', strtotime('+7 days'));
          $bJson    = bookingJson($b);
        ?>
        <tr class="clickable-row" onclick="showBookingModal(<?= $bJson ?>)" title="Click for full details">
          <td>
            <div style="font-weight:600"><?= htmlspecialchars($b['guest_name']) ?></div>
            <?php if (!empty($b['guest_phone'])): ?><div class="muted" style="font-size:.74rem"><?= htmlspecialchars($b['guest_phone']) ?></div><?php endif; ?>
          </td>
          <td style="font-size:.82rem"><?= htmlspecialchars($b['room_name']) ?></td>
          <td style="white-space:nowrap"><?= date('D d M', strtotime($b['check_in'])) ?></td>
          <td style="white-space:nowrap"><?= date('D d M', strtotime($b['check_out'])) ?></td>
          <td class="muted"><?= nights($b['check_in'], $b['check_out']) ?></td>
          <td><?= badge($b['source']) ?></td>
          <td style="white-space:nowrap">
            <?php if ($isArriv && $isDepart): ?>
              <span style="background:#fff3e0;color:#e65100;padding:.2rem .5rem;border-radius:5px;font-size:.72rem;font-weight:700">✈️ In · 🚪 Out</span>
            <?php elseif ($isArriv): ?>
              <span style="background:#e8f5e9;color:#2e7d32;padding:.2rem .5rem;border-radius:5px;font-size:.72rem;font-weight:700">✈️ Check-in</span>
            <?php else: ?>
              <span style="background:#fce4ec;color:#880e4f;padding:.2rem .5rem;border-radius:5px;font-size:.72rem;font-weight:700">🚪 Check-out</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Recent Bookings -->
<div class="panel">
  <div class="panel-hd"><h3>📋 Recent Bookings</h3><span class="sub">Click any row for full details</span></div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Property</th><th>Guest</th><th>Check-in</th><th>Check-out</th><th>Nts</th><th>Source</th><th>Revenue</th></tr></thead>
      <tbody>
        <?php foreach (array_slice(array_values(array_filter($allBookings, fn($b) => $b['source'] !== 'blocked')), 0, 10) as $b):
          $bJson = bookingJson($b);
        ?>
        <tr class="clickable-row" onclick="showBookingModal(<?= $bJson ?>)" title="Click for full details">
          <td style="font-weight:600"><?= htmlspecialchars($b['room_name']) ?></td>
          <td><?= htmlspecialchars($b['guest_name']) ?></td>
          <td class="muted" style="white-space:nowrap"><?= date('d M Y', strtotime($b['check_in'])) ?></td>
          <td class="muted" style="white-space:nowrap"><?= date('d M Y', strtotime($b['check_out'])) ?></td>
          <td class="muted"><?= nights($b['check_in'], $b['check_out']) ?></td>
          <td><?= badge($b['source']) ?></td>
          <td><?= $b['amount'] > 0 ? fmt((float)$b['amount']) : '<span class="muted">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ══════════════════════════════════════════════
     UNIFIED CALENDAR (Day / Week / Month / Year)
════════════════════════════════════════════════ -->
<?php elseif (in_array($section, ['calendar','day','week','overview'])):
  // ── shared date params ──────────────────────
  $calBase  = 'admin.php?section=calendar';
  $calMonth = (int)($_GET['cm'] ?? date('n'));
  $calYear  = (int)($_GET['cy'] ?? date('Y'));
  if ($calMonth < 1)  { $calMonth = 12; $calYear--; }
  if ($calMonth > 12) { $calMonth = 1;  $calYear++; }
  $calYearView = (int)($_GET['cy'] ?? date('Y'));

  // Week start: default to Monday of current week
  $wsRaw = $_GET['ws'] ?? date('Y-m-d', strtotime('monday this week'));
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wsRaw)) $wsRaw = date('Y-m-d', strtotime('monday this week'));
  $weekStartTs  = strtotime($wsRaw);
  $weekStartStr = date('Y-m-d', $weekStartTs);
  $weekEndStr   = date('Y-m-d', strtotime('+6 days', $weekStartTs));
  $prevWeekStr  = date('Y-m-d', strtotime('-7 days', $weekStartTs));
  $nextWeekStr  = date('Y-m-d', strtotime('+7 days', $weekStartTs));

  // Day-view data
  $prevDay = date('Y-m-d', strtotime($dayDate . ' -1 day'));
  $nextDay = date('Y-m-d', strtotime($dayDate . ' +1 day'));
  $isToday = $dayDate === date('Y-m-d');
  $dayCheckins  = array_values(array_filter($confirmed, fn($b) => $b['check_in']  === $dayDate));
  $dayCheckouts = array_values(array_filter($confirmed, fn($b) => $b['check_out'] === $dayDate));
  $dayStays     = array_values(array_filter($confirmed, fn($b) => $dayDate > $b['check_in'] && $dayDate < $b['check_out']));
  $dayRevenue   = array_sum(array_column($dayCheckins, 'amount'));
  $dayDemand    = $demandByDate[$dayDate] ?? [];
?>

<!-- ── View tab bar ───────────────────────────────────────── -->
<div class="cal-view-tabs">
  <a class="cvt-btn <?= $calView==='day'   ? 'active' : '' ?>" href="<?= $calBase ?>&view=day&date=<?= $dayDate ?>">📅 Day</a>
  <a class="cvt-btn <?= $calView==='week'  ? 'active' : '' ?>" href="<?= $calBase ?>&view=week&ws=<?= $weekStartStr ?>">📆 Week</a>
  <a class="cvt-btn <?= $calView==='month' ? 'active' : '' ?>" href="<?= $calBase ?>&view=month&cm=<?= $calMonth ?>&cy=<?= $calYear ?>">🗓️ Month</a>
  <a class="cvt-btn <?= $calView==='year'  ? 'active' : '' ?>" href="<?= $calBase ?>&view=year&cy=<?= $calYearView ?>">📊 Year</a>
</div>

<?php if ($calView === 'day'): ?>

<!-- Day navigation -->
<div class="day-nav">
  <a class="day-btn" href="<?= $calBase ?>&view=day&date=<?= $prevDay ?>">← <?= date('d M', strtotime($prevDay)) ?></a>
  <span class="day-lbl"><?= date('l, d F Y', strtotime($dayDate)) ?></span>
  <?php if ($isToday): ?><span class="today-pill">Today</span><?php endif; ?>
  <a class="day-btn" href="<?= $calBase ?>&view=day&date=<?= $nextDay ?>"><?= date('d M', strtotime($nextDay)) ?> →</a>
  <a class="day-btn" href="<?= $calBase ?>&view=day&date=<?= date('Y-m-d') ?>" style="margin-left:auto">Jump to Today</a>
</div>

<!-- Demand event banner -->
<?php if (!empty($dayDemand)): ?>
<div style="margin-bottom:1rem">
  <?php foreach ($dayDemand as $de): ?>
  <div style="<?= demandBadgeStyle($de['demand_level']) ?>;padding:.6rem 1rem;border-radius:8px;margin-bottom:.4rem;font-weight:600;font-size:.88rem">
    <?= demandIcon($de['demand_level']) ?> <?= htmlspecialchars($de['event_name']) ?> — <span style="font-weight:400;opacity:.9"><?= ucfirst(str_replace('_',' ',$de['event_type'])) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Day stats -->
<div class="day-stats-row">
  <div class="day-stat">
    <div class="ds-val"><?= count($dayCheckins) ?></div>
    <div class="ds-lbl">Check-ins Today</div>
  </div>
  <div class="day-stat">
    <div class="ds-val"><?= count($dayStays) ?></div>
    <div class="ds-lbl">Guests In-Stay</div>
  </div>
  <div class="day-stat">
    <div class="ds-val"><?= count($dayCheckouts) ?></div>
    <div class="ds-lbl">Check-outs Today</div>
  </div>
  <div class="day-stat">
    <div class="ds-val"><?= fmt($dayRevenue) ?></div>
    <div class="ds-lbl">Revenue (Check-ins)</div>
  </div>
</div>

<!-- Full occupancy summary -->
<div class="panel" style="margin-bottom:1.25rem">
  <div class="panel-hd"><h3>🏠 Room Status on <?= date('d M Y', strtotime($dayDate)) ?></h3></div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Room</th><th>Status</th><th>Guest</th><th>Phone</th><th>Check-in</th><th>Check-out</th><th>Nights</th><th>Source</th><th>Amount</th></tr></thead>
      <tbody>
        <?php foreach ($rooms as $rid => $rname):
          // Find booking for this room on this day
          $rb = null;
          foreach ($confirmed as $b) {
            if ($b['room_id']===$rid && $dayDate >= $b['check_in'] && $dayDate < $b['check_out']) { $rb=$b; break; }
          }
          $status = '';
          $statusStyle = '';
          if ($rb) {
            if ($rb['check_in'] === $dayDate) { $status='Check-in'; $statusStyle='color:#16a34a;font-weight:700'; }
            elseif ($rb['check_out'] === date('Y-m-d', strtotime($dayDate.' +1 day'))) { $status='Last Night'; $statusStyle='color:#e65100;font-weight:700'; }
            else { $status='Occupied'; $statusStyle='color:#3b82f6;font-weight:600'; }
          }
        ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($rname) ?></td>
          <td>
            <?php if ($rb): ?>
              <span style="<?= $statusStyle ?>"><?= $status ?></span>
            <?php else: ?>
              <span style="color:var(--text-muted)">Free</span>
            <?php endif; ?>
          </td>
          <td><?= $rb ? htmlspecialchars($rb['guest_name']) : '—' ?></td>
          <td class="muted"><?= $rb ? htmlspecialchars($rb['guest_phone']) : '—' ?></td>
          <td><?= $rb ? $rb['check_in'] : '—' ?></td>
          <td><?= $rb ? $rb['check_out'] : '—' ?></td>
          <td><?= $rb ? nights($rb['check_in'],$rb['check_out']) : '—' ?></td>
          <td><?= $rb ? badge($rb['source']) : '' ?></td>
          <td><?= $rb && $rb['amount']>0 ? fmt($rb['amount']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Check-ins -->
<div class="day-section">
  <div class="day-section-hd">↘ Check-ins (<?= count($dayCheckins) ?>)</div>
  <?php if (empty($dayCheckins)): ?>
    <div class="day-empty">No check-ins on this date.</div>
  <?php else: foreach ($dayCheckins as $b):
    $bJson = bookingJson($b);
  ?>
  <div class="guest-card checkin-card" onclick="showBookingModal(<?= $bJson ?>)" style="cursor:pointer" title="Click to view / edit / delete">
    <div class="gc-icon">↘</div>
    <div class="gc-body">
      <div class="gc-name"><?= htmlspecialchars($b['guest_name']) ?></div>
      <div class="gc-meta">
        <strong>Room:</strong> <?= htmlspecialchars($b['room_name']) ?><br>
        <strong>Stay:</strong> <?= $b['check_in'] ?> → <?= $b['check_out'] ?> (<?= nights($b['check_in'],$b['check_out']) ?> nights)<br>
        <?php if ($b['guest_phone']): ?><strong>Phone:</strong> <?= htmlspecialchars($b['guest_phone']) ?><br><?php endif; ?>
        <?php if ($b['guest_email']): ?><strong>Email:</strong> <?= htmlspecialchars($b['guest_email']) ?><br><?php endif; ?>
        <?php if ($b['notes']): ?><strong>Notes:</strong> <?= htmlspecialchars($b['notes']) ?><br><?php endif; ?>
        <?php if ($b['booking_ref']): ?><strong>Ref:</strong> <?= htmlspecialchars($b['booking_ref']) ?><?php endif; ?>
      </div>
    </div>
    <div style="text-align:right;flex-shrink:0">
      <?= badge($b['source']) ?>
      <div class="gc-amount" style="margin-top:.35rem"><?= $b['amount']>0 ? fmt($b['amount']) : '—' ?></div>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- In-stay -->
<div class="day-section">
  <div class="day-section-hd">• In-Stay (<?= count($dayStays) ?>)</div>
  <?php if (empty($dayStays)): ?>
    <div class="day-empty">No guests currently mid-stay on this date.</div>
  <?php else: foreach ($dayStays as $b):
    $bJson = bookingJson($b);
  ?>
  <div class="guest-card stay-card" onclick="showBookingModal(<?= $bJson ?>)" style="cursor:pointer" title="Click to view / edit / delete">
    <div class="gc-icon">🛏</div>
    <div class="gc-body">
      <div class="gc-name"><?= htmlspecialchars($b['guest_name']) ?></div>
      <div class="gc-meta">
        <strong>Room:</strong> <?= htmlspecialchars($b['room_name']) ?><br>
        <strong>Stay:</strong> <?= $b['check_in'] ?> → <?= $b['check_out'] ?> (<?= nights($b['check_in'],$b['check_out']) ?> nights total)<br>
        <?php
          $daysIn = (int)ceil((strtotime($dayDate)-strtotime($b['check_in']))/86400);
          $daysLeft = (int)ceil((strtotime($b['check_out'])-strtotime($dayDate))/86400);
        ?>
        <strong>Day <?= $daysIn+1 ?> of stay</strong> · <?= $daysLeft ?> night<?= $daysLeft!==1?'s':'' ?> remaining<br>
        <?php if ($b['guest_phone']): ?><strong>Phone:</strong> <?= htmlspecialchars($b['guest_phone']) ?><br><?php endif; ?>
        <?php if ($b['guest_email']): ?><strong>Email:</strong> <?= htmlspecialchars($b['guest_email']) ?><?php endif; ?>
      </div>
    </div>
    <div style="text-align:right;flex-shrink:0">
      <?= badge($b['source']) ?>
      <div class="gc-amount" style="margin-top:.35rem"><?= $b['amount']>0 ? fmt($b['amount']) : '—' ?></div>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- Check-outs -->
<div class="day-section">
  <div class="day-section-hd">↗ Check-outs (<?= count($dayCheckouts) ?>)</div>
  <?php if (empty($dayCheckouts)): ?>
    <div class="day-empty">No check-outs on this date.</div>
  <?php else: foreach ($dayCheckouts as $b):
    $bJson = bookingJson($b);
  ?>
  <div class="guest-card checkout-card" onclick="showBookingModal(<?= $bJson ?>)" style="cursor:pointer" title="Click to view / edit / delete">
    <div class="gc-icon">↗</div>
    <div class="gc-body">
      <div class="gc-name"><?= htmlspecialchars($b['guest_name']) ?></div>
      <div class="gc-meta">
        <strong>Room:</strong> <?= htmlspecialchars($b['room_name']) ?><br>
        <strong>Stay:</strong> <?= $b['check_in'] ?> → <?= $b['check_out'] ?> (<?= nights($b['check_in'],$b['check_out']) ?> nights)<br>
        <?php if ($b['guest_phone']): ?><strong>Phone:</strong> <?= htmlspecialchars($b['guest_phone']) ?><br><?php endif; ?>
        <?php if ($b['guest_email']): ?><strong>Email:</strong> <?= htmlspecialchars($b['guest_email']) ?><?php endif; ?>
      </div>
    </div>
    <div style="text-align:right;flex-shrink:0">
      <?= badge($b['source']) ?>
      <div class="gc-amount" style="margin-top:.35rem"><?= $b['amount']>0 ? fmt($b['amount']) : '—' ?></div>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- Monthly navigation strip -->
<div class="panel">
  <div class="panel-hd"><h3>📆 Browse this Month</h3><span class="sub"><?= date('F Y', strtotime($dayDate)) ?> — click any date</span></div>
  <div class="panel-bd" style="padding:.75rem">
    <?php
    $browseYear  = (int)date('Y', strtotime($dayDate));
    $browseMonth = (int)date('n', strtotime($dayDate));
    $firstOfMonth = mktime(0,0,0,$browseMonth,1,$browseYear);
    $daysInBrowse = (int)date('t', $firstOfMonth);
    $startDowBrowse = (int)date('N', $firstOfMonth);
    $prevBrowse = date('Y-m-d', strtotime($dayDate.' -1 month'));
    $nextBrowse = date('Y-m-d', strtotime($dayDate.' +1 month'));
    ?>
    <div class="cal-nav">
      <button onclick="window.location.href='<?= $calBase ?>&view=day&date=<?= date('Y-m-d', mktime(0,0,0,$browseMonth-1,1,$browseYear)) ?>'">&lsaquo; <?= date('M', strtotime($dayDate.' -1 month')) ?></button>
      <span class="cal-title"><?= date('F Y', $firstOfMonth) ?></span>
      <button onclick="window.location.href='<?= $calBase ?>&view=day&date=<?= date('Y-m-d', mktime(0,0,0,$browseMonth+1,1,$browseYear)) ?>'">  <?= date('M', strtotime($dayDate.' +1 month')) ?> &rsaquo;</button>
    </div>
    <table class="mini-cal">
      <thead><tr><?php foreach(['Mo','Tu','We','Th','Fr','Sa','Su'] as $d): ?><th><?= $d ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php
      $col2=0; echo '<tr>';
      for ($i=1; $i<$startDowBrowse; $i++) { echo '<td></td>'; $col2++; }
      for ($day=1; $day<=$daysInBrowse; $day++) {
        $ds = sprintf('%04d-%02d-%02d',$browseYear,$browseMonth,$day);
        $isTd = $ds===date('Y-m-d');
        $isSel= $ds===$dayDate;
        $hasBk2 = false;
        foreach ($confirmed as $b) { if ($ds>=$b['check_in']&&$ds<$b['check_out']) { $hasBk2=true; break; } }
        $isDem2 = !empty($demandByDate[$ds]);
        $cls2 = implode(' ', array_filter(['dc',$isTd?'today':'', $hasBk2?'has-booking':'', $isDem2&&!$isTd?'is-demand':'', $isSel?'selected':'']));
        echo '<td><div class="'.$cls2.'" onclick="window.location.href=\''.htmlspecialchars($calBase).'&view=day&date='.$ds.'\'">'.$day.'</div></td>';
        $col2++;
        if ($col2%7===0&&$day<$daysInBrowse) echo '</tr><tr>';
      }
      echo '</tr>';
      ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($calView === 'week'): ?>

<!-- Week navigation -->
<div class="day-nav" style="margin-bottom:1rem">
  <a class="day-btn" href="<?= $calBase ?>&view=week&ws=<?= $prevWeekStr ?>">← <?= date('d M', strtotime($prevWeekStr)) ?></a>
  <span class="day-lbl"><?= date('d M', $weekStartTs) ?> — <?= date('d M Y', strtotime($weekEndStr)) ?></span>
  <a class="day-btn" href="<?= $calBase ?>&view=week&ws=<?= $nextWeekStr ?>"><?= date('d M', strtotime($nextWeekStr)) ?> →</a>
  <a class="day-btn" href="<?= $calBase ?>&view=week&ws=<?= date('Y-m-d', strtotime('monday this week')) ?>" style="margin-left:auto">This Week</a>
</div>

<div class="panel">
  <div class="panel-hd">
    <h3>📆 Week at a Glance</h3>
    <span class="sub"><?= date('d M Y', $weekStartTs) ?> — <?= date('d M Y', strtotime($weekEndStr)) ?></span>
  </div>
  <div class="panel-bd">
    <div class="week-grid">
      <?php
      $today = date('Y-m-d');
      for ($d = 0; $d < 7; $d++):
        $dateStr = date('Y-m-d', strtotime("+$d days", $weekStartTs));
        $isToday = $dateStr === $today;
        $dayDemand = $demandByDate[$dateStr] ?? [];
        $hasDemand = !empty($dayDemand);

        // Bookings for this day across all rooms
        $dayCheckins  = [];
        $dayCheckouts = [];
        $dayStays     = [];
        foreach ($confirmed as $b) {
          if ($b['check_in'] === $dateStr) $dayCheckins[] = $b;
          if ($b['check_out'] === $dateStr) $dayCheckouts[] = $b;
          if ($dateStr > $b['check_in'] && $dateStr < $b['check_out']) $dayStays[] = $b;
        }
      ?>
      <div class="week-day <?= $isToday?'today':'' ?> <?= $hasDemand?'has-demand':'' ?>" onclick="window.location.href='<?= $calBase ?>&view=day&date=<?= $dateStr ?>'" style="cursor:pointer" title="View <?= date('d M', strtotime($dateStr)) ?> details">
        <div class="wd-hdr">
          <div class="wd-dow"><?= date('D', strtotime($dateStr)) ?></div>
          <div class="wd-date"><?= date('d', strtotime($dateStr)) ?></div>
          <div style="font-size:.68rem;color:var(--text-muted)"><?= date('M', strtotime($dateStr)) ?></div>
        </div>
        <?php foreach ($dayDemand as $de): ?>
          <div class="week-demand" style="<?= demandBadgeStyle($de['demand_level']) ?>;margin-bottom:.2rem">
            <?= demandIcon($de['demand_level']) ?> <?= htmlspecialchars(substr($de['event_name'],0,18)) ?>
          </div>
        <?php endforeach; ?>
        <?php foreach ($dayCheckins as $b): ?>
          <div class="week-event week-checkin">↘ <?= htmlspecialchars(substr($b['guest_name'],0,12)) ?></div>
        <?php endforeach; ?>
        <?php foreach ($dayStays as $b): ?>
          <div class="week-event week-stay">• <?= htmlspecialchars(substr($b['room_name'],0,12)) ?></div>
        <?php endforeach; ?>
        <?php foreach ($dayCheckouts as $b): ?>
          <div class="week-event week-checkout">↗ <?= htmlspecialchars(substr($b['guest_name'],0,12)) ?></div>
        <?php endforeach; ?>
      </div>
      <?php endfor; ?>
    </div>

    <!-- 7-day detail table -->
    <div style="margin-top:1.25rem">
      <table class="tbl">
        <thead><tr><th>Date</th><th>Check-ins</th><th>In-Stay</th><th>Check-outs</th><th>Demand Event</th></tr></thead>
        <tbody>
          <?php for ($d = 0; $d < 7; $d++):
            $dateStr = date('Y-m-d', strtotime("+$d days", $weekStartTs));
            $ci = array_filter($confirmed, fn($b) => $b['check_in'] === $dateStr);
            $co = array_filter($confirmed, fn($b) => $b['check_out'] === $dateStr);
            $st = array_filter($confirmed, fn($b) => $dateStr > $b['check_in'] && $dateStr < $b['check_out']);
            $dem = $demandByDate[$dateStr] ?? [];
          ?>
          <tr <?= $dateStr===date('Y-m-d')?'style="background:#f0faf3"':'' ?>>
            <td style="font-weight:700"><?= date('D d M', strtotime($dateStr)) ?></td>
            <td>
              <?php foreach ($ci as $b): ?>
                <span style="font-size:.78rem">↘ <?= htmlspecialchars($b['guest_name']) ?> (<?= htmlspecialchars($b['room_name']) ?>)<br></span>
              <?php endforeach; ?>
              <?php if (empty($ci)): ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td><span class="muted"><?= count($st) ?> room<?= count($st)!==1?'s':'' ?></span></td>
            <td>
              <?php foreach ($co as $b): ?>
                <span style="font-size:.78rem">↗ <?= htmlspecialchars($b['guest_name']) ?><br></span>
              <?php endforeach; ?>
              <?php if (empty($co)): ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td>
              <?php foreach ($dem as $de): ?>
                <span style="<?= demandBadgeStyle($de['demand_level']) ?>;padding:.1rem .4rem;border-radius:4px;font-size:.7rem;font-weight:700;margin-right:.25rem"><?= demandIcon($de['demand_level']) ?> <?= htmlspecialchars(substr($de['event_name'],0,25)) ?></span>
              <?php endforeach; ?>
            </td>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($calView === 'month'): ?>

<?php
  // Month view data
  $firstDay    = mktime(0,0,0,$calMonth,1,$calYear);
  $daysInMonth = (int)date('t', $firstDay);
  $startDow    = (int)date('N', $firstDay);
  $prevMonth   = $calMonth-1 < 1  ? 12 : $calMonth-1;
  $prevYearM   = $calMonth-1 < 1  ? $calYear-1 : $calYear;
  $nextMonth   = $calMonth+1 > 12 ? 1  : $calMonth+1;
  $nextYearM   = $calMonth+1 > 12 ? $calYear+1 : $calYear;
  $monthBookingDates = [];
  $monthBookingSrc   = [];
  foreach ($confirmed as $b) {
    $ci = strtotime($b['check_in']); $co = strtotime($b['check_out']);
    for ($t=$ci; $t<$co; $t+=86400) {
      if (date('n',$t)==$calMonth && date('Y',$t)==$calYear) {
        $ds = date('Y-m-d',$t);
        $monthBookingDates[$ds] = true;
        $monthBookingSrc[$ds][] = $b['source'];
      }
    }
  }
?>

<!-- Month view: big calendar + gantt below -->
<div class="panel" style="margin-bottom:1.25rem">
  <div class="panel-hd">
    <h3>🗓️ <?= date('F Y', $firstDay) ?></h3>
    <div style="display:flex;gap:.5rem;align-items:center">
      <a class="day-btn" href="<?= $calBase ?>&view=month&cm=<?= $prevMonth ?>&cy=<?= $prevYearM ?>">← <?= date('M Y', mktime(0,0,0,$prevMonth,1,$prevYearM)) ?></a>
      <a class="day-btn" href="<?= $calBase ?>&view=month&cm=<?= date('n') ?>&cy=<?= date('Y') ?>">Today's Month</a>
      <a class="day-btn" href="<?= $calBase ?>&view=month&cm=<?= $nextMonth ?>&cy=<?= $nextYearM ?>"><?= date('M Y', mktime(0,0,0,$nextMonth,1,$nextYearM)) ?> →</a>
    </div>
  </div>
  <div class="panel-bd" style="padding:.75rem">
    <!-- Large month calendar -->
    <table style="width:100%;border-collapse:collapse;table-layout:fixed">
      <thead>
        <tr><?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $dname): ?>
          <th style="text-align:center;padding:.5rem;font-size:.78rem;font-weight:700;color:var(--text-muted);border-bottom:2px solid var(--border)"><?= substr($dname,0,3) ?></th>
        <?php endforeach; ?></tr>
      </thead>
      <tbody>
      <?php
        $col = 0; echo '<tr>';
        for ($i=1; $i<$startDow; $i++) { echo '<td style="padding:.25rem;height:80px;border:1px solid var(--border);background:#fafafa"></td>'; $col++; }
        for ($day=1; $day<=$daysInMonth; $day++) {
          $ds = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $day);
          $isToday = $ds === date('Y-m-d');
          $hasBk   = isset($monthBookingDates[$ds]);
          $isDem   = !empty($demandByDate[$ds]);
          $isPast  = $ds < date('Y-m-d');
          $srcs    = $monthBookingSrc[$ds] ?? [];
          $topSrc  = $srcs[0] ?? null;
          $bgCell  = $isToday ? '#f0faf3' : ($isPast ? '#fafafa' : '#fff');
          $topColor = $topSrc ? sourceColor($topSrc) : 'transparent';
          echo '<td onclick="window.location.href=\''.htmlspecialchars($calBase).'&view=day&date='.$ds.'\'" '
            .'style="padding:.25rem;height:80px;border:1px solid var(--border);background:'.$bgCell.';cursor:pointer;vertical-align:top;transition:background .12s" '
            .'onmouseover="this.style.background=\'#f0faf3\'" onmouseout="this.style.background=\''.$bgCell.'\'">';
          // Day number pill
          $numStyle = $isToday ? 'background:var(--primary);color:#fff;border-radius:50%;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem' : 'font-size:.8rem;font-weight:'.($hasBk?'700':'400').';color:'.($isPast?'var(--text-muted)':'var(--text)').'';
          echo '<div style="'.$numStyle.'">'.$day.'</div>';
          // Colored bar for bookings
          if ($hasBk) {
            foreach (array_unique($srcs) as $s) {
              echo '<div style="margin-top:2px;padding:1px 4px;border-radius:3px;background:'.sourceColor($s).';color:#fff;font-size:.62rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'.sourceName($s).'</div>';
            }
          }
          // Demand badge
          if ($isDem) {
            $de0 = $demandByDate[$ds][0];
            echo '<div style="margin-top:2px;font-size:.58rem">'.demandIcon($de0['demand_level']).' '.htmlspecialchars(substr($de0['event_name'],0,10)).'</div>';
          }
          echo '</td>';
          $col++;
          if ($col%7===0 && $day<$daysInMonth) echo '</tr><tr>';
        }
        // Fill trailing cells
        $rem = 7 - ($col % 7);
        if ($rem < 7) for ($i=0; $i<$rem; $i++) echo '<td style="padding:.25rem;height:80px;border:1px solid var(--border);background:#fafafa"></td>';
        echo '</tr>';
      ?>
      </tbody>
    </table>
    <!-- Legend -->
    <div style="margin-top:.75rem;display:flex;gap:1rem;flex-wrap:wrap;font-size:.73rem;color:var(--text-muted);align-items:center">
      <?php foreach (['#FF5A5F'=>'Airbnb','#003580'=>'Booking.com','#EB1A23'=>'Agoda','#2e7d32'=>'Direct','#e53e3e'=>'Blocked'] as $col2 => $lbl2): ?>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:<?= $col2 ?>;margin-right:.3rem"></span><?= $lbl2 ?></span>
      <?php endforeach; ?>
      <span>⭐ = Demand event</span>
    </div>
  </div>
</div>

<!-- Gantt + stats side by side -->
<div class="dash-grid">
  <div style="grid-column:span 2">
<!-- Demand legend -->
<div class="gantt-legend" style="margin-bottom:.5rem">
  <?php foreach ([
    '#FF5A5F'=>'Airbnb','#003580'=>'Booking.com','#EB1A23'=>'Agoda',
    '#E8262D'=>'MakeMyTrip','#2e7d32'=>'Direct','#e53e3e'=>'Blocked',
  ] as $col => $lbl): ?>
    <span class="gl-item"><span class="gl-dot" style="background:<?= $col ?>"></span><?= $lbl ?></span>
  <?php endforeach; ?>
  <span class="gl-item"><span class="gl-dot" style="background:#fef3c7;border:1px solid #f59e0b"></span>🥇 Gold Date</span>
  <span class="gl-item"><span class="gl-dot" style="background:#fee2e2;border:1px solid #ef4444"></span>🔥 High Demand</span>
  <span class="gl-item"><span class="gl-dot" style="background:#dbeafe;border:1px solid #3b82f6"></span>📈 Holiday</span>
</div>

<div class="gantt-wrap">
  <table class="gantt-table">
    <thead>
      <tr>
        <th class="gantt-room-col"><div class="gantt-hdr-room">Property</div></th>
        <?php foreach ($ganttDates as $gd):
          $dow = date('D', strtotime($gd));
          $isToday = $gd === date('Y-m-d');
          $isWeekend = in_array($dow, ['Sat','Sun']);
          $dem = $demandByDate[$gd] ?? [];
          $demLevel = '';
          foreach ($dem as $de) { if ($de['demand_level']==='gold'){$demLevel='gold';break;} elseif($de['demand_level']==='high' && $demLevel!=='gold'){$demLevel='high';} elseif($de['demand_level']==='medium' && !$demLevel){$demLevel='medium';} }
          $cls = $isToday ? 'today-col' : ($isWeekend ? 'weekend-col' : '');
          if ($demLevel) $cls = "demand-$demLevel";
        ?>
        <th class="gantt-day-col <?= $cls ?>">
          <div class="gantt-hdr-day <?= $cls ?>">
            <span class="d-num"><?= date('d', strtotime($gd)) ?></span>
            <span class="d-dow"><?= substr($dow,0,2) ?></span>
            <?php if ($demLevel): ?><span class="d-dem"><?= demandIcon($demLevel) ?></span><?php endif; ?>
          </div>
        </th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rooms as $rid => $rname):
        $rb = $bookingsByRoom[$rid] ?? [];
      ?>
      <tr>
        <td class="gantt-room-label"><?= htmlspecialchars($rname) ?></td>
        <?php foreach ($ganttDates as $gd):
          $booking = bookingOnDay($rb, $gd);
          $isPast = $gd < date('Y-m-d');
          $dow = date('D', strtotime($gd));
          $isToday = $gd === date('Y-m-d');
          $isWeekend = in_array($dow, ['Sat','Sun']);
          $dem = $demandByDate[$gd] ?? [];
          $topDem = '';
          foreach ($dem as $de) { if (!$topDem || ($de['demand_level']==='gold')){$topDem=$de['demand_level'];} }
          if ($booking):
            $src = $booking['source'];
            $col = sourceColor($src);
            $srcAbbr = strtoupper(substr($src,0,3));
            $cls2 = $isPast ? 'gantt-day-past booked-past' : '';
            $bJson = bookingJson($booking);
        ?>
          <td class="gantt-booking <?= $cls2 ?>" onclick="showBookingModal(<?= $bJson ?>)" style="cursor:pointer;<?= $isPast?'opacity:.45':'' ?>" title="<?= htmlspecialchars($booking['guest_name']) ?> | <?= sourceName($src) ?> | <?= $booking['check_in'] ?>→<?= $booking['check_out'] ?> (Click to view/edit)">
            <div class="gantt-bk-inner" style="background:<?= $col ?>">
              <span class="bk-guest"><?= htmlspecialchars(substr($booking['guest_name'],0,10)) ?></span>
              <span class="bk-src"><?= $srcAbbr ?></span>
            </div>
          </td>
        <?php else:
          $freeCls = 'gantt-day-free';
          if ($isToday) $freeCls .= ' today-day';
          elseif ($isWeekend) $freeCls .= ' weekend-day';
          if ($topDem) $freeCls .= " demand-$topDem";
        ?>
          <td class="<?= $freeCls ?>" title="<?= $rname ?> — <?= $gd ?> Free<?= $topDem?' ('.strtoupper($topDem).' DEMAND)':'' ?>"></td>
        <?php endif; ?>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

  </div><!-- end span 2 gantt col -->

  <!-- Side stats for month view -->
  <div>
    <div class="panel" style="margin-bottom:1rem">
      <div class="panel-hd"><h3>📡 Platform Breakdown</h3><span class="sub">All confirmed</span></div>
      <div class="panel-bd">
        <div class="platform-grid" style="margin-bottom:.5rem">
          <?php foreach (array_filter($byPlatform, fn($cnt, $src) => $src !== 'blocked', ARRAY_FILTER_USE_BOTH) as $src => $cnt): ?>
          <div class="platform-card" style="background:<?= sourceColor($src) ?>">
            <div class="pc-count"><?= $cnt ?></div>
            <div class="pc-name"><?= sourceName($src) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-hd"><h3>🏠 Room Occupancy (30d)</h3></div>
      <div class="panel-bd">
        <?php foreach ($rooms as $rid => $rname):
          $roomBookings = array_filter($confirmed, fn($b) => $b['room_id'] === $rid && $b['source'] !== 'blocked');
          $rn = 0;
          foreach ($roomBookings as $b) $rn += max(0, (int)ceil((strtotime($b['check_out'])-strtotime($b['check_in']))/86400));
          $rpct = min(100, round($rn / 30 * 100));
        ?>
        <div class="occ-row">
          <span class="occ-lbl"><?= htmlspecialchars($rname) ?></span>
          <div class="occ-bar-wrap"><div class="occ-bar" style="width:<?= $rpct ?>%"></div></div>
          <span class="occ-pct"><?= $rpct ?>%</span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div><!-- end dash-grid month view -->

<?php elseif ($calView === 'year'): ?>
<?php
  // Year view
  $yvPrev = $calYearView - 1;
  $yvNext = $calYearView + 1;
  // Pre-index bookings by date
  $yrBkDates = []; $yrBkSrc = [];
  foreach ($confirmed as $b) {
    $ci = strtotime($b['check_in']); $co = strtotime($b['check_out']);
    for ($t=$ci; $t<$co; $t+=86400) {
      if (date('Y',$t) == $calYearView) {
        $ds = date('Y-m-d',$t);
        $yrBkDates[$ds] = true;
        $yrBkSrc[$ds]   = $b['source'];
      }
    }
  }
  $yrDemDates = [];
  foreach ($demandByDate as $ds => $des) {
    if (substr($ds,0,4) == $calYearView) $yrDemDates[$ds] = $des[0]['demand_level'] ?? 'medium';
  }
  // Year stats
  $yrTotal = 0; $yrRev = 0; $yrNights = 0;
  foreach ($confirmedBookings as $b) {
    if (substr($b['check_in'],0,4) == $calYearView && $b['source'] !== 'blocked') {
      $yrTotal++; $yrRev += $b['amount'];
      $yrNights += max(0,(int)ceil((strtotime($b['check_out'])-strtotime($b['check_in']))/86400));
    }
  }
?>

<!-- Year navigation + stats -->
<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;flex-wrap:wrap">
  <div style="display:flex;align-items:center;gap:.5rem">
    <a class="day-btn" href="<?= $calBase ?>&view=year&cy=<?= $yvPrev ?>">← <?= $yvPrev ?></a>
    <span style="font-size:1.4rem;font-weight:800;color:var(--text)"><?= $calYearView ?></span>
    <a class="day-btn" href="<?= $calBase ?>&view=year&cy=<?= $yvNext ?>"><?= $yvNext ?> →</a>
    <?php if ($calYearView != date('Y')): ?>
    <a class="day-btn" href="<?= $calBase ?>&view=year&cy=<?= date('Y') ?>">This Year</a>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:1rem;flex-wrap:wrap">
    <div class="day-stat"><div class="ds-val"><?= $yrTotal ?></div><div class="ds-lbl">Bookings <?= $calYearView ?></div></div>
    <div class="day-stat"><div class="ds-val"><?= $yrNights ?></div><div class="ds-lbl">Nights Booked</div></div>
    <div class="day-stat"><div class="ds-val"><?= fmt($yrRev) ?></div><div class="ds-lbl">Revenue</div></div>
  </div>
</div>

<!-- Year legend -->
<div class="year-legend">
  <span><span class="yl-dot" style="background:#bbf7d0"></span>Booked</span>
  <span><span class="yl-dot" style="background:#fecaca"></span>Blocked</span>
  <span><span class="yl-dot" style="background:var(--primary);border-radius:50%"></span>Today</span>
  <span><span class="yl-dot" style="outline:2px solid #f59e0b;outline-offset:-2px;background:transparent"></span>Demand event</span>
</div>

<!-- 12-month grid -->
<div class="year-grid">
<?php for ($mo = 1; $mo <= 12; $mo++):
  $moFirst = mktime(0,0,0,$mo,1,$calYearView);
  $moDays  = (int)date('t', $moFirst);
  $moStartDow = (int)date('N', $moFirst);
  $isCurrentMonth = ($mo == date('n') && $calYearView == date('Y'));
?>
  <div class="year-month-card">
    <div class="ymc-hd"><?= date('F Y', $moFirst) ?></div>
    <div class="ymc-body">
      <table class="ymc-cal">
        <thead><tr><?php foreach(['M','T','W','T','F','S','S'] as $dh): ?><th><?= $dh ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php
          $mc = 0; echo '<tr>';
          for ($i=1; $i<$moStartDow; $i++) { echo '<td></td>'; $mc++; }
          for ($md=1; $md<=$moDays; $md++) {
            $mds = sprintf('%04d-%02d-%02d',$calYearView,$mo,$md);
            $mIsToday   = $mds === date('Y-m-d');
            $mIsBooked  = isset($yrBkDates[$mds]);
            $mIsBlocked = $mIsBooked && (($yrBkSrc[$mds] ?? '') === 'blocked');
            $mIsDem     = isset($yrDemDates[$mds]);
            $mIsPast    = $mds < date('Y-m-d');
            $cls = 'ydc';
            if ($mIsToday)        $cls .= ' ydc-today';
            elseif ($mIsBlocked)  $cls .= ' ydc-blocked'.($mIsPast?' ydc-past':'');
            elseif ($mIsBooked)   $cls .= ' ydc-booked'.($mIsPast?' ydc-past':'');
            elseif ($mIsPast)     $cls .= ' ydc-past';
            if ($mIsDem && !$mIsToday) $cls .= ' ydc-demand';
            $nav = htmlspecialchars($calBase).'&view=day&date='.$mds;
            echo '<td><div class="'.$cls.'" onclick="window.location.href=\''.$nav.'\'" title="'.$mds.'">'.$md.'</div></td>';
            $mc++;
            if ($mc%7===0&&$md<$moDays) echo '</tr><tr>';
          }
          echo '</tr>';
        ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endfor; ?>
</div><!-- end year-grid -->

<?php endif; // end calView branches ?>

<!-- ══════════════════════════════════════════════
     BLOCKED DATES
════════════════════════════════════════════════ -->
<?php elseif ($section === 'blocked'): ?>

<?php
// ── Blocked dates data ───────────────────────────────────────────
$bview = $_GET['bv'] ?? 'week';   // week | month | quarter

// Week nav
$bwRaw   = $_GET['bws'] ?? date('Y-m-d', strtotime('monday this week'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bwRaw)) $bwRaw = date('Y-m-d', strtotime('monday this week'));
$bwStartTs  = strtotime($bwRaw);
$bwPrev     = date('Y-m-d', strtotime('-7 days', $bwStartTs));
$bwNext     = date('Y-m-d', strtotime('+7 days', $bwStartTs));

// Month nav
$bmMonth    = (int)($_GET['bm'] ?? date('n'));
$bmYear     = (int)($_GET['by'] ?? date('Y'));
if ($bmMonth < 1) { $bmMonth = 12; $bmYear--; }
if ($bmMonth > 12) { $bmMonth = 1; $bmYear++; }
$bmPrevM    = $bmMonth-1 < 1  ? 12 : $bmMonth-1;
$bmPrevY    = $bmMonth-1 < 1  ? $bmYear-1 : $bmYear;
$bmNextM    = $bmMonth+1 > 12 ? 1  : $bmMonth+1;
$bmNextY    = $bmMonth+1 > 12 ? $bmYear+1 : $bmYear;

// Quarter nav (Q1–Q4)
$bqYear     = (int)($_GET['bqy'] ?? date('Y'));
$bqNum      = (int)($_GET['bqn'] ?? ceil(date('n') / 3));
if ($bqNum < 1) { $bqNum = 4; $bqYear--; }
if ($bqNum > 4) { $bqNum = 1; $bqYear++; }
$bqPrevN    = $bqNum-1 < 1 ? 4 : $bqNum-1;
$bqPrevY    = $bqNum-1 < 1 ? $bqYear-1 : $bqYear;
$bqNextN    = $bqNum+1 > 4 ? 1 : $bqNum+1;
$bqNextY    = $bqNum+1 > 4 ? $bqYear+1 : $bqYear;

// Build occupancy lookup: $occ[$roomId][$date] = booking
$occ = [];
foreach ($confirmed as $b) {
    $ci = strtotime($b['check_in']); $co = strtotime($b['check_out']);
    for ($t = $ci; $t < $co; $t += 86400) {
        $ds = date('Y-m-d', $t);
        $occ[$b['room_id']][$ds] = $b;
    }
}

// Manual blocks only (for the list table)
$blockedBookings = array_values(array_filter($allBookings, fn($b) => $b['source'] === 'blocked'));

// Summary counts
$today = date('Y-m-d');
$blocksActive   = 0; $blocksUpcoming = 0; $totalBlockedNights = 0;
foreach ($blockedBookings as $b) {
    $n = (int)ceil((strtotime($b['check_out'])-strtotime($b['check_in']))/86400);
    $totalBlockedNights += $n;
    if ($b['check_in'] <= $today && $b['check_out'] > $today) $blocksActive++;
    elseif ($b['check_in'] > $today) $blocksUpcoming++;
}

// All blocked days across all sources (for visual views)
$baseUrl = 'admin.php?section=blocked';

// Source → short label
function srcShort(string $src): string {
    return match($src) {
        'airbnb' => 'AIR', 'booking.com','booking' => 'BK', 'agoda' => 'AGO',
        'makemytrip' => 'MMT', 'direct','phone','whatsapp','walk_in' => 'DIR',
        'blocked' => 'BLK', default => strtoupper(substr($src,0,3))
    };
}
?>

<!-- ── Quick block form ──────────────────────────────────────── -->
<div class="panel" style="margin-bottom:1.25rem">
  <div class="panel-hd">
    <div><h3>🚫 Block Dates</h3><div class="sub">Block a property instantly — syncs to all OTA channels via iCal</div></div>
    <button type="button" class="btn btn-grey btn-sm" onclick="togglePanel('blkFormPanel')">▲ Collapse</button>
  </div>
  <div class="panel-bd" id="blkFormPanel">
    <form method="POST">
    <?= csrfField() ?>
      <input type="hidden" name="action" value="add_booking">
      <input type="hidden" name="source" value="blocked">
      <input type="hidden" name="guest_name" value="Blocked">
      <input type="hidden" name="amount" value="0">
      <input type="hidden" name="amount_paid" value="0">
      <input type="hidden" name="payment_method" value="cash">
      <div class="form-row-3" style="margin-bottom:.75rem">
        <div class="fld">
          <label>Property</label>
          <select name="room_id" required>
            <?php foreach ($rooms as $rid => $rname): ?>
            <option value="<?= htmlspecialchars($rid) ?>"><?= htmlspecialchars($rname) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fld"><label>Block From</label><input type="date" name="check_in" required min="<?= date('Y-m-d') ?>"></div>
        <div class="fld"><label>Block Until</label><input type="date" name="check_out" required min="<?= date('Y-m-d') ?>"></div>
      </div>
      <div class="fld" style="margin-bottom:1rem">
        <label>Reason (optional)</label>
        <input type="text" name="notes" placeholder="e.g. Owner stay, Maintenance, Deep cleaning…">
      </div>
      <button type="submit" class="btn btn-danger">🚫 Block These Dates</button>
    </form>
  </div>
</div>

<!-- ── Summary stats ────────────────────────────────────────── -->
<div class="stats-row" style="margin-bottom:1.25rem">
  <?php
  // Count all occupied (booked) nights in next 90 days across all rooms
  $occNights90 = 0; $freeNights90 = 0; $totalSlots90 = count($rooms) * 90;
  $d90end = date('Y-m-d', strtotime('+90 days'));
  for ($t = strtotime($today); $t < strtotime($d90end); $t += 86400) {
    $ds = date('Y-m-d',$t);
    foreach ($rooms as $rid => $_) {
      if (isset($occ[$rid][$ds])) $occNights90++; else $freeNights90++;
    }
  }
  $occPct90 = $totalSlots90 > 0 ? round($occNights90/$totalSlots90*100) : 0;
  ?>
  <div class="stat-card"><div class="stat-icon">🔴</div><div class="stat-val"><?= $blocksActive ?></div><div class="stat-lbl">Active Blocks Today</div></div>
  <div class="stat-card"><div class="stat-icon">📅</div><div class="stat-val"><?= $blocksUpcoming ?></div><div class="stat-lbl">Upcoming Blocks</div></div>
  <div class="stat-card"><div class="stat-icon">🌙</div><div class="stat-val"><?= $totalBlockedNights ?></div><div class="stat-lbl">Manual Block Nights</div></div>
  <div class="stat-card"><div class="stat-icon">🏠</div><div class="stat-val"><?= $occPct90 ?>%</div><div class="stat-lbl">Occupancy (Next 90d)</div></div>
</div>

<!-- ── View tabs ─────────────────────────────────────────────── -->
<div class="cal-view-tabs" style="margin-bottom:1rem">
  <a class="cvt-btn <?= $bview==='week'    ? 'active' : '' ?>" href="<?= $baseUrl ?>&bv=week&bws=<?= $bwRaw ?>">📅 Weekly</a>
  <a class="cvt-btn <?= $bview==='month'   ? 'active' : '' ?>" href="<?= $baseUrl ?>&bv=month&bm=<?= $bmMonth ?>&by=<?= $bmYear ?>">🗓️ Monthly</a>
  <a class="cvt-btn <?= $bview==='quarter' ? 'active' : '' ?>" href="<?= $baseUrl ?>&bv=quarter&bqy=<?= $bqYear ?>&bqn=<?= $bqNum ?>">📊 Quarterly</a>
</div>

<!-- ── Source legend ────────────────────────────────────────── -->
<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;font-size:.75rem;align-items:center">
  <?php foreach (['airbnb'=>'Airbnb','booking.com'=>'Booking.com','agoda'=>'Agoda','direct'=>'Direct','blocked'=>'Blocked'] as $s => $sl): ?>
  <span style="display:flex;align-items:center;gap:.35rem">
    <span style="width:14px;height:14px;border-radius:3px;background:<?= sourceColor($s) ?>;display:inline-block"></span><?= $sl ?>
  </span>
  <?php endforeach; ?>
  <span style="display:flex;align-items:center;gap:.35rem">
    <span style="width:14px;height:14px;border-radius:3px;background:#e5e7eb;display:inline-block"></span>Free
  </span>
</div>

<?php if ($bview === 'week'): ?>
<!-- ══ WEEKLY VIEW ════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.85rem;flex-wrap:wrap">
  <a class="day-btn" href="<?= $baseUrl ?>&bv=week&bws=<?= $bwPrev ?>">← <?= date('d M', strtotime($bwPrev)) ?></a>
  <span style="font-weight:700;font-size:.95rem"><?= date('d M Y', $bwStartTs) ?> — <?= date('d M Y', strtotime('+6 days',$bwStartTs)) ?></span>
  <a class="day-btn" href="<?= $baseUrl ?>&bv=week&bws=<?= $bwNext ?>"><?= date('d M', strtotime($bwNext)) ?> →</a>
  <a class="day-btn" href="<?= $baseUrl ?>&bv=week&bws=<?= date('Y-m-d',strtotime('monday this week')) ?>" style="margin-left:auto">This Week</a>
</div>

<div class="panel" style="margin-bottom:1.25rem">
  <div class="panel-bd" style="padding:0;overflow-x:auto">
    <?php
    $wDays = [];
    for ($d = 0; $d < 7; $d++) $wDays[] = date('Y-m-d', strtotime("+$d days", $bwStartTs));
    ?>
    <table style="width:100%;border-collapse:collapse;min-width:600px">
      <thead>
        <tr style="background:#f8fafc">
          <th style="padding:.6rem .75rem;text-align:left;font-size:.78rem;border-bottom:2px solid var(--border);min-width:140px">Property</th>
          <?php foreach ($wDays as $wd): ?>
          <?php $isWdToday = $wd === $today; $dow = date('D',$bwStartTs+86400*array_search($wd,$wDays)); ?>
          <th style="padding:.6rem .4rem;text-align:center;font-size:.75rem;border-bottom:2px solid var(--border);<?= $isWdToday?'background:#f0faf3':'' ?>;<?= in_array($dow,['Sat','Sun'])?'background:#fafaf5':'' ?>">
            <div style="font-weight:700"><?= date('D',strtotime($wd)) ?></div>
            <div style="font-size:.68rem;color:var(--text-muted)"><?= date('d M',strtotime($wd)) ?></div>
            <?php if ($isWdToday): ?><div style="font-size:.6rem;color:var(--primary);font-weight:700">TODAY</div><?php endif; ?>
          </th>
          <?php endforeach; ?>
          <th style="padding:.6rem;text-align:center;font-size:.75rem;border-bottom:2px solid var(--border)">Free Days</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rooms as $rid => $rname):
        $freeCnt = 0;
      ?>
        <tr>
          <td style="padding:.5rem .75rem;font-size:.8rem;font-weight:600;border-bottom:1px solid var(--border);white-space:nowrap"><?= htmlspecialchars($rname) ?></td>
          <?php foreach ($wDays as $wd):
            $bk = $occ[$rid][$wd] ?? null;
            $isWdToday = $wd === $today;
            $cellBg = $isWdToday ? '#f0faf3' : '#fff';
          ?>
          <td style="padding:.35rem .25rem;text-align:center;border-bottom:1px solid var(--border);border-left:1px solid #f0f0f0;background:<?= $cellBg ?>">
            <?php if ($bk):
              $sc = sourceColor($bk['source']);
            ?>
            <div style="background:<?= $sc ?>;color:#fff;border-radius:5px;padding:.2rem .35rem;font-size:.65rem;font-weight:700;line-height:1.3;cursor:default"
              title="<?= htmlspecialchars($bk['guest_name']) ?> (<?= $bk['source'] ?>) <?= $bk['check_in'] ?>→<?= $bk['check_out'] ?>">
              <?= srcShort($bk['source']) ?><br>
              <span style="font-weight:400;font-size:.6rem"><?= htmlspecialchars(substr($bk['guest_name'],0,7)) ?></span>
            </div>
            <?php else: $freeCnt++; ?>
            <div style="width:100%;height:28px;background:#f3f4f6;border-radius:4px"></div>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
          <td style="text-align:center;font-weight:700;font-size:.85rem;border-bottom:1px solid var(--border);color:<?= $freeCnt===7?'#16a34a':($freeCnt===0?'#e53e3e':'#92400e') ?>">
            <?= $freeCnt ?>/7
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Weekly summary table -->
<div class="panel">
  <div class="panel-hd"><h3>📊 Week Summary — Bookings by Platform</h3></div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Day</th><th>Free Rooms</th><th>Airbnb</th><th>Booking.com</th><th>Agoda</th><th>Direct/Phone</th><th>Blocked</th></tr></thead>
      <tbody>
        <?php foreach ($wDays as $wd):
          $srcCount = [];
          $freeRms = 0;
          foreach ($rooms as $rid => $_) {
            $bk = $occ[$rid][$wd] ?? null;
            if ($bk) $srcCount[$bk['source']] = ($srcCount[$bk['source']] ?? 0) + 1;
            else $freeRms++;
          }
          $isWdToday = $wd === $today;
        ?>
        <tr style="<?= $isWdToday?'background:#f0faf3':'' ?>">
          <td style="font-weight:700"><?= date('d M Y',strtotime($wd)) ?></td>
          <td class="muted"><?= date('D',strtotime($wd)) ?></td>
          <td style="font-weight:700;color:<?= $freeRms===count($rooms)?'#16a34a':($freeRms===0?'#e53e3e':'#92400e') ?>"><?= $freeRms ?> / <?= count($rooms) ?></td>
          <td><?= $srcCount['airbnb'] ?? 0 ?></td>
          <td><?= ($srcCount['booking.com'] ?? 0) + ($srcCount['booking'] ?? 0) ?></td>
          <td><?= $srcCount['agoda'] ?? 0 ?></td>
          <td><?= ($srcCount['direct'] ?? 0) + ($srcCount['phone'] ?? 0) + ($srcCount['whatsapp'] ?? 0) + ($srcCount['walk_in'] ?? 0) ?></td>
          <td><?= $srcCount['blocked'] ?? 0 ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($bview === 'month'): ?>
<!-- ══ MONTHLY VIEW ══════════════════════════════════════════ -->
<?php
  $bmFirst   = mktime(0,0,0,$bmMonth,1,$bmYear);
  $bmDays    = (int)date('t',$bmFirst);
  $bmStartDow= (int)date('N',$bmFirst);
?>
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.85rem;flex-wrap:wrap">
  <a class="day-btn" href="<?= $baseUrl ?>&bv=month&bm=<?= $bmPrevM ?>&by=<?= $bmPrevY ?>">← <?= date('M Y',mktime(0,0,0,$bmPrevM,1,$bmPrevY)) ?></a>
  <span style="font-weight:700;font-size:.95rem"><?= date('F Y',$bmFirst) ?></span>
  <a class="day-btn" href="<?= $baseUrl ?>&bv=month&bm=<?= $bmNextM ?>&by=<?= $bmNextY ?>"><?= date('M Y',mktime(0,0,0,$bmNextM,1,$bmNextY)) ?> →</a>
  <a class="day-btn" href="<?= $baseUrl ?>&bv=month&bm=<?= date('n') ?>&by=<?= date('Y') ?>" style="margin-left:auto">This Month</a>
</div>

<!-- Gantt-style: rooms as rows, dates as cols -->
<div class="panel" style="margin-bottom:1.25rem">
  <div class="panel-bd" style="padding:0;overflow-x:auto">
    <table style="border-collapse:collapse;white-space:nowrap">
      <thead>
        <tr style="background:#f8fafc">
          <th style="padding:.6rem .75rem;text-align:left;font-size:.75rem;border-bottom:2px solid var(--border);min-width:140px;position:sticky;left:0;background:#f8fafc;z-index:2">Property</th>
          <?php for ($d=1; $d<=$bmDays; $d++):
            $ds = sprintf('%04d-%02d-%02d',$bmYear,$bmMonth,$d);
            $dow = date('D',strtotime($ds));
            $isWknd = in_array($dow,['Sat','Sun']);
            $isToday2 = $ds === $today;
          ?>
          <th style="padding:.35rem .2rem;text-align:center;font-size:.65rem;width:28px;border-bottom:2px solid var(--border);<?= $isToday2?'background:#f0faf3;color:var(--primary);font-weight:700':($isWknd?'background:#fafaf5':'') ?>">
            <div><?= $d ?></div>
            <div style="font-size:.55rem;color:var(--text-muted)"><?= substr($dow,0,2) ?></div>
          </th>
          <?php endfor; ?>
          <th style="padding:.6rem;font-size:.75rem;border-bottom:2px solid var(--border);text-align:center">Occ %</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rooms as $rid => $rname):
        $bookedDays = 0;
      ?>
        <tr>
          <td style="padding:.4rem .75rem;font-size:.78rem;font-weight:600;border-bottom:1px solid var(--border);position:sticky;left:0;background:#fff;z-index:1"><?= htmlspecialchars($rname) ?></td>
          <?php for ($d=1; $d<=$bmDays; $d++):
            $ds = sprintf('%04d-%02d-%02d',$bmYear,$bmMonth,$d);
            $bk = $occ[$rid][$ds] ?? null;
            $isToday2 = $ds === $today;
            $dow = date('D',strtotime($ds));
            $isWknd = in_array($dow,['Sat','Sun']);
            $bg = $bk ? sourceColor($bk['source']) : ($isToday2?'#d1fae5':($isWknd?'#f5f5f0':'#f3f4f6'));
            $tc = $bk ? '#fff' : '#999';
            if ($bk) $bookedDays++;
            // Check if this is check-in day
            $isCI = $bk && $bk['check_in'] === $ds;
            $isCO = $bk && $bk['check_out'] === $ds;
          ?>
          <td style="padding:.25rem .1rem;border-bottom:1px solid var(--border);border-left:1px solid #f0f0f0;text-align:center" title="<?= $ds ?><?= $bk?' — '.htmlspecialchars($bk['guest_name']).' ('.$bk['source'].')':' — Free' ?>">
            <div style="width:24px;height:22px;border-radius:<?= $isCI?'5px 2px 2px 5px':($isCO?'2px 5px 5px 2px':'2px') ?>;background:<?= $bg ?>;margin:0 auto;font-size:.55rem;color:<?= $tc ?>;display:flex;align-items:center;justify-content:center;font-weight:700">
              <?= $bk ? srcShort($bk['source']) : '' ?>
            </div>
          </td>
          <?php endfor; ?>
          <td style="text-align:center;font-weight:700;font-size:.82rem;border-bottom:1px solid var(--border);color:<?= $bookedDays>=$bmDays*0.8?'#e53e3e':($bookedDays>=$bmDays*0.5?'#92400e':'#16a34a') ?>">
            <?= round($bookedDays/$bmDays*100) ?>%
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Monthly breakdown by platform -->
<div class="panel">
  <div class="panel-hd"><h3>📡 <?= date('F Y',$bmFirst) ?> — Platform Breakdown</h3></div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Property</th><th>Total Days</th><th>Airbnb</th><th>Booking.com</th><th>Agoda</th><th>Direct</th><th>Blocked</th><th>Free</th><th>Occupancy</th></tr></thead>
      <tbody>
      <?php foreach ($rooms as $rid => $rname):
        $totals = ['airbnb'=>0,'booking.com'=>0,'agoda'=>0,'direct'=>0,'blocked'=>0,'free'=>0];
        for ($d=1; $d<=$bmDays; $d++) {
          $ds = sprintf('%04d-%02d-%02d',$bmYear,$bmMonth,$d);
          $bk = $occ[$rid][$ds] ?? null;
          if (!$bk) { $totals['free']++; continue; }
          $src = $bk['source'];
          if (in_array($src,['booking','booking.com'])) $totals['booking.com']++;
          elseif (in_array($src,['direct','phone','whatsapp','walk_in'])) $totals['direct']++;
          elseif (isset($totals[$src])) $totals[$src]++;
          else $totals['direct']++;
        }
        $occPct = round(($bmDays-$totals['free'])/$bmDays*100);
      ?>
      <tr>
        <td style="font-weight:600;font-size:.83rem"><?= htmlspecialchars($rname) ?></td>
        <td class="muted"><?= $bmDays ?></td>
        <td><?= $totals['airbnb'] ?: '—' ?></td>
        <td><?= $totals['booking.com'] ?: '—' ?></td>
        <td><?= $totals['agoda'] ?: '—' ?></td>
        <td><?= $totals['direct'] ?: '—' ?></td>
        <td style="color:#e53e3e;font-weight:<?= $totals['blocked']?'700':'400' ?>"><?= $totals['blocked'] ?: '—' ?></td>
        <td style="color:var(--primary);font-weight:700"><?= $totals['free'] ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:.4rem">
            <div style="flex:1;height:8px;background:#e5e7eb;border-radius:4px;min-width:60px">
              <div style="height:8px;border-radius:4px;background:<?= $occPct>=80?'#e53e3e':($occPct>=50?'#f59e0b':'#16a34a') ?>;width:<?= $occPct ?>%"></div>
            </div>
            <span style="font-weight:700;font-size:.8rem;color:<?= $occPct>=80?'#e53e3e':($occPct>=50?'#92400e':'#16a34a') ?>"><?= $occPct ?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($bview === 'quarter'): ?>
<!-- ══ QUARTERLY VIEW ════════════════════════════════════════ -->
<?php
  $qLabel   = "Q{$bqNum} {$bqYear}";
  $qMonths  = [($bqNum-1)*3+1, ($bqNum-1)*3+2, ($bqNum-1)*3+3];
?>
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.85rem;flex-wrap:wrap">
  <a class="day-btn" href="<?= $baseUrl ?>&bv=quarter&bqy=<?= $bqPrevY ?>&bqn=<?= $bqPrevN ?>">← Q<?= $bqPrevN ?> <?= $bqPrevY ?></a>
  <span style="font-weight:700;font-size:.95rem"><?= $qLabel ?> — <?= date('M',mktime(0,0,0,$qMonths[0],1,$bqYear)) ?> to <?= date('M Y',mktime(0,0,0,$qMonths[2],1,$bqYear)) ?></span>
  <a class="day-btn" href="<?= $baseUrl ?>&bv=quarter&bqy=<?= $bqNextY ?>&bqn=<?= $bqNextN ?>">Q<?= $bqNextN ?> <?= $bqNextY ?> →</a>
  <a class="day-btn" href="<?= $baseUrl ?>&bv=quarter&bqy=<?= date('Y') ?>&bqn=<?= ceil(date('n')/3) ?>" style="margin-left:auto">This Quarter</a>
</div>

<!-- 3 monthly mini-gantt grids side by side -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.25rem">
<?php foreach ($qMonths as $qm):
  $qmFirst = mktime(0,0,0,$qm,1,$bqYear);
  $qmDays  = (int)date('t',$qmFirst);
?>
  <div class="panel" style="margin:0">
    <div class="panel-hd" style="background:var(--primary);color:#fff;padding:.5rem .75rem">
      <h3 style="color:#fff;font-size:.85rem;margin:0"><?= date('F Y',$qmFirst) ?></h3>
    </div>
    <div class="panel-bd" style="padding:.5rem;overflow-x:auto">
      <!-- Mini calendar per room -->
      <?php foreach ($rooms as $rid => $rname):
        $rshort = substr($rname,0,16);
      ?>
      <div style="margin-bottom:.4rem">
        <div style="font-size:.65rem;font-weight:700;color:var(--text-muted);margin-bottom:.2rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($rshort) ?></div>
        <div style="display:flex;gap:1px;flex-wrap:nowrap">
          <?php for ($d=1; $d<=$qmDays; $d++):
            $ds = sprintf('%04d-%02d-%02d',$bqYear,$qm,$d);
            $bk = $occ[$rid][$ds] ?? null;
            $dow = date('D',strtotime($ds));
            $isWknd = in_array($dow,['Sat','Sun']);
            $isToday2 = $ds === $today;
            $bg = $bk ? sourceColor($bk['source']) : ($isToday2?'#16a34a':($isWknd?'#e5e7eb':'#f3f4f6'));
            $title = $ds.($bk?' — '.htmlspecialchars($bk['guest_name']).' ('.$bk['source'].')':' — Free');
          ?>
          <div style="width:9px;height:14px;border-radius:1px;background:<?= $bg ?>;flex-shrink:0;cursor:default<?= $isToday2?';outline:2px solid var(--primary);outline-offset:-1px':'' ?>"
            title="<?= $title ?>" onclick="window.location.href='admin.php?section=calendar&view=day&date=<?= $ds ?>'"></div>
          <?php endfor; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <!-- Month totals -->
      <?php
        $mFree=0; $mBooked=0;
        for ($d=1; $d<=$qmDays; $d++) {
          $ds = sprintf('%04d-%02d-%02d',$bqYear,$qm,$d);
          foreach ($rooms as $rid => $_) { if (isset($occ[$rid][$ds])) $mBooked++; else $mFree++; }
        }
        $mOcc = round($mBooked/($qmDays*count($rooms))*100);
      ?>
      <div style="margin-top:.5rem;padding-top:.4rem;border-top:1px solid var(--border);display:flex;justify-content:space-between;font-size:.7rem">
        <span style="color:var(--text-muted)">Occ: <strong style="color:<?= $mOcc>=70?'#e53e3e':'#16a34a' ?>"><?= $mOcc ?>%</strong></span>
        <span style="color:var(--text-muted)">Free slots: <strong><?= $mFree ?></strong></span>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- Quarterly summary table -->
<div class="panel" style="margin-bottom:1.25rem">
  <div class="panel-hd"><h3>📊 <?= $qLabel ?> Summary — All Properties</h3></div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Property</th>
          <?php foreach ($qMonths as $qm): ?><th style="text-align:center"><?= date('M',$qm>0?mktime(0,0,0,$qm,1,$bqYear):time()) ?></th><?php endforeach; ?>
          <th style="text-align:center">Q Total Nights</th>
          <th style="text-align:center">Airbnb</th>
          <th style="text-align:center">Booking.com</th>
          <th style="text-align:center">Direct</th>
          <th style="text-align:center">Blocked</th>
          <th>Quarter Occ</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rooms as $rid => $rname):
        $qTotalDays = 0; $qBooked = 0;
        $qSrc = ['airbnb'=>0,'booking.com'=>0,'agoda'=>0,'direct'=>0,'blocked'=>0];
        $mOccs = [];
        foreach ($qMonths as $qm) {
          $qmDays2 = (int)date('t',mktime(0,0,0,$qm,1,$bqYear));
          $qTotalDays += $qmDays2;
          $mBk = 0;
          for ($d=1; $d<=$qmDays2; $d++) {
            $ds = sprintf('%04d-%02d-%02d',$bqYear,$qm,$d);
            $bk = $occ[$rid][$ds] ?? null;
            if ($bk) {
              $mBk++; $qBooked++;
              $src = $bk['source'];
              if (in_array($src,['booking','booking.com'])) $qSrc['booking.com']++;
              elseif (in_array($src,['direct','phone','whatsapp','walk_in'])) $qSrc['direct']++;
              elseif (isset($qSrc[$src])) $qSrc[$src]++;
              else $qSrc['direct']++;
            }
          }
          $mOccs[$qm] = $qmDays2>0 ? round($mBk/$qmDays2*100) : 0;
        }
        $qOccPct = $qTotalDays>0 ? round($qBooked/$qTotalDays*100) : 0;
      ?>
      <tr>
        <td style="font-weight:600;font-size:.83rem"><?= htmlspecialchars($rname) ?></td>
        <?php foreach ($qMonths as $qm): ?>
        <td style="text-align:center;font-size:.82rem">
          <div style="font-weight:700;color:<?= $mOccs[$qm]>=70?'#e53e3e':($mOccs[$qm]>=40?'#92400e':'#16a34a') ?>"><?= $mOccs[$qm] ?>%</div>
        </td>
        <?php endforeach; ?>
        <td style="text-align:center;font-weight:700"><?= $qBooked ?></td>
        <td style="text-align:center"><?= $qSrc['airbnb'] ?: '—' ?></td>
        <td style="text-align:center"><?= $qSrc['booking.com'] ?: '—' ?></td>
        <td style="text-align:center"><?= $qSrc['direct'] ?: '—' ?></td>
        <td style="text-align:center;color:#e53e3e;font-weight:<?= $qSrc['blocked']?'700':'400' ?>"><?= $qSrc['blocked'] ?: '—' ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:.4rem">
            <div style="flex:1;height:8px;background:#e5e7eb;border-radius:4px;min-width:50px">
              <div style="height:8px;border-radius:4px;background:<?= $qOccPct>=70?'#e53e3e':($qOccPct>=40?'#f59e0b':'#16a34a') ?>;width:<?= $qOccPct ?>%"></div>
            </div>
            <strong style="font-size:.8rem;color:<?= $qOccPct>=70?'#e53e3e':'#16a34a' ?>"><?= $qOccPct ?>%</strong>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; // end bview ?>

<!-- ── Manual blocks list ───────────────────────────────────── -->
<div class="panel">
  <div class="panel-hd"><h3>🚫 Manual Block Ranges</h3><span class="sub">Owner-blocked periods only (excludes OTA bookings)</span></div>
  <div class="tbl-wrap">
    <?php if (empty($blockedBookings)): ?>
      <p style="padding:1rem;color:var(--text-muted);font-size:.85rem">No manual blocks set. Use the form above to block dates.</p>
    <?php else: ?>
    <table class="tbl">
      <thead><tr><th>Property</th><th>From</th><th>Until</th><th>Nights</th><th>Reason</th><th>Synced To</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($blockedBookings as $b): ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($b['room_name']) ?></td>
          <td><?= date('d M Y',strtotime($b['check_in'])) ?></td>
          <td><?= date('d M Y',strtotime($b['check_out'])) ?></td>
          <td><?= nights($b['check_in'], $b['check_out']) ?></td>
          <td class="muted"><?= htmlspecialchars($b['notes'] ?: '—') ?></td>
          <td>
            <span style="font-size:.7rem;display:flex;gap:.25rem;flex-wrap:wrap">
              <?php foreach (['airbnb'=>'#FF5A5F','booking.com'=>'#003580','agoda'=>'#EB1A23'] as $pl => $pc): ?>
              <span style="background:<?= $pc ?>;color:#fff;padding:.1rem .3rem;border-radius:3px;font-weight:700">✓ <?= strtoupper(substr($pl,0,3)) ?></span>
              <?php endforeach; ?>
            </span>
          </td>
          <td style="white-space:nowrap">
            <form method="POST" style="display:inline" onsubmit="return confirm('Remove this block? Dates will reopen on all platforms within 15 minutes.')">
    <?= csrfField() ?>
              <input type="hidden" name="action" value="delete_booking">
              <input type="hidden" name="id" value="<?= $b['id'] ?>">
              <input type="hidden" name="return_section" value="blocked">
              <button type="submit" class="btn btn-warn btn-sm">🗑 Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- ══════════════════════════════════════════════
     HIGH-DEMAND DATES
════════════════════════════════════════════════ -->
<?php elseif ($section === 'demand'): ?>

<?php
$allDemand = getDemandEvents(date('Y-m-d'), date('Y-m-d', strtotime('+365 days')));
?>

<!-- Summary cards -->
<div class="stats-row" style="margin-bottom:1.25rem">
  <?php
  $dGold   = count(array_filter($allDemand, fn($e) => $e['demand_level'] === 'gold'));
  $dHigh   = count(array_filter($allDemand, fn($e) => $e['demand_level'] === 'high'));
  $dMedium = count(array_filter($allDemand, fn($e) => $e['demand_level'] === 'medium'));
  ?>
  <div class="stat-card"><div class="stat-icon">🥇</div><div class="stat-val"><?= $dGold ?></div><div class="stat-lbl">Gold Events (Next 365d)</div></div>
  <div class="stat-card"><div class="stat-icon">🔴</div><div class="stat-val"><?= $dHigh ?></div><div class="stat-lbl">High-Demand Events</div></div>
  <div class="stat-card"><div class="stat-icon">🔵</div><div class="stat-val"><?= $dMedium ?></div><div class="stat-lbl">Medium-Demand Events</div></div>
  <div class="stat-card"><div class="stat-icon">📅</div><div class="stat-val"><?= count($allDemand) ?></div><div class="stat-lbl">Total Events</div></div>
</div>

<!-- Demand events list -->
<div class="panel">
  <div class="panel-hd">
    <h3>🥇 Upcoming High-Demand Dates <span class="sub">Next 365 days</span></h3>
    <form method="POST" style="display:inline">
    <?= csrfField() ?>
      <input type="hidden" name="action" value="reseed_events">
      <button type="submit" class="btn btn-gold btn-sm">⟳ Refresh Events</button>
    </form>
  </div>
  <div class="tbl-wrap">
    <?php if (empty($allDemand)): ?>
      <p style="padding:1rem;color:var(--text-muted);font-size:.85rem">No high-demand events found for the next 365 days. Click "Refresh Events" to seed them.</p>
    <?php else: ?>
    <table class="tbl">
      <thead>
        <tr><th>Date</th><th>Day</th><th>Event</th><th>Type</th><th>Demand Level</th></tr>
      </thead>
      <tbody>
        <?php foreach ($allDemand as $e): ?>
        <tr>
          <td style="font-weight:600;white-space:nowrap"><?= date('d M Y', strtotime($e['event_date'])) ?></td>
          <td class="muted"><?= date('D', strtotime($e['event_date'])) ?></td>
          <td><?= htmlspecialchars($e['event_name']) ?></td>
          <td class="muted"><?= htmlspecialchars($e['event_type'] ?? '—') ?></td>
          <td>
            <span style="<?= demandBadgeStyle($e['demand_level']) ?>;padding:.2rem .55rem;border-radius:5px;font-size:.72rem;font-weight:700;display:inline-block">
              <?= demandIcon($e['demand_level']) ?> <?= strtoupper($e['demand_level']) ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- Also show Pricing tip -->
<div class="panel" style="border-color:var(--gold)">
  <div class="panel-hd" style="background:#fffbeb"><h3>💡 Pricing Tip</h3></div>
  <div class="panel-bd">
    <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:.75rem">
      High-demand dates are used to auto-generate pricing suggestions. Visit Pricing to review and approve rate increases for these dates.
    </p>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
      <a href="admin.php?section=pricing" class="btn btn-gold">Review Pricing Suggestions →</a>
      <form method="POST" style="display:inline">
    <?= csrfField() ?>
        <input type="hidden" name="action" value="generate_suggestions">
        <button type="submit" class="btn btn-grey">⚡ Generate New Suggestions</button>
      </form>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════
     BOOKINGS
════════════════════════════════════════════════ -->
<?php elseif ($section === 'bookings'): ?>

<div class="panel" id="addBookingPanel">
  <div class="panel-hd">
    <div>
      <h3>➕ Log Direct Booking</h3>
      <div class="sub">Phone · WhatsApp · Walk-in · Manual</div>
    </div>
    <button type="button" class="btn btn-grey btn-sm" onclick="togglePanel('addBookingPanel')">▲ Collapse</button>
  </div>
  <div class="panel-bd" id="addBookingBody">
    <form method="POST" id="addBookingForm">
    <?= csrfField() ?>
      <input type="hidden" name="action" value="add_booking">
      <input type="hidden" name="wa_conversation_id" id="waConvId" value="">

      <!-- Source tabs -->
      <div class="section-label">Booking Source</div>
      <div class="source-tabs">
        <?php foreach (['phone'=>'📞 Phone','whatsapp'=>'💬 WhatsApp','walk_in'=>'🚶 Walk-in','direct'=>'🌐 Website','airbnb'=>'Airbnb','booking.com'=>'Booking.com','agoda'=>'Agoda','makemytrip'=>'MakeMyTrip','blocked'=>'🔒 Block Only'] as $sv => $sl): ?>
        <button type="button" class="source-tab <?= $sv==='phone'?'active':'' ?>" data-src="<?= $sv ?>" onclick="setSource('<?= $sv ?>')"><?= $sl ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="source" id="sourceInput" value="phone">

      <!-- Guest details -->
      <div class="section-label">Guest Details</div>
      <div class="form-row-3" style="margin-bottom:.75rem">
        <div class="fld"><label>Guest Name</label><input type="text" name="guest_name" id="bkGuestName" placeholder="Leave blank to block dates"></div>
        <div class="fld"><label>Phone</label><input type="tel" name="guest_phone" id="bkPhone" oninput="syncWhatsApp()"></div>
        <div class="fld"><label>WhatsApp Number <small style="font-weight:400">(if different)</small></label><input type="tel" name="whatsapp_number" id="bkWhatsApp" placeholder="Same as phone"></div>
      </div>
      <div class="form-row-2" style="margin-bottom:.75rem">
        <div class="fld"><label>Email (optional)</label><input type="email" name="guest_email"></div>
        <div class="fld"><label>Booking Ref</label><input type="text" name="booking_ref" placeholder="OTA confirmation number"></div>
      </div>

      <!-- Stay details -->
      <div class="section-label">Stay Details</div>
      <div class="form-row-3" style="margin-bottom:.75rem">
        <div class="fld">
          <label>Property</label>
          <select name="room_id" required>
            <?php foreach ($rooms as $rid => $rname): ?>
            <option value="<?= htmlspecialchars($rid) ?>"><?= htmlspecialchars($rname) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fld">
          <label>Check-in</label>
          <input type="date" name="check_in" id="bkCheckIn" required onchange="calcNights()">
        </div>
        <div class="fld">
          <label>Check-out <span id="nightsDisplay" class="nights-display" style="display:none"></span></label>
          <input type="date" name="check_out" id="bkCheckOut" required onchange="calcNights()">
        </div>
      </div>

      <!-- Payment -->
      <div class="section-label">Payment</div>
      <div class="form-row-3" style="margin-bottom:.75rem">
        <div class="fld">
          <label>Total Amount (₹)</label>
          <input type="number" name="amount" id="bkAmount" min="0" step="1" placeholder="0" oninput="updateBalance()">
        </div>
        <div class="fld">
          <label>Amount Paid (₹)</label>
          <input type="number" name="amount_paid" id="bkPaid" min="0" step="1" placeholder="0" oninput="updateBalance()">
        </div>
        <div class="fld">
          <label>Balance Due</label>
          <input type="text" id="bkBalance" readonly style="background:#f7faf7;font-weight:700;color:var(--warn)">
        </div>
      </div>
      <div class="section-label" style="margin-top:0">Payment Method</div>
      <div class="pay-method-grid" style="margin-bottom:.75rem">
        <?php foreach (['cash'=>'💵 Cash','upi'=>'📱 UPI','bank_transfer'=>'🏦 Bank Transfer','online'=>'💳 Online'] as $pv => $pl): ?>
        <button type="button" class="pay-method-btn <?= $pv==='cash'?'active':'' ?>" data-pm="<?= $pv ?>" onclick="setPayMethod('<?= $pv ?>')"><?= $pl ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="payment_method" id="pmInput" value="cash">

      <!-- Notes -->
      <div class="fld" style="margin-bottom:1rem">
        <label>Notes</label>
        <textarea name="notes" rows="2" placeholder="e.g. Early check-in requested, allergies, special occasions…"></textarea>
      </div>

      <div style="display:flex;gap:.75rem;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">➕ Save Booking &amp; Notify</button>
        <button type="reset" class="btn btn-grey" onclick="resetBookingForm()">Clear</button>
      </div>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel-hd"><h3>📋 All Bookings</h3><span class="sub">Excludes blocked dates — <a href="admin.php?section=blocked" style="color:var(--primary)">manage blocks →</a></span></div>
  <div class="panel-bd" style="padding-bottom:.5rem">
    <div class="search-bar">
      <input type="text" id="searchQ" placeholder="Search guest, room, ref…" oninput="filterBookings()">
      <select id="filterRoom" onchange="filterBookings()">
        <option value="">All Rooms</option>
        <?php foreach ($rooms as $rid => $rname): ?>
        <option value="<?= htmlspecialchars($rname) ?>"><?= htmlspecialchars($rname) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="filterSource" onchange="filterBookings()">
        <option value="">All Sources</option>
        <?php foreach (['airbnb','booking.com','agoda','makemytrip','direct','razorpay','manual','blocked'] as $src): ?>
        <option value="<?= $src ?>"><?= sourceName($src) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="filterStatus" onchange="filterBookings()">
        <option value="">All Statuses</option>
        <option value="confirmed">Confirmed</option>
        <option value="cancelled">Cancelled</option>
      </select>
    </div>
  </div>
  <div class="tbl-wrap">
    <table class="tbl" id="bookingsTable">
      <thead>
        <tr><th>#</th><th>Property</th><th>Check-in</th><th>Check-out</th><th>Nts</th><th>Guest</th><th>Source</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach (array_filter($allBookings, fn($b) => $b['source'] !== 'blocked') as $b):
          $bTotal   = (float)($b['amount']      ?? 0);
          $bPaid    = (float)($b['amount_paid'] ?? 0);
          $bBalance = max(0, $bTotal - $bPaid);
          $pstatus  = $bBalance <= 0 && $bTotal > 0 ? 'paid' : ($bPaid > 0 ? 'partial' : 'unpaid');
          $pdfUrl   = 'booking-pdf.php?id=' . $b['id'];
          $waNum    = $b['whatsapp_number'] ?: $b['guest_phone'];
          $bJson    = bookingJson($b);
        ?>
        <tr data-room="<?= htmlspecialchars($b['room_name']) ?>" data-source="<?= htmlspecialchars($b['source']) ?>" data-status="<?= $b['status'] ?>" data-search="<?= htmlspecialchars(strtolower($b['guest_name'].' '.$b['room_name'].' '.($b['booking_ref']??''))) ?>">
          <td class="muted"><?= $b['id'] ?></td>
          <td style="font-weight:600;white-space:nowrap"><?= htmlspecialchars($b['room_name']) ?></td>
          <td><?= $b['check_in'] ?></td>
          <td><?= $b['check_out'] ?></td>
          <td><?= nights($b['check_in'],$b['check_out']) ?></td>
          <td>
            <div style="font-weight:600;cursor:pointer;color:var(--primary-dark)" onclick="showBookingModal(<?= $bJson ?>)" title="View booking details"><?= htmlspecialchars($b['guest_name']) ?></div>
            <?php if ($b['guest_phone']): ?><div class="muted" style="font-size:.76rem"><?= htmlspecialchars($b['guest_phone']) ?></div><?php endif; ?>
            <?php if ($b['guest_email']): ?><div class="muted" style="font-size:.73rem"><?= htmlspecialchars($b['guest_email']) ?></div><?php endif; ?>
          </td>
          <td><?= badge($b['source']) ?></td>
          <td><?= $bTotal > 0 ? fmt($bTotal) : '—' ?></td>
          <td>
            <?php if ($bPaid > 0): ?>
            <span><?= fmt($bPaid) ?></span>
            <div class="muted" style="font-size:.72rem"><?= ucfirst($b['payment_method'] ?? 'cash') ?></div>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
          </td>
          <td>
            <?php if ($bTotal > 0): ?>
            <span class="pay-pill pay-<?= $pstatus ?>"><?= $pstatus === 'paid' ? '✓ Paid' : ($pstatus === 'partial' ? '½ ' . fmt($bBalance) . ' due' : 'Unpaid') ?></span>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
          </td>
          <td><span class="status-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
          <td style="white-space:nowrap">
            <button type="button" class="btn btn-sm btn-primary" onclick="openEditBookingModal(<?= $bJson ?>)" title="Edit Booking / Change Dates">✏️ Edit</button>
            <a href="<?= $pdfUrl ?>" target="_blank" class="btn btn-sm btn-grey" title="View / Download PDF">📄 PDF</a>
            <?php if ($waNum): ?><a href="https://wa.me/<?= preg_replace('/\D/','',$waNum) ?>" target="_blank" class="btn btn-sm btn-grey" title="Open WhatsApp chat">💬</a><?php endif; ?>
            <?php if ($b['status']==='confirmed'): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Cancel booking #<?= $b['id'] ?> (<?= htmlspecialchars(addslashes($b['guest_name'])) ?>)?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="cancel_booking">
              <input type="hidden" name="id" value="<?= $b['id'] ?>">
              <input type="hidden" name="return_section" value="bookings">
              <button type="submit" class="btn btn-warn btn-sm" title="Cancel Booking">Cancel</button>
            </form>
            <?php endif; ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete booking #<?= $b['id'] ?> (<?= htmlspecialchars(addslashes($b['guest_name'])) ?>) permanently?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete_booking">
              <input type="hidden" name="id" value="<?= $b['id'] ?>">
              <input type="hidden" name="return_section" value="bookings">
              <button type="submit" class="btn btn-danger btn-sm" title="Delete Booking Permanently">🗑️ Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ══════════════════════════════════════════════
     PRICING & SUGGESTIONS
════════════════════════════════════════════════ -->
<?php elseif ($section === 'pricing'): ?>

<?php
// OTA commission rates
$otaComm = OTA_COMMISSIONS;
$platforms = ['airbnb' => 'Airbnb', 'booking.com' => 'Booking.com', 'agoda' => 'Agoda', 'direct' => 'Direct / Website'];

// Default discount rules if none saved
$defaultRules = [
  'los_weekly'     => ['label'=>'Weekly Stay (7+ nights)',    'value'=>10,'unit'=>'pct','min_nights'=>7, 'days_ahead'=>0,  'enabled'=>1],
  'los_monthly'    => ['label'=>'Monthly Stay (28+ nights)',  'value'=>20,'unit'=>'pct','min_nights'=>28,'days_ahead'=>0,  'enabled'=>1],
  'early_bird_30'  => ['label'=>'Early Bird (30+ days ahead)','value'=>5, 'unit'=>'pct','min_nights'=>1, 'days_ahead'=>30, 'enabled'=>1],
  'last_minute_3'  => ['label'=>'Last Minute (≤3 days)',      'value'=>10,'unit'=>'pct','min_nights'=>1, 'days_ahead'=>3,  'enabled'=>0],
  'weekend'        => ['label'=>'Weekend Premium',            'value'=>15,'unit'=>'pct','min_nights'=>1, 'days_ahead'=>0,  'enabled'=>1],
];
foreach ($defaultRules as $k => $d) {
  if (!isset($discountRules[$k])) $discountRules[$k] = array_merge(['rule_type'=>$k], $d);
}

// OTA deep-link update pages
$otaLinks = [
  'airbnb'     => 'https://www.airbnb.com/hosting/listings',
  'booking.com'=> 'https://account.booking.com/auth/login',
  'agoda'      => 'https://ycs.agoda.com/',
];
?>

<!-- ── Sync explanation banner ──────────────────────────────── -->
<div class="panel" style="border-color:#3b82f6;margin-bottom:1.25rem">
  <div class="panel-hd" style="background:#eff6ff">
    <h3 style="color:#1d4ed8">ℹ️ How Cross-Platform Pricing Works</h3>
  </div>
  <div class="panel-bd">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem">
      <div style="padding:.85rem;background:#f0fdf4;border-radius:8px;border:1px solid #bbf7d0">
        <div style="font-weight:700;font-size:.85rem;color:#166534;margin-bottom:.35rem">✅ What syncs automatically</div>
        <div style="font-size:.82rem;color:#166534">Availability (blocked dates) sync via iCal every 15 minutes to Airbnb, Booking.com, and Agoda.</div>
      </div>
      <div style="padding:.85rem;background:#fffbeb;border-radius:8px;border:1px solid #fde68a">
        <div style="font-weight:700;font-size:.85rem;color:#92400e;margin-bottom:.35rem">⚠️ What needs manual update</div>
        <div style="font-size:.82rem;color:#92400e">Prices. OTAs don't accept pricing via iCal — you must update rates on each platform. Set them here first, then use the quick-links below to update each OTA in one click.</div>
      </div>
      <div style="padding:.85rem;background:#f5f3ff;border-radius:8px;border:1px solid #ddd6fe">
        <div style="font-weight:700;font-size:.85rem;color:#5b21b6;margin-bottom:.35rem">🔗 Quick-update links</div>
        <div style="font-size:.82rem;color:#5b21b6">Each platform card below has a direct link to that OTA's pricing page so you can update in seconds.</div>
      </div>
    </div>
  </div>
</div>

<!-- ── Platform Rate Matrix ─────────────────────────────────── -->
<div class="panel" style="margin-bottom:1.25rem">
  <div class="panel-hd">
    <h3>💰 Rate Matrix — All Properties × All Platforms</h3>
    <span class="sub">Set base rate + per-platform rate. Net = rate after OTA commission.</span>
  </div>
  <div class="panel-bd" style="padding:0">
    <form method="POST">
    <?= csrfField() ?>
      <input type="hidden" name="action" value="update_base_rate">
      <div class="tbl-wrap">
        <table class="tbl" style="min-width:700px">
          <thead>
            <tr>
              <th style="min-width:160px">Property</th>
              <th style="text-align:center">Base Rate<br><span style="font-weight:400;font-size:.7rem;color:var(--text-muted)">₹/night</span></th>
              <?php foreach ($platforms as $pid => $pname): ?>
              <th style="text-align:center">
                <?= $pname ?><br>
                <span style="font-weight:400;font-size:.7rem;color:var(--text-muted)">
                  <?php if ($pid !== 'direct'): ?>-<?= $otaComm[$pid] ?? 15 ?>% comm<?php else: ?>no comm<?php endif; ?>
                </span>
              </th>
              <th style="text-align:center;font-size:.7rem;color:var(--text-muted)">Net<br>(<?= $pname ?>)</th>
              <?php endforeach; ?>
              <th style="text-align:center">Update On</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rooms as $rid => $rname):
            $base = $ratesMap[$rid] ?? 0;
          ?>
          <tr>
            <td style="font-weight:600;font-size:.83rem"><?= htmlspecialchars($rname) ?></td>
            <td style="text-align:center">
              <input type="number" name="rates[<?= $rid ?>]" value="<?= $base ?>" min="0"
                style="width:80px;padding:.3rem .4rem;border:1.5px solid var(--border);border-radius:6px;font-size:.82rem;text-align:center"
                oninput="calcNet(this,'<?= $rid ?>')">
            </td>
            <?php foreach ($platforms as $pid => $pname):
              $prate = $platformRates[$rid][$pid] ?? ($base ?: '');
              $comm  = $pid !== 'direct' ? ($otaComm[$pid] ?? 15) : 0;
              $netId = "net-{$rid}-{$pid}";
              $net   = $prate ? round($prate * (1 - $comm/100)) : 0;
            ?>
            <td style="text-align:center">
              <input type="number" name="platform_rates[<?= $rid ?>][<?= $pid ?>]"
                value="<?= htmlspecialchars($prate) ?>" min="0"
                id="pr-<?= $rid ?>-<?= $pid ?>"
                style="width:80px;padding:.3rem .4rem;border:1.5px solid var(--border);border-radius:6px;font-size:.82rem;text-align:center"
                oninput="document.getElementById('<?= $netId ?>').textContent='₹'+Math.round(this.value*(1-<?= $comm ?>/100)).toLocaleString('en-IN')">
            </td>
            <td style="text-align:center;font-size:.82rem;font-weight:600;color:var(--primary-dark)" id="<?= $netId ?>">
              <?= $net ? '₹'.number_format($net) : '—' ?>
            </td>
            <?php endforeach; ?>
            <td style="text-align:center">
              <?php foreach ($otaLinks as $pid2 => $link): ?>
              <a href="<?= $link ?>" target="_blank"
                style="display:inline-block;padding:.2rem .45rem;margin:.1rem;border-radius:4px;font-size:.65rem;font-weight:700;text-decoration:none;color:#fff;background:<?= sourceColor($pid2) ?>">
                <?= strtoupper(substr($pid2,0,3)) ?>↗
              </a>
              <?php endforeach; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="padding:.85rem 1rem;border-top:1px solid var(--border);display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">💾 Save All Rates</button>
        <span style="font-size:.78rem;color:var(--text-muted)">Saving updates base rates and all platform-specific rates at once.</span>
      </div>
    </form>
  </div>
</div>

<!-- ── Platform Quick-Update Cards ──────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;margin-bottom:1.25rem">
  <?php
  $otaCardInfo = [
    'airbnb'      => ['color'=>'#FF5A5F','icon'=>'🏠','steps'=>'Listings → Select property → Pricing → Calendar pricing'],
    'booking.com' => ['color'=>'#003580','icon'=>'🏨','steps'=>'Rates & Availability → Rate Plans → Edit rates per date range'],
    'agoda'       => ['color'=>'#EB1A23','icon'=>'🌏','steps'=>'YCS → Properties → Rate Plans → Manage rates'],
  ];
  foreach ($otaCardInfo as $pid => $info): ?>
  <div style="background:var(--card);border:1px solid var(--border);border-top:4px solid <?= $info['color'] ?>;border-radius:10px;padding:1rem">
    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.6rem">
      <span style="font-size:1.4rem"><?= $info['icon'] ?></span>
      <div>
        <div style="font-weight:700;font-size:.92rem"><?= $platforms[$pid] ?></div>
        <div style="font-size:.72rem;color:var(--text-muted)"><?= $otaComm[$pid] ?? 15 ?>% commission</div>
      </div>
    </div>
    <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.75rem;line-height:1.5">
      <strong>Steps:</strong> <?= $info['steps'] ?>
    </div>
    <!-- Net rates summary for this platform -->
    <div style="margin-bottom:.75rem">
      <?php foreach (array_slice($rooms, 0, 5) as $rid => $rname):
        $pr  = $platformRates[$rid][$pid] ?? ($ratesMap[$rid] ?? 0);
        $net = $pr ? round($pr * (1 - ($otaComm[$pid] ?? 15)/100)) : null;
      ?>
      <div style="display:flex;justify-content:space-between;font-size:.75rem;padding:.2rem 0;border-bottom:1px dashed var(--border)">
        <span style="color:var(--text-muted)"><?= htmlspecialchars(substr($rname,0,20)) ?></span>
        <span>
          <?php if ($pr): ?>
            <span style="font-weight:600">₹<?= number_format($pr) ?></span>
            <span style="color:var(--text-muted);font-size:.68rem"> → net ₹<?= number_format($net) ?></span>
          <?php else: ?><span style="color:#e53e3e">Not set</span><?php endif; ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
    <a href="<?= $otaLinks[$pid] ?>" target="_blank"
      style="display:block;text-align:center;padding:.45rem;background:<?= $info['color'] ?>;color:#fff;border-radius:7px;font-size:.78rem;font-weight:700;text-decoration:none">
      Update <?= $platforms[$pid] ?> Pricing ↗
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Discount Rules ────────────────────────────────────────── -->
<div class="panel" style="margin-bottom:1.25rem">
  <div class="panel-hd">
    <h3>🏷️ Discount Rules</h3>
    <span class="sub">Applied to all platforms — update each OTA manually with these rules</span>
  </div>
  <div class="panel-bd">
    <form method="POST">
    <?= csrfField() ?>
      <input type="hidden" name="action" value="save_discount_rules">
      <div class="tbl-wrap">
        <table class="tbl">
          <thead><tr><th>Enabled</th><th>Discount Type</th><th>Discount %</th><th>Min Nights / Days Ahead</th><th>Apply To</th></tr></thead>
          <tbody>
          <?php foreach ($discountRules as $rtype => $dr): ?>
          <tr>
            <td style="text-align:center">
              <input type="hidden" name="rule_type[]" value="<?= htmlspecialchars($rtype) ?>">
              <input type="hidden" name="rule_label[]" value="<?= htmlspecialchars($dr['label']) ?>">
              <input type="hidden" name="rule_unit[]" value="pct">
              <input type="checkbox" name="rule_enabled[]" value="1" <?= ($dr['enabled'] ?? 0) ? 'checked' : '' ?>
                style="width:16px;height:16px;cursor:pointer">
            </td>
            <td style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($dr['label']) ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:.4rem">
                <input type="number" name="rule_value[]" value="<?= $dr['value'] ?? 0 ?>" min="0" max="90" step="0.5"
                  style="width:70px;padding:.3rem .4rem;border:1.5px solid var(--border);border-radius:6px;font-size:.83rem;text-align:center">
                <span style="font-size:.82rem;color:var(--text-muted)">%</span>
              </div>
            </td>
            <td style="font-size:.82rem;color:var(--text-muted)">
              <?php if (($dr['min_nights'] ?? 1) > 1): ?>
                Min <?= $dr['min_nights'] ?> nights
              <?php elseif (($dr['days_ahead'] ?? 0) > 0): ?>
                Book ≥ <?= $dr['days_ahead'] ?> days ahead
              <?php elseif ($rtype === 'last_minute_3'): ?>
                Book ≤ 3 days before
              <?php else: ?>
                Any stay
              <?php endif; ?>
              <input type="hidden" name="rule_min[]" value="<?= $dr['min_nights'] ?? 1 ?>">
              <input type="hidden" name="rule_ahead[]" value="<?= $dr['days_ahead'] ?? 0 ?>">
            </td>
            <td style="font-size:.8rem">
              <?php foreach ($platforms as $pid => $pname): ?>
                <span style="display:inline-block;padding:.1rem .35rem;border-radius:4px;background:<?= sourceColor($pid) ?>;color:#fff;font-size:.65rem;font-weight:700;margin:.1rem"><?= strtoupper(substr($pid,0,3)) ?></span>
              <?php endforeach; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="padding:.85rem 1rem;border-top:1px solid var(--border);display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">💾 Save Discount Rules</button>
        <span style="font-size:.78rem;color:var(--text-muted)">
          These discounts are for reference — apply them manually on each OTA's discount/promotions section.
        </span>
      </div>
    </form>
  </div>
</div>

<!-- Generate suggestions -->
<div class="panel">
  <div class="panel-hd"><h3>⚡ Demand Intelligence</h3></div>
  <div class="panel-bd" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
    <div style="flex:1;min-width:200px">
      <p style="font-size:.85rem;color:var(--text-muted)">Re-seed Muhuratham, festival, holiday, and bridge-holiday dates, then generate pricing suggestions for all rooms.</p>
    </div>
    <form method="POST" style="display:flex;gap:.5rem;flex-wrap:wrap">
    <?= csrfField() ?>
      <input type="hidden" name="action" value="generate_suggestions">
      <button type="submit" class="btn btn-gold">⚡ Generate Suggestions</button>
    </form>
    <form method="POST">
    <?= csrfField() ?>
      <input type="hidden" name="action" value="reseed_events">
      <button type="submit" class="btn btn-grey btn-sm">🔄 Re-seed Events Only</button>
    </form>
  </div>
</div>

<!-- Pending suggestions -->
<?php if ($pendingCount > 0): ?>
<div class="panel" style="border-color:var(--gold)">
  <div class="panel-hd" style="background:#fffbeb">
    <div>
      <h3>💡 Pending Suggestions (<?= $pendingCount ?>)</h3>
      <div class="sub">Review and approve pricing changes before they take effect</div>
    </div>
    <form method="POST">
    <?= csrfField() ?>
      <input type="hidden" name="action" value="approve_all_suggestions">
      <button type="submit" class="btn btn-success" onclick="return confirm('Approve all <?= $pendingCount ?> suggestions?')">✓ Approve All</button>
    </form>
  </div>
  <div class="panel-bd">
    <?php foreach ($pendingSuggestions as $s): ?>
    <div class="suggestion-card <?= $s['demand_level']==='gold'?'gold-card':($s['demand_level']==='high'?'high-card':'') ?>">
      <span class="sc-badge" style="<?= demandBadgeStyle($s['demand_level']) ?>"><?= demandIcon($s['demand_level']) ?> <?= strtoupper($s['demand_level']) ?></span>
      <div class="sc-body">
        <div class="sc-reason"><?= htmlspecialchars($s['reason']) ?></div>
        <div class="sc-meta"><?= htmlspecialchars(ROOM_IDS[$s['room_id']] ?? $s['room_id']) ?> · <?= $s['date_from'] ?> → <?= $s['date_to'] ?></div>
        <div class="sc-price-row">
          <span class="sc-price-old"><?= fmt($s['current_price']) ?></span>
          <span style="color:var(--text-muted)">→</span>
          <span class="sc-price-new"><?= fmt($s['suggested_price']) ?></span>
          <span class="sc-pct">+<?= $s['suggestion_pct'] ?>%</span>
        </div>
        <div class="sc-actions" style="margin-top:.5rem">
          <form method="POST" style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap">
    <?= csrfField() ?>
            <input type="hidden" name="action" value="approve_suggestion">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <input type="hidden" name="suggested_price" value="<?= $s['suggested_price'] ?>">
            <input type="number" name="approved_price" value="<?= $s['suggested_price'] ?>" min="0" style="width:100px;padding:.3rem .5rem;border:1.5px solid var(--border);border-radius:6px;font-size:.82rem">
            <button type="submit" class="btn btn-success btn-sm">✓ Approve</button>
          </form>
          <form method="POST">
    <?= csrfField() ?>
            <input type="hidden" name="action" value="dismiss_suggestion">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button type="submit" class="btn btn-grey btn-sm">✕ Dismiss</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<div class="panel"><div class="panel-bd" style="text-align:center;padding:2rem;color:var(--text-muted)">
  <div style="font-size:2rem;margin-bottom:.5rem">✅</div>
  <p>No pending suggestions. Click "Generate Suggestions" to scan for upcoming high-demand events.</p>
</div></div>
<?php endif; ?>

<!-- Approved / Dismissed history -->
<?php
$approvedSuggestions = getPricingSuggestions('approved');
$dismissedSuggestions = getPricingSuggestions('dismissed');
if (!empty($approvedSuggestions)):
?>
<div class="panel">
  <div class="panel-hd"><h3>✅ Approved Pricing Changes</h3></div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Room</th><th>Date Range</th><th>Old Price</th><th>Approved Price</th><th>Reason</th><th>Approved At</th></tr></thead>
      <tbody>
        <?php foreach ($approvedSuggestions as $s): ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars(ROOM_IDS[$s['room_id']] ?? $s['room_id']) ?></td>
          <td class="muted"><?= $s['date_from'] ?> → <?= $s['date_to'] ?></td>
          <td><?= fmt($s['current_price']) ?></td>
          <td style="font-weight:700;color:var(--primary-dark)"><?= fmt($s['approved_price']) ?></td>
          <td style="font-size:.78rem"><?= htmlspecialchars(substr($s['reason'],0,60)) ?>…</td>
          <td class="muted"><?= $s['approved_at'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Upcoming demand events full list -->
<div class="panel">
  <div class="panel-hd"><h3>📅 Upcoming Demand Events (180 days)</h3></div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Day</th><th>Event</th><th>Type</th><th>Level</th></tr></thead>
      <tbody>
        <?php foreach (getDemandEvents(date('Y-m-d'), date('Y-m-d', strtotime('+180 days'))) as $e): ?>
        <tr>
          <td style="font-weight:700"><?= $e['event_date'] ?></td>
          <td class="muted"><?= date('D', strtotime($e['event_date'])) ?></td>
          <td><?= htmlspecialchars($e['event_name']) ?></td>
          <td class="muted"><?= ucfirst(str_replace('_',' ',$e['event_type'])) ?></td>
          <td><span style="<?= demandBadgeStyle($e['demand_level']) ?>;padding:.15rem .45rem;border-radius:5px;font-size:.72rem;font-weight:700"><?= demandIcon($e['demand_level']) ?> <?= strtoupper($e['demand_level']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ══════════════════════════════════════════════
     ANALYTICS
════════════════════════════════════════════════ -->
<?php elseif ($section === 'analytics'): ?>

<!-- Revenue stats -->
<?php
$totalGross = array_sum(array_column($revenueMonthly,'gross'));
$totalComm  = array_sum(array_column($revenueMonthly,'commission'));
$totalNet   = array_sum(array_column($revenueMonthly,'net'));
$totalBkgs  = array_sum(array_column($revenueMonthly,'count'));
?>
<div class="stats-row">
  <div class="stat-card">
    <div class="stat-icon">💰</div>
    <div class="stat-val"><?= fmt($totalGross) ?></div>
    <div class="stat-lbl">Total Gross Revenue (all time)</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">📡</div>
    <div class="stat-val"><?= fmt($totalComm) ?></div>
    <div class="stat-lbl">OTA Commissions Paid</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">✅</div>
    <div class="stat-val"><?= fmt($totalNet) ?></div>
    <div class="stat-lbl">Net Revenue (after commissions)</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">📋</div>
    <div class="stat-val"><?= $totalBkgs ?></div>
    <div class="stat-lbl">Total Paid Bookings</div>
  </div>
</div>

<div class="dash-grid">
  <div>
    <!-- Revenue chart -->
    <div class="panel">
      <div class="panel-hd">
        <h3>📊 Revenue Over Time</h3>
        <div class="analytics-tabs">
          <?php foreach (['monthly'=>'Monthly','quarterly'=>'Quarterly','yearly'=>'Yearly'] as $k=>$l): ?>
          <span class="atab <?= $k==='monthly'?'active':'' ?>" onclick="switchPeriod('<?= $k ?>')" id="tab-<?= $k ?>"><?= $l ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="panel-bd">
        <div class="chart-wrap"><canvas id="revenueChart"></canvas></div>
      </div>
    </div>

    <!-- Revenue table -->
    <div class="panel">
      <div class="panel-hd"><h3>📋 Monthly Revenue Breakdown</h3></div>
      <div class="tbl-wrap">
        <table class="tbl">
          <thead><tr><th>Period</th><th>Bookings</th><th>Gross</th><th>OTA Commission</th><th>Net Revenue</th></tr></thead>
          <tbody>
            <?php foreach (array_reverse($revenueMonthly, true) as $period => $row): ?>
            <tr>
              <td style="font-weight:600"><?= htmlspecialchars($period) ?></td>
              <td><?= $row['count'] ?></td>
              <td><?= fmt($row['gross']) ?></td>
              <td style="color:var(--danger)"><?= fmt($row['commission']) ?></td>
              <td style="font-weight:700;color:var(--primary-dark)"><?= fmt($row['net']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#f7faf7;font-weight:700">
              <td>Total</td>
              <td><?= $totalBkgs ?></td>
              <td><?= fmt($totalGross) ?></td>
              <td style="color:var(--danger)"><?= fmt($totalComm) ?></td>
              <td style="color:var(--primary-dark)"><?= fmt($totalNet) ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div>
    <!-- Revenue projection -->
    <div class="panel">
      <div class="panel-hd"><h3>🔮 Revenue Projection</h3><span class="sub">Remaining <?= $projection['remaining_days'] ?> days of year</span></div>
      <div class="panel-bd">
        <div class="proj-card">
          <div class="proj-row">
            <span class="proj-lbl">Historical Occupancy</span>
            <span class="proj-val"><?= $projection['hist_occupancy_pct'] ?>%</span>
          </div>
          <div class="proj-row">
            <span class="proj-lbl">Avg Nightly Rate</span>
            <span class="proj-val"><?= fmt($projection['avg_nightly_rate']) ?></span>
          </div>
          <div class="proj-row">
            <span class="proj-lbl">Confirmed Future Revenue</span>
            <span class="proj-val" style="color:#16a34a"><?= fmt($projection['confirmed_future_rev']) ?></span>
          </div>
          <div class="proj-row">
            <span class="proj-lbl">Projected (unconfirmed)</span>
            <span class="proj-val"><?= fmt($projection['projected_unconfirmed']) ?></span>
          </div>
          <div class="proj-row">
            <span class="proj-lbl">🥇 Demand Premium (<?= $projection['gold_dates'] ?> Gold + <?= $projection['high_dates'] ?> High dates)</span>
            <span class="proj-val" style="color:var(--gold)"><?= fmt($projection['demand_premium']) ?></span>
          </div>
          <div class="proj-row" style="margin-top:.5rem;border-top:2px solid var(--border)">
            <span class="proj-lbl" style="font-weight:700">Total Projection</span>
            <span class="proj-val proj-total"><?= fmt($projection['total_projected']) ?></span>
          </div>
        </div>

        <div style="margin-top:1rem">
          <div style="font-size:.75rem;color:var(--text-muted);line-height:1.6">
            Projection = Confirmed bookings + Estimated unconfirmed (historical occupancy × avg rate) + Premium for <?= $projection['gold_dates'] + $projection['high_dates'] ?> high-demand days.
          </div>
        </div>
      </div>
    </div>

    <!-- OTA commission rates info -->
    <div class="panel">
      <div class="panel-hd"><h3>📡 OTA Commission Rates</h3></div>
      <div class="panel-bd">
        <?php foreach (OTA_COMMISSIONS as $platform => $pct): ?>
        <div class="occ-row">
          <span class="occ-lbl"><?= sourceName($platform) ?></span>
          <div class="occ-bar-wrap"><div class="occ-bar" style="width:<?= $pct*4 ?>%;background:<?= sourceColor($platform) ?>"></div></div>
          <span class="occ-pct"><?= $pct ?>%</span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Platform revenue breakdown -->
    <div class="panel">
      <div class="panel-hd"><h3>📡 Revenue by Platform</h3></div>
      <div class="panel-bd">
        <?php
        $byPlatformRev = [];
        foreach (array_filter($allBookings, fn($b)=>$b['status']==='confirmed'&&$b['amount']>0) as $b) {
            $src = $b['source'] ?? 'direct';
            if (!isset($byPlatformRev[$src])) $byPlatformRev[$src] = ['gross'=>0,'net'=>0,'count'=>0];
            $gross = (float)$b['amount'];
            $byPlatformRev[$src]['gross'] += $gross;
            $byPlatformRev[$src]['net']   += $gross * (1 - commissionForSource($src));
            $byPlatformRev[$src]['count']++;
        }
        arsort($byPlatformRev);
        foreach ($byPlatformRev as $src => $rv): ?>
        <div style="margin-bottom:.75rem">
          <div style="display:flex;justify-content:space-between;margin-bottom:.25rem;font-size:.82rem">
            <?= badge($src) ?>
            <span><?= fmt($rv['gross']) ?> gross / <?= fmt($rv['net']) ?> net</span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════
     CHANNELS
════════════════════════════════════════════════ -->
<?php elseif ($section === 'channels'): ?>

<?php
// Build connection matrix: room → platform → calendar_id
$connMatrix = [];
foreach ($extCals as $cal) {
    $connMatrix[$cal['room_id']][$cal['platform']] = $cal['id'];
}
$otaPlatforms = ['airbnb' => 'Airbnb', 'booking.com' => 'Booking.com', 'agoda' => 'Agoda', 'makemytrip' => 'MakeMyTrip'];
$totalSlots   = count($rooms) * count($otaPlatforms);
$connected    = 0;
foreach ($connMatrix as $rConnections) { $connected += count($rConnections); }
$pct = $totalSlots > 0 ? round($connected / $totalSlots * 100) : 0;
?>

<!-- AUTO-SYNC STATUS + HEALTH BAR -->
<div class="panel" style="border-color:<?= $connected === 0 ? '#e2e8f0' : ($pct >= 75 ? '#38a169' : '#d97706') ?>;background:<?= $connected === 0 ? '#fff' : ($pct >= 75 ? '#f0fff4' : '#fffbeb') ?>">
  <div class="panel-bd" style="display:flex;align-items:center;gap:1rem;padding:.9rem 1.25rem;flex-wrap:wrap">
    <span style="font-size:1.6rem"><?= $connected === 0 ? '⚙️' : ($pct >= 75 ? '🔄' : '⚠️') ?></span>
    <div style="flex:1;min-width:200px">
      <strong style="font-size:.95rem"><?= $connected === 0 ? 'Auto-Sync Ready — connect a channel below to activate' : "Auto-Sync Active — {$connected}/{$totalSlots} room-channels connected ({$pct}%)" ?></strong>
      <?php if ($connected > 0): ?>
      <div style="margin-top:.4rem;height:6px;background:#e2e8f0;border-radius:3px;width:min(260px,100%)">
        <div style="height:6px;border-radius:3px;width:<?= $pct ?>%;background:<?= $pct >= 75 ? '#38a169' : '#d97706' ?>"></div>
      </div>
      <div style="font-size:.78rem;color:#4a5568;margin-top:.3rem">
        Last synced: <strong><?= $lastAutoSync ? date('d M, g:i A', strtotime($lastAutoSync)) : 'never' ?></strong>
        · Automatic sync runs from the server cron schedule
      </div>
      <?php endif; ?>
    </div>
    <button class="sync-btn" id="syncBtn2" onclick="runSync()" style="white-space:nowrap">⟳ Sync Now</button>
  </div>
</div>

<!-- CONNECTION STATUS MATRIX -->
<div class="panel">
  <div class="panel-hd">
    <h3>📡 Connection Status</h3>
    <span class="sub">Which rooms are synced with which platforms</span>
  </div>
  <div class="tbl-wrap">
    <table class="tbl" style="min-width:500px">
      <thead>
        <tr>
          <th>Property</th>
          <?php foreach ($otaPlatforms as $pid => $pname): ?>
          <th style="text-align:center;background:<?= sourceColor($pid) ?>20"><?= $pname ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rooms as $rid => $rname): ?>
        <tr>
          <td style="font-weight:600;font-size:.83rem"><?= htmlspecialchars($rname) ?></td>
          <?php foreach ($otaPlatforms as $pid => $pname): ?>
          <td style="text-align:center">
            <?php if (isset($connMatrix[$rid][$pid])): ?>
              <span title="Connected" style="color:#38a169;font-size:1.1rem">✅</span>
            <?php else: ?>
              <a href="#bulk-connect" onclick="document.getElementById('bulkPlatform').value='<?= $pid ?>';document.getElementById('bulkConnectPanel').style.display='block';document.getElementById('bulkConnectPanel').scrollIntoView({behavior:'smooth'})" style="color:#e2e8f0;font-size:1.1rem;text-decoration:none" title="Not connected — click to connect <?= $pname ?>">❌</a>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- BULK PLATFORM CONNECT -->
<div class="panel" id="bulkConnectPanel">
  <div class="panel-hd">
    <div>
      <h3 id="bulk-connect">🔗 Connect Platform — Paste All URLs at Once</h3>
      <div class="sub">Get iCal export URLs from the platform and paste them below for each room</div>
    </div>
  </div>
  <div class="panel-bd">

    <!-- Platform selector tabs -->
    <div style="display:flex;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap">
      <?php foreach ($otaPlatforms as $pid => $pname): ?>
      <button type="button"
        onclick="selectBulkPlatform('<?= $pid ?>')"
        id="bpTab_<?= str_replace('.', '_', $pid) ?>"
        style="padding:.45rem 1rem;border-radius:20px;border:2px solid <?= sourceColor($pid) ?>;background:<?= sourceColor($pid) ?>20;color:<?= sourceColor($pid) ?>;font-weight:700;font-size:.82rem;cursor:pointer">
        <?= $pname ?>
      </button>
      <?php endforeach; ?>
    </div>
    <input type="hidden" id="bulkPlatform" value="airbnb">

    <!-- Step-by-step guide per platform -->
    <div id="guide_airbnb" class="platform-guide">
      <div style="background:#fff5f5;border:1px solid #FC8181;border-radius:10px;padding:1rem;margin-bottom:1rem;font-size:.83rem;line-height:1.8">
        <strong style="color:#C53030;font-size:.88rem">📋 How to get Airbnb iCal export URLs:</strong><br>
        1. Go to <a href="https://www.airbnb.co.in/hosting/listings" target="_blank" style="color:#C53030">airbnb.co.in/hosting/listings</a><br>
        2. Click a listing → <strong>Manage listing</strong><br>
        3. Go to <strong>Availability</strong> tab → scroll to <strong>Sync calendars</strong><br>
        4. Click <strong>Export calendar</strong> → copy the <code>.ics</code> URL<br>
        5. Paste it in the field for that room below → repeat for each listing
      </div>
    </div>
    <div id="guide_booking_com" class="platform-guide" style="display:none">
      <div style="background:#e8f4fd;border:1px solid #63b3ed;border-radius:10px;padding:1rem;margin-bottom:1rem;font-size:.83rem;line-height:1.8">
        <strong style="color:#003580;font-size:.9rem">📋 Booking.com — exact steps (2025 Extranet UI)</strong>
        <div style="margin-top:.6rem;display:grid;grid-template-columns:1fr 1fr;gap:1rem">

          <div style="background:#fff;border:1px solid #bee3f8;border-radius:8px;padding:.75rem">
            <div style="font-weight:700;color:#003580;margin-bottom:.4rem">① Get Booking.com's iCal URL → paste below</div>
            <ol style="margin:0;padding-left:1.2rem;line-height:2">
              <li>Log in → <a href="https://admin.booking.com" target="_blank" style="color:#003580;font-weight:600">admin.booking.com</a></li>
              <li>Top menu → <strong>Calendar &amp; Pricing</strong></li>
              <li>Click <strong>Sync calendars</strong> (below the calendar grid)</li>
              <li>Click <strong>Add calendar connection</strong></li>
              <li>On the popup → click <strong>Skip this step</strong></li>
              <li>Give it a name (e.g. "Kanchi Direct") → click <strong>Export Calendar</strong></li>
              <li>Click <strong>Copy link</strong> → paste URL in the field below for that room</li>
            </ol>
            <div style="background:#dbeafe;border-radius:5px;padding:.35rem .5rem;margin-top:.4rem;font-size:.77rem">
              Booking.com provides a complete HTTPS export URL. Treat it as a secret and paste it only into the PMS.
            </div>
          </div>

          <div style="background:#fff;border:1px solid #bee3f8;border-radius:8px;padding:.75rem">
            <div style="font-weight:700;color:#003580;margin-bottom:.4rem">② Give Booking.com your iCal URL → so it blocks your direct bookings</div>
            <ol style="margin:0;padding-left:1.2rem;line-height:2">
              <li>Same page → <strong>Calendar &amp; Pricing → Sync calendars</strong></li>
              <li>Click <strong>Import calendar</strong></li>
              <li>Paste the export URL for this room from the <a href="admin.php?section=export" style="color:#003580">📤 iCal Export</a> page</li>
              <li>Click <strong>Connect</strong> or <strong>Save</strong></li>
            </ol>
            <div style="background:#dbeafe;border-radius:5px;padding:.35rem .5rem;margin-top:.4rem;font-size:.77rem">
              ✅ Booking.com will now auto-block dates from your direct bookings within 2–4 hrs
            </div>
            <div style="background:#fef3c7;border-radius:5px;padding:.35rem .5rem;margin-top:.3rem;font-size:.77rem">
              ⚠️ If you have multiple room types on Booking.com, repeat both steps for each room type separately
            </div>
          </div>
        </div>
      </div>
    </div>
    <div id="guide_agoda" class="platform-guide" style="display:none">
      <div style="background:#fff5eb;border:1px solid #f6ad55;border-radius:10px;padding:1rem;margin-bottom:1rem;font-size:.83rem;line-height:1.8">
        <strong style="color:#c05621;font-size:.88rem">📋 How to get Agoda iCal export URLs:</strong><br>
        1. Log in to <a href="https://ycs.agoda.com" target="_blank" style="color:#c05621">ycs.agoda.com</a> (YCS Portal)<br>
        2. Go to <strong>Room Management</strong> → <strong>Availability</strong><br>
        3. Look for <strong>Calendar Sync</strong> or <strong>iCal Export</strong><br>
        4. Click <strong>Export iCal</strong> → copy the URL<br>
        5. Also click <strong>Import iCal</strong> → paste our export URL for that room → Save<br>
        6. Paste Agoda's export URL in the field below<br>
        <div style="background:#fef3c7;border-radius:6px;padding:.4rem .6rem;margin-top:.4rem">💡 If you don't see Calendar Sync, contact Agoda YCS support and ask them to enable "iCal sync" for your property</div>
      </div>
    </div>
    <div id="guide_makemytrip" class="platform-guide" style="display:none">
      <div style="background:#fff5f5;border:1px solid #feb2b2;border-radius:10px;padding:1rem;margin-bottom:1rem;font-size:.83rem;line-height:1.8">
        <strong style="color:#9b2c2c;font-size:.88rem">📋 How to get MakeMyTrip iCal export URLs:</strong><br>
        1. Log in to <a href="https://www.mmt-biz.com" target="_blank" style="color:#9b2c2c">mmt-biz.com</a> (Partner Portal)<br>
        2. Go to <strong>Property</strong> → <strong>Inventory</strong> → <strong>Calendar</strong><br>
        3. Look for <strong>Calendar Sync / iCal</strong> option<br>
        4. Export → copy URL → paste below<br>
        5. Import → paste our URL → Save<br>
        <div style="background:#fed7d7;border-radius:6px;padding:.4rem .6rem;margin-top:.4rem">💡 MMT iCal sync may need to be enabled by your account manager — call MMT partner support if the option is missing</div>
      </div>
    </div>

    <!-- Bulk URL input table -->
    <form method="POST" action="admin.php?section=channels" id="bulkConnectForm">
    <?= csrfField() ?>
      <input type="hidden" name="action" value="bulk_add_calendars">
      <input type="hidden" name="platform" id="bulkPlatformInput" value="airbnb">
      <div class="tbl-wrap">
        <table class="tbl" style="min-width:500px">
          <thead>
            <tr>
              <th style="width:35%">Property</th>
              <th>iCal Export URL from Platform <span style="font-weight:400;color:var(--text-muted)">(paste the platform's calendar URL here)</span></th>
              <th style="width:80px;text-align:center">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rooms as $rid => $rname):
              $currentPid = 'airbnb'; // default for initial render
            ?>
            <tr>
              <td style="font-weight:600;font-size:.83rem"><?= htmlspecialchars($rname) ?></td>
              <td>
                <input type="url" name="urls[<?= htmlspecialchars($rid) ?>]"
                  placeholder="https://…"
                  style="width:100%;padding:.4rem .6rem;border:1px solid #e2e8f0;border-radius:6px;font-size:.81rem"
                  class="bulk-url-input">
              </td>
              <td style="text-align:center" id="bulkStatus_<?= htmlspecialchars($rid) ?>">
                <?php foreach ($otaPlatforms as $pid => $pname): ?>
                <span class="conn-status-<?= str_replace('.','_',$pid) ?>" style="display:<?= $pid === 'airbnb' ? 'inline' : 'none' ?>">
                  <?php if (isset($connMatrix[$rid][$pid])): ?>
                    <span style="color:#38a169;font-weight:700;font-size:.78rem">✅ Live</span>
                  <?php else: ?>
                    <span style="color:#a0aec0;font-size:.78rem">—</span>
                  <?php endif; ?>
                </span>
                <?php endforeach; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:1rem;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">💾 Save &amp; Connect All</button>
        <span style="font-size:.8rem;color:var(--text-muted)">Empty fields are skipped — only rooms with a URL will be connected</span>
      </div>
    </form>
  </div>
</div>

<!-- STEP-BY-STEP PLATFORM GUIDES -->
<div class="panel">
  <div class="panel-hd"><h3>📡 Platform Setup</h3><span class="sub">Do this once per room — then it syncs automatically</span></div>
  <div class="panel-bd">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;margin-bottom:1.25rem">

      <!-- AIRBNB -->
      <div style="border:2px solid #FF5A5F;border-radius:12px;overflow:hidden">
        <div style="background:#FF5A5F;color:#fff;padding:.65rem 1rem;font-weight:700;font-size:.95rem;display:flex;align-items:center;gap:.5rem">
          🏠 Airbnb <span style="font-weight:400;font-size:.78rem;opacity:.9;margin-left:auto">refreshes every ~1 hour</span>
        </div>
        <div style="padding:1rem;font-size:.83rem;line-height:1.65">
          <div style="font-weight:600;color:#c53030;margin-bottom:.4rem">① Push YOUR bookings → Airbnb</div>
          1. Airbnb → Hosting → Listings → [your listing]<br>
          2. Availability → <strong>Sync calendars → Import calendar</strong><br>
          3. Paste the URL from <a href="admin.php?section=export" style="color:#c53030">📤 iCal Export</a> for this room → Save<br>
          <div style="background:#fff5f5;border-radius:6px;padding:.4rem .6rem;margin:.5rem 0;font-size:.78rem">✅ Airbnb will now block dates from your direct bookings (within 1 hr)</div>
          <div style="font-weight:600;color:#c53030;margin-bottom:.4rem;margin-top:.5rem">② Pull Airbnb bookings → Your system</div>
          1. Same page → <strong>Sync calendars → Export calendar</strong> → copy URL<br>
          2. Paste in the "Add iCal Feed" form below → Save → click <strong>⟳ Sync Now</strong>
        </div>
      </div>

      <!-- BOOKING.COM -->
      <div style="border:2px solid #003580;border-radius:12px;overflow:hidden">
        <div style="background:#003580;color:#fff;padding:.65rem 1rem;font-weight:700;font-size:.95rem;display:flex;align-items:center;gap:.5rem">
          🌐 Booking.com <span style="font-weight:400;font-size:.78rem;opacity:.9;margin-left:auto">refreshes every ~2–4 hrs</span>
        </div>
        <div style="padding:1rem;font-size:.83rem;line-height:1.75">
          <div style="font-weight:600;color:#003580;margin-bottom:.35rem">① Get Booking.com's iCal URL (import into your system)</div>
          1. <a href="https://admin.booking.com" target="_blank" style="color:#003580">admin.booking.com</a> → top menu → <strong>Calendar &amp; Pricing</strong><br>
          2. Scroll down → click <strong>Sync calendars</strong><br>
          3. Click <strong>Add calendar connection → Skip this step</strong><br>
          4. Name it → click <strong>Export Calendar → Copy link</strong><br>
          5. Paste into the Bulk Connect form above under Booking.com<br>
          <div style="font-weight:600;color:#003580;margin-bottom:.35rem;margin-top:.65rem">② Give Booking.com your URL (so it blocks your direct bookings)</div>
          1. Same page → <strong>Sync calendars → Import calendar</strong><br>
          2. Paste the URL from <a href="admin.php?section=export" style="color:#003580">📤 iCal Export</a> for this room → <strong>Connect</strong>
          <div style="background:#ebf8ff;border-radius:6px;padding:.35rem .6rem;margin-top:.4rem;font-size:.78rem">✅ Booking.com blocks your direct-booked dates within 2–4 hrs after import</div>
        </div>
      </div>

      <!-- AGODA / MMT -->
      <div style="border:2px solid #e2e8f0;border-radius:12px;overflow:hidden">
        <div style="background:#4a5568;color:#fff;padding:.65rem 1rem;font-weight:700;font-size:.95rem">🌏 Agoda / MakeMyTrip</div>
        <div style="padding:1rem;font-size:.83rem;line-height:1.65">
          <strong>Agoda (YCS):</strong><br>
          YCS Portal → Availability → Calendar Sync → Import iCal → paste URL<br>
          Export: YCS → Calendar → Export → copy URL → add below<br><br>
          <strong>MakeMyTrip:</strong><br>
          Partner Portal → Property → Inventory → Calendar Sync → Import URL<br>
          Export: same section → Export → copy URL → add below
        </div>
      </div>
    </div>

    <!-- CRON SETUP BOX -->
    <div style="background:#f7fafc;border:1px solid #e2e8f0;border-radius:10px;padding:1rem;font-size:.82rem">
      <strong>⚡ Required: set up the server cron for automatic synchronization</strong><br>
      <span style="color:#4a5568">The admin's Sync Now button is manual. The cron must run every 15–30 minutes to keep OTA availability current while you are offline.</span><br>
      <div style="margin-top:.6rem">
        <strong>Option A — Hostinger hPanel:</strong> Advanced → Cron Jobs → Add new → schedule <code>*/15 * * * *</code> → command:<br>
        <code style="background:#edf2f7;padding:2px 6px;border-radius:4px;display:inline-block;margin:.3rem 0">/usr/local/bin/php <?= rtrim($_SERVER['DOCUMENT_ROOT'] ?? '/home/u997938990/domains/kanchifarmstay.com/public_html', '/') ?>/channel-manager/cron.php</code><br>
        <strong>Option B — Free external cron (cron-job.org):</strong> Create a free account → Add job → URL:<br>
        <code style="background:#edf2f7;padding:2px 6px;border-radius:4px;display:inline-block;margin:.3rem 0"><?= SITE_URL ?>/channel-manager/cron.php?token=<?= CRON_SECRET ?></code>
        · Schedule: every 15 minutes
      </div>
    </div>
  </div>
</div>

<!-- ADD ICAL FEED FORM -->
<div class="panel">
  <div class="panel-hd">
    <h3>➕ Add iCal Import Feed</h3>
    <span class="sub">Paste the platform's export URL here to import their bookings into your system</span>
  </div>
  <div class="panel-bd">
    <form method="POST">
    <?= csrfField() ?>
      <input type="hidden" name="action" value="add_calendar">
      <div class="form-grid">
        <div class="fld">
          <label>Room</label>
          <select name="room_id">
            <?php foreach ($rooms as $rid => $rname): ?>
            <option value="<?= htmlspecialchars($rid) ?>"><?= htmlspecialchars($rname) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fld">
          <label>Platform</label>
          <select name="platform">
            <option value="airbnb">Airbnb</option>
            <option value="booking.com">Booking.com</option>
            <option value="agoda">Agoda</option>
            <option value="makemytrip">MakeMyTrip</option>
          </select>
        </div>
        <div class="fld" style="grid-column:span 2">
          <label>iCal URL (from the platform's "Export calendar" / "Export iCal" option)</label>
          <input type="url" name="ical_url" required placeholder="https://calendar-provider.example/export.ics">
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:.85rem">➕ Connect Channel</button>
    </form>
  </div>
</div>

<!-- CONNECTED CHANNELS TABLE -->
<div class="panel">
  <div class="panel-hd">
    <h3>🔗 Connected Channels (<?= count($extCals) ?>)</h3>
  </div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Room</th><th>Platform</th><th>iCal URL</th><th>Last Synced</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($extCals)): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:2.5rem">
          No channels connected yet. Follow the setup guides above to connect Airbnb and Booking.com.
        </td></tr>
        <?php else: ?>
        <?php foreach ($extCals as $cal): ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars(ROOM_IDS[$cal['room_id']] ?? $cal['room_id']) ?></td>
          <td><?= badge($cal['platform']) ?></td>
          <td><a href="<?= htmlspecialchars($cal['ical_url']) ?>" target="_blank" style="color:var(--info);font-size:.78rem;word-break:break-all"><?= htmlspecialchars(substr($cal['ical_url'],0,55)) ?>…</a></td>
          <td>
            <?php if ($cal['last_synced']): ?>
              <?php $ago = round((time()-strtotime($cal['last_synced']))/60); ?>
              <span class="status-synced">✓ <?= $ago < 60 ? $ago.'m ago' : date('d M, g:i A', strtotime($cal['last_synced'])) ?></span>
            <?php else: ?>
              <span class="status-never">Never synced</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="POST" onsubmit="return confirm('Remove this channel?')">
    <?= csrfField() ?>
              <input type="hidden" name="action" value="delete_calendar">
              <input type="hidden" name="id" value="<?= $cal['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$lastSyncResults = $_SESSION['last_sync_results'] ?? null;
$lastSyncTime    = $_SESSION['last_sync_time'] ?? null;
if ($lastSyncResults):
?>
<div class="panel" style="border-color:var(--info)">
  <div class="panel-hd" style="background:#e3f2fd">
    <h3>⟳ Sync Results</h3>
    <span class="sub"><?= htmlspecialchars($lastSyncTime) ?></span>
  </div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Platform</th><th>Room</th><th>Status</th><th>Active Blocks</th><th>Error</th></tr></thead>
      <tbody>
        <?php foreach ($lastSyncResults as $r): ?>
        <tr>
          <td><?= badge($r['platform']) ?></td>
          <td style="font-weight:600"><?= htmlspecialchars(ROOM_IDS[$r['room_id']] ?? $r['room_id']) ?></td>
          <td><?php if ($r['success']): ?><span class="status-confirmed">✓ OK</span><?php else: ?><span class="status-cancelled">✗ Failed</span><?php endif; ?></td>
          <td><?= (int)$r['blocks'] ?></td>
          <td class="muted"><?= htmlspecialchars($r['error'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════
     iCAL EXPORT
════════════════════════════════════════════════ -->
<?php elseif ($section === 'export'): ?>

<div class="panel">
  <div class="panel-hd"><h3>📤 Your iCal Export URLs</h3><span class="sub">Paste these into Airbnb, Booking.com, Agoda, MakeMyTrip to block your dates automatically</span></div>
  <div class="panel-bd">
    <?php foreach (SUPPORTED_ICAL_PLATFORMS as $destination): ?>
      <h4 style="margin:1rem 0 .5rem">For <?= htmlspecialchars(ucfirst($destination)) ?></h4>
      <div class="ical-list">
        <?php foreach ($rooms as $rid => $rname): $urlId = $destination . '-' . $rid; ?>
        <div class="ical-row">
          <span class="ical-room-lbl"><?= htmlspecialchars($rname) ?></span>
          <span class="ical-url-box" id="url-<?= htmlspecialchars($urlId) ?>"><?= htmlspecialchars($icalUrls[$destination][$rid]) ?></span>
          <button class="btn btn-copy" onclick="copyUrl(<?= htmlspecialchars(json_encode($urlId), ENT_QUOTES) ?>)">Copy</button>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="howto">
  <h4>📋 How to add to each platform:</h4>
  <strong>Airbnb:</strong> Hosting → Your Listings → Select listing → Availability → Sync calendars → Import calendar<br>
  <strong>Booking.com:</strong> Extranet → Calendar → Sync calendars → Add a source → Paste URL<br>
  <strong>Agoda:</strong> YCS (Yield Control System) → Availability → Calendar Sync → Import iCal<br>
  <strong>MakeMyTrip:</strong> Partner Portal → Property → Inventory → Calendar Sync → Import URL<br><br>
  <strong>Tip:</strong> Also paste each platform's export URL into the Channels section so we can import their bookings.
</div>

<?php elseif ($section === 'wa_inbox'):
  $activeConvId = (int)($_GET['conv'] ?? 0);
  if (!$activeConvId && !empty($waConvs)) $activeConvId = (int)$waConvs[0]['id'];
  $activeConv = null;
  $activeMessages = [];
  if ($activeConvId) {
      foreach ($waConvs as $c) { if ((int)$c['id'] === $activeConvId) { $activeConv = $c; break; } }
      $activeMessages = getWAMessages($activeConvId);
      markConversationRead($activeConvId);
  }

  // WA templates
  $waTpls = [
    ['🙏 Greeting',         "Hi! Welcome to Kanchi Farm Stay 🌿\nThank you for reaching out! How can we help you today?"],
    ['📅 Request Dates',    "Could you please share your preferred check-in and check-out dates, and the number of guests?"],
    ['✅ Availability',     "Great news! The room is available for your selected dates. Would you like to confirm the booking?"],
    ['💰 Payment Details',  "To confirm your booking, please transfer the advance:\n\nBank: " . BANK_NAME . "\nA/C No: " . (BANK_ACCOUNT_NO ?: 'xxxxxxx') . "\nIFSC: " . (BANK_IFSC ?: 'xxxxxxx') . (UPI_ID ? "\nUPI: " . UPI_ID : '') . "\n\nKindly share the payment screenshot once done."],
    ['🗺️ Arrival Info',    "We look forward to your arrival! 🏡\nCheck-in time: 3:00 PM\nAddress: Madikeri, Coorg, Karnataka\nGoogle Maps: https://maps.google.com/?q=Coorg+Karnataka\n\nFeel free to call us if you need any assistance."],
    ['👋 Checkout Reminder',"Good morning! 🌅 Just a reminder that checkout is at 11:00 AM today.\nWe hope you had a wonderful stay! Please let us know if you need anything before you leave."],
  ];
?>

<!-- ══════════════════════════════════════════════
     WHATSAPP INBOX
════════════════════════════════════════════════ -->

<!-- Add Manual Conversation (collapsible) -->
<div class="panel" style="margin-bottom:.75rem">
  <div class="panel-hd" style="cursor:pointer" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'':'none'">
    <h3>➕ Add / Start Conversation</h3>
    <span class="sub">Manually create a conversation from a phone number</span>
  </div>
  <div class="panel-bd" style="display:none">
    <form method="POST">
    <?= csrfField() ?>
      <input type="hidden" name="action" value="wa_add_manual">
      <div class="form-row-3">
        <div class="fld"><label>Phone (with country code)</label><input type="tel" name="phone" placeholder="919876543210" required></div>
        <div class="fld"><label>Guest Name</label><input type="text" name="guest_name" placeholder="Unknown Guest"></div>
        <div class="fld"><label>First Message (optional)</label><input type="text" name="first_message"></div>
      </div>
      <div style="margin-top:.75rem">
        <button type="submit" class="btn btn-primary btn-sm">Start Conversation</button>
      </div>
    </form>
  </div>
</div>

<div class="wa-layout">
  <!-- Conversation list -->
  <div class="wa-sidebar">
    <div class="wa-sidebar-hd">
      <h3>💬 Conversations <?php if ($waUnread > 0): ?><span class="nav-badge"><?= $waUnread ?></span><?php endif; ?></h3>
      <input class="wa-search" type="text" placeholder="Search conversations…" oninput="filterConvs(this.value)" id="convSearch">
      <div class="wa-filters">
        <?php foreach (['all'=>'All','new_inquiry'=>'Inquiries','awaiting_reply'=>'Pending','confirmed'=>'Confirmed','urgent'=>'Urgent','closed'=>'Closed'] as $fs => $fl): ?>
        <button class="wa-filter <?= $fs==='all'?'active':'' ?>" data-f="<?= $fs ?>" onclick="filterConvsStatus('<?= $fs ?>',this)"><?= $fl ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="wa-conv-list" id="convList">
      <?php if (empty($waConvs)): ?>
      <div style="padding:2rem 1rem;text-align:center;color:var(--text-muted);font-size:.85rem">
        No conversations yet.<br>Messages from guests will appear here when your webhook is connected.
      </div>
      <?php endif; ?>
      <?php foreach ($waConvs as $c):
        $isActive = (int)$c['id'] === $activeConvId;
        $initial  = mb_strtoupper(mb_substr($c['guest_name'],0,1));
        $timeAgo  = $c['last_message_time'] ? date('d M', strtotime($c['last_message_time'])) : '';
        $statusColors = ['new_inquiry'=>'#f59e0b','awaiting_reply'=>'#3b82f6','confirmed'=>'#16a34a','urgent'=>'#ef4444','closed'=>'#9ca3af'];
        $statusColor  = $statusColors[$c['status']] ?? '#9ca3af';
      ?>
      <div class="wa-conv-item <?= $isActive?'active':'' ?>" data-status="<?= $c['status'] ?>" data-name="<?= htmlspecialchars(strtolower($c['guest_name'])) ?>"
           onclick="window.location='admin.php?section=wa_inbox&conv=<?= $c['id'] ?>'">
        <div class="wa-conv-avatar"><?= htmlspecialchars($initial) ?></div>
        <div class="wa-conv-body">
          <div class="wa-conv-top">
            <div class="wa-conv-name"><?= htmlspecialchars($c['guest_name']) ?></div>
            <div class="wa-conv-time"><?= $timeAgo ?></div>
          </div>
          <div class="wa-conv-preview"><?= htmlspecialchars(mb_substr($c['last_message'] ?? '', 0, 50)) ?></div>
          <div class="wa-conv-footer">
            <span class="wa-status-badge ws-<?= $c['status'] ?>"><?= str_replace('_', ' ', ucfirst($c['status'])) ?></span>
            <?php if ($c['unread_count'] > 0): ?><span class="wa-unread-badge"><?= $c['unread_count'] ?></span><?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Message thread -->
  <div class="wa-thread <?= $activeConv ? 'wa-active' : '' ?>">
    <?php if (!$activeConv): ?>
    <div class="wa-empty">
      <span style="font-size:2.5rem">💬</span>
      <span>Select a conversation to view messages</span>
    </div>
    <?php else: ?>

    <!-- Thread header -->
    <div class="wa-thread-hd">
      <div class="wa-thread-info">
        <div class="wa-conv-avatar" style="width:34px;height:34px;font-size:.9rem"><?= mb_strtoupper(mb_substr($activeConv['guest_name'],0,1)) ?></div>
        <div>
          <div class="wa-thread-name"><?= htmlspecialchars($activeConv['guest_name']) ?></div>
          <div class="wa-thread-phone">+<?= htmlspecialchars($activeConv['phone']) ?> · <span class="wa-status-badge ws-<?= $activeConv['status'] ?>"><?= str_replace('_', ' ', ucfirst($activeConv['status'])) ?></span></div>
        </div>
      </div>
      <div class="wa-thread-actions">
        <!-- Status changer -->
        <form method="POST" style="display:inline">
    <?= csrfField() ?>
          <input type="hidden" name="action" value="wa_update_status">
          <input type="hidden" name="conversation_id" value="<?= $activeConv['id'] ?>">
          <select name="status" onchange="this.form.submit()" style="border:1px solid var(--border);border-radius:6px;padding:.3rem .5rem;font-size:.78rem">
            <?php foreach (['new_inquiry'=>'New Inquiry','awaiting_reply'=>'Awaiting Reply','confirmed'=>'Confirmed','urgent'=>'Urgent','closed'=>'Closed'] as $sv => $sl): ?>
            <option value="<?= $sv ?>" <?= $activeConv['status']===$sv?'selected':'' ?>><?= $sl ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <!-- Convert to booking -->
        <?php
          // Find last inquiry message with extracted data
          $lastInquiry = null;
          foreach (array_reverse($activeMessages) as $m) {
            if ($m['is_inquiry']) { $lastInquiry = $m; break; }
          }
        ?>
        <button class="btn btn-success btn-sm" onclick="convertToBooking(<?= $activeConv['id'] ?>,'<?= htmlspecialchars(addslashes($activeConv['guest_name'])) ?>','<?= htmlspecialchars($activeConv['phone']) ?>','<?= $lastInquiry['extracted_check_in'] ?? '' ?>','<?= $lastInquiry['extracted_check_out'] ?? '' ?>')">
          📋 Convert to Booking
        </button>
        <a href="https://wa.me/<?= preg_replace('/\D/','',$activeConv['phone']) ?>" target="_blank" class="btn btn-grey btn-sm">↗ Open WA</a>
      </div>
    </div>

    <!-- Messages -->
    <div class="wa-messages" id="waMessages">
      <?php if (empty($activeMessages)): ?>
      <div class="wa-empty" style="height:auto;padding:2rem;color:rgba(0,0,0,.45)">No messages yet</div>
      <?php endif; ?>
      <?php foreach ($activeMessages as $m):
        $time = date('d M, H:i', strtotime($m['timestamp']));
      ?>
      <div class="wa-msg <?= htmlspecialchars($m['sender']) ?>">
        <?php if ($m['is_inquiry'] && $m['sender']==='guest'): ?>
        <div class="wa-inquiry-banner">
          <span>⚡</span>
          <div class="wa-inquiry-info">
            <strong>Booking Inquiry</strong>
            <?php if ($m['extracted_check_in']): ?> · <?= date('d M', strtotime($m['extracted_check_in'])) ?> → <?= $m['extracted_check_out'] ? date('d M', strtotime($m['extracted_check_out'])) : '?' ?><?php endif; ?>
            <?php if ($m['extracted_guests']): ?> · <?= $m['extracted_guests'] ?> guests<?php endif; ?>
            <?php if ($m['extracted_room']): ?> · <?= htmlspecialchars($m['extracted_room']) ?><?php endif; ?>
          </div>
          <button class="wa-inquiry-book" onclick="convertToBooking(<?= $activeConv['id'] ?>,'<?= htmlspecialchars(addslashes($activeConv['guest_name'])) ?>','<?= htmlspecialchars($activeConv['phone']) ?>','<?= $m['extracted_check_in'] ?>','<?= $m['extracted_check_out'] ?>')">Book Now</button>
        </div>
        <?php endif; ?>
        <div class="wa-bubble"><?= nl2br(htmlspecialchars($m['body'])) ?></div>
        <div class="wa-msg-time"><?= $time ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Composer -->
    <div class="wa-composer">
      <div class="wa-tpl-strip">
        <?php foreach ($waTpls as [$tName, $tBody]): ?>
        <button class="wa-tpl-btn" onclick="insertTemplate(<?= htmlspecialchars(json_encode($tBody)) ?>)"><?= htmlspecialchars($tName) ?></button>
        <?php endforeach; ?>
      </div>
      <form method="POST" id="replyForm" onsubmit="return sendReply(event)">
    <?= csrfField() ?>
        <input type="hidden" name="action" value="wa_reply">
        <input type="hidden" name="conversation_id" value="<?= $activeConv['id'] ?>">
        <div class="wa-composer-row">
          <textarea name="body" id="replyBody" placeholder="Type a message… (Enter to send, Shift+Enter for new line)" rows="1"></textarea>
          <button type="submit" class="wa-send-btn" title="Send">➤</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /layout -->
<?php endif; ?>

<?php if (!empty($_SESSION['admin_logged_in'])): ?>
<!-- ── Floating "Log Direct Booking" button ───────────────── -->
<button class="fab" id="fabBtn" onclick="openQuickBook()" title="Log Direct Booking (Ctrl+Shift+B)">
  📞 <span>Log Direct Booking</span>
  <span class="fab-shortcut">Ctrl+Shift+B</span>
</button>
<div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="true"></div>
<?php endif; ?>

<script>
// ── Bulk platform connect ────────────────────────────────────
function selectBulkPlatform(pid) {
  const selector = document.getElementById('bulkPlatform');
  const input = document.getElementById('bulkPlatformInput');
  if (!selector || !input) return;
  selector.value = pid;
  input.value = pid;
  // Show correct guide
  document.querySelectorAll('.platform-guide').forEach(el => el.style.display = 'none');
  const guideId = 'guide_' + pid.replace('.', '_');
  const guide = document.getElementById(guideId);
  if (guide) guide.style.display = 'block';
  // Show correct status column
  document.querySelectorAll('[class^="conn-status-"]').forEach(el => el.style.display = 'none');
  document.querySelectorAll('.conn-status-' + pid.replace('.', '_')).forEach(el => el.style.display = 'inline');
  // Clear URL inputs
  document.querySelectorAll('.bulk-url-input').forEach(el => el.value = '');
  // Highlight active tab
  document.querySelectorAll('[id^="bpTab_"]').forEach(el => el.style.opacity = '.45');
  const tab = document.getElementById('bpTab_' + pid.replace('.', '_'));
  if (tab) tab.style.opacity = '1';
}
function showToast(message, type = 'success', title = '', duration = 5000) {
  const container = document.getElementById('toastContainer');
  if (!container) return null;
  const styles = {
    success: {icon:'✓', title:'Success'}, warning: {icon:'!', title:'Attention'},
    error: {icon:'×', title:'Something went wrong'}, info: {icon:'i', title:'Update'}
  };
  const kind = styles[type] ? type : 'info';
  const toast = document.createElement('div');
  toast.className = 'toast toast-' + kind;
  toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');

  const icon = document.createElement('span');
  icon.className = 'toast-icon';
  icon.textContent = styles[kind].icon;
  const body = document.createElement('div');
  const heading = document.createElement('div');
  heading.className = 'toast-title';
  heading.textContent = title || styles[kind].title;
  const copy = document.createElement('div');
  copy.className = 'toast-message';
  copy.textContent = message;
  body.append(heading, copy);
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'toast-close';
  close.setAttribute('aria-label', 'Dismiss notification');
  close.textContent = '×';

  let timer;
  const dismiss = () => {
    clearTimeout(timer);
    toast.classList.add('is-leaving');
    setTimeout(() => toast.remove(), 190);
  };
  close.addEventListener('click', dismiss);
  toast.append(icon, body, close);
  container.appendChild(toast);
  timer = setTimeout(dismiss, Math.max(1800, duration));
  return toast;
}

function queueToastForReload(details) {
  try { sessionStorage.setItem('kfsSyncToast', JSON.stringify(details)); } catch (_) {}
}

// init
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('bulkPlatform')) selectBulkPlatform('airbnb');
  try {
    const queued = sessionStorage.getItem('kfsSyncToast');
    if (queued) {
      sessionStorage.removeItem('kfsSyncToast');
      const details = JSON.parse(queued);
      showToast(details.message, details.type, details.title, details.duration || 6000);
    }
  } catch (_) {}
});

// ── Booking detail modal ─────────────────────────────────────
function showBookingModal(b) {
  if (!b) return;
  const fmt = n => n > 0 ? '₹' + Number(n).toLocaleString('en-IN') : '—';
  const balance = Math.max(0, (b.amount||0) - (b.amount_paid||0));
  const payStatus = balance <= 0 && b.amount > 0
    ? '<span style="color:#2e7d32;font-weight:700">✓ Fully Paid</span>'
    : balance > 0
      ? '<span style="color:#e65100;font-weight:700">' + fmt(balance) + ' due</span>'
      : '<span style="color:#90a4ae">—</span>';
  const srcColors = {airbnb:'#FF5A5F','booking.com':'#003580',agoda:'#EB1A23',makemytrip:'#E8262D',direct:'#2e7d32',razorpay:'#2e7d32',manual:'#6d4c41',blocked:'#e53e3e'};
  const srcNames  = {airbnb:'Airbnb','booking.com':'Booking.com',agoda:'Agoda',makemytrip:'MakeMyTrip',direct:'Direct (Website)',razorpay:'Direct (Razorpay)',manual:'Manual / Phone',blocked:'Blocked'};
  const srcColor  = srcColors[b.source] || '#546e7a';
  const srcName   = srcNames[b.source]  || b.source;
  const ci = new Date(b.check_in);
  const co = new Date(b.check_out);
  const nts = Math.round((co - ci) / 86400000);
  const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const fmtDate = d => days[d.getUTCDay()] + ', ' + d.getUTCDate() + ' ' + months[d.getUTCMonth()] + ' ' + d.getUTCFullYear();
  const csrfToken = <?= json_encode(csrfToken()) ?>;
  const currentSection = <?= json_encode($section ?? 'bookings') ?>;

  window._activeBookingData = b;

  document.getElementById('bkMTitle').textContent = b.guest_name || 'Booking #' + b.id;
  document.getElementById('bkMSub').innerHTML =
    '<span style="background:' + srcColor + ';color:#fff;padding:.15rem .45rem;border-radius:4px;font-size:.72rem;font-weight:700">' + srcName + '</span>' +
    '&nbsp; ' + (b.room_name || '') + ' &nbsp;<span style="color:var(--text-muted)">#' + b.id + '</span>';

  document.getElementById('bkMBody').innerHTML = `
    <div class="bk-detail-grid">
      <div>
        <div class="bk-lbl">Check-in</div>
        <div class="bk-val">${fmtDate(ci)}</div>
      </div>
      <div>
        <div class="bk-lbl">Check-out</div>
        <div class="bk-val">${fmtDate(co)}</div>
      </div>
      <div>
        <div class="bk-lbl">Duration</div>
        <div class="bk-val">${nts} night${nts!==1?'s':''}</div>
      </div>
      <div>
        <div class="bk-lbl">Status</div>
        <div class="bk-val"><span class="status-${b.status}">${b.status.charAt(0).toUpperCase()+b.status.slice(1)}</span></div>
      </div>
      ${b.guest_phone ? `<div><div class="bk-lbl">Phone</div><div class="bk-val"><a href="tel:${b.guest_phone}" style="color:var(--primary)">${b.guest_phone}</a></div></div>` : ''}
      ${b.guest_email ? `<div><div class="bk-lbl">Email</div><div class="bk-val"><a href="mailto:${b.guest_email}" style="color:var(--primary)">${b.guest_email}</a></div></div>` : ''}
      <div>
        <div class="bk-lbl">Total Amount</div>
        <div class="bk-val">${fmt(b.amount)}</div>
      </div>
      <div>
        <div class="bk-lbl">Amount Paid</div>
        <div class="bk-val">${fmt(b.amount_paid)}${b.payment_method?' <span style="color:var(--text-muted);font-size:.75rem;font-weight:400">('+b.payment_method+')</span>':''}</div>
      </div>
      <div class="bk-detail-full">
        <div class="bk-lbl">Balance</div>
        <div class="bk-val">${payStatus}</div>
      </div>
      ${b.booking_ref ? `<div class="bk-detail-full"><div class="bk-lbl">Booking Reference</div><div class="bk-val">${b.booking_ref}</div></div>` : ''}
      ${b.notes ? `<div class="bk-detail-full"><div class="bk-lbl">Notes</div><div class="bk-val" style="font-weight:400;white-space:pre-wrap">${b.notes}</div></div>` : ''}
    </div>
    <div style="display:flex;gap:.6rem;margin-top:1.25rem;flex-wrap:wrap;align-items:center;justify-content:space-between;border-top:1px solid #eef2ee;padding-top:1rem">
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="button" class="btn btn-primary btn-sm" onclick="openEditBookingModal(window._activeBookingData)">✏️ Edit Booking &amp; Dates</button>
        ${b.guest_phone ? `<a href="https://wa.me/${b.guest_phone.replace(/\D/g,'')}" target="_blank" class="btn btn-grey btn-sm">💬 WhatsApp</a>` : ''}
        <a href="booking-pdf.php?id=${b.id}" target="_blank" class="btn btn-grey btn-sm">📄 PDF Receipt</a>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        ${b.status === 'confirmed' ? `
        <form method="POST" action="admin.php?section=${currentSection}" style="display:inline" onsubmit="return confirm('Cancel booking #${b.id}?')">
          <input type="hidden" name="csrf_token" value="${csrfToken}">
          <input type="hidden" name="action" value="cancel_booking">
          <input type="hidden" name="id" value="${b.id}">
          <input type="hidden" name="return_section" value="${currentSection}">
          <button type="submit" class="btn btn-warn btn-sm">❌ Cancel</button>
        </form>` : ''}
        <form method="POST" action="admin.php?section=${currentSection}" style="display:inline" onsubmit="return confirm('Delete booking #${b.id} permanently?')">
          <input type="hidden" name="csrf_token" value="${csrfToken}">
          <input type="hidden" name="action" value="delete_booking">
          <input type="hidden" name="id" value="${b.id}">
          <input type="hidden" name="return_section" value="${currentSection}">
          <button type="submit" class="btn btn-danger btn-sm">🗑️ Delete</button>
        </form>
      </div>
    </div>`;

  const detailModal = document.getElementById('bkDetailModal');
  if (detailModal) detailModal.style.display = 'flex';
}

function openEditBookingModal(b) {
  if (!b) return;
  document.getElementById('editBkId').value = b.id || '';
  document.getElementById('editModalTitle').textContent = '✏️ Edit Booking #' + (b.id || '');
  if (document.getElementById('editBkRoom')) document.getElementById('editBkRoom').value = b.room_id || '';
  if (document.getElementById('editBkCheckIn')) document.getElementById('editBkCheckIn').value = b.check_in || '';
  if (document.getElementById('editBkCheckOut')) document.getElementById('editBkCheckOut').value = b.check_out || '';
  if (document.getElementById('editBkStatus')) document.getElementById('editBkStatus').value = b.status || 'confirmed';
  if (document.getElementById('editBkSource')) document.getElementById('editBkSource').value = b.source || 'direct';
  if (document.getElementById('editBkGuestName')) document.getElementById('editBkGuestName').value = b.guest_name || '';
  if (document.getElementById('editBkPhone')) document.getElementById('editBkPhone').value = b.guest_phone || '';
  if (document.getElementById('editBkWa')) document.getElementById('editBkWa').value = b.whatsapp_number || b.guest_phone || '';
  if (document.getElementById('editBkEmail')) document.getElementById('editBkEmail').value = b.guest_email || '';
  if (document.getElementById('editBkRef')) document.getElementById('editBkRef').value = b.booking_ref || '';
  if (document.getElementById('editBkAmount')) document.getElementById('editBkAmount').value = b.amount !== undefined ? b.amount : 0;
  if (document.getElementById('editBkPaid')) document.getElementById('editBkPaid').value = b.amount_paid !== undefined ? b.amount_paid : 0;
  if (document.getElementById('editBkPayMethod')) document.getElementById('editBkPayMethod').value = b.payment_method || 'cash';
  if (document.getElementById('editBkNotes')) document.getElementById('editBkNotes').value = b.notes || '';

  calcEditNights();
  updateEditBalance();

  const detailModal = document.getElementById('bkDetailModal');
  if (detailModal) detailModal.style.display = 'none';

  const editModal = document.getElementById('editBookingModal');
  if (editModal) editModal.style.display = 'flex';
}

function closeEditBookingModal() {
  const editModal = document.getElementById('editBookingModal');
  if (editModal) editModal.style.display = 'none';
}

function calcEditNights() {
  const ci = document.getElementById('editBkCheckIn')?.value;
  const co = document.getElementById('editBkCheckOut')?.value;
  const el = document.getElementById('editNightsDisplay');
  if (!ci || !co || !el) return;
  const diff = Math.round((new Date(co) - new Date(ci)) / 86400000);
  if (diff > 0) { el.textContent = '(' + diff + ' night' + (diff !== 1 ? 's' : '') + ')'; }
  else { el.textContent = ''; }
}

function updateEditBalance() {
  const total = parseFloat(document.getElementById('editBkAmount')?.value) || 0;
  const paid  = parseFloat(document.getElementById('editBkPaid')?.value)   || 0;
  const bal   = Math.max(0, total - paid);
  const el    = document.getElementById('editBkBalance');
  if (el) {
    el.value = bal > 0 ? '₹' + bal.toLocaleString('en-IN') + ' due' : (total > 0 ? '✓ Fully Paid' : '—');
    el.style.color = bal > 0 ? 'var(--warn)' : (total > 0 ? '#16a34a' : 'var(--text-muted)');
  }
}

// ── Navigation ──────────────────────────────────────────────
function goTo(section) {
  if (section === 'calendar') {
    window.location.href = 'admin.php?section=calendar&view=day&date=<?= date('Y-m-d') ?>';
  } else {
    window.location.href = 'admin.php?section=' + section;
  }
}

// ── Sync ────────────────────────────────────────────────────
let syncInProgress = false;
async function runSync() {
  if (syncInProgress) return;
  syncInProgress = true;
  document.querySelectorAll('#syncBtn,#syncBtn2').forEach(b => { b.disabled=true; b.textContent='Syncing…'; });
  showToast('Checking all connected OTA calendars…', 'info', 'Sync started', 2500);
  try {
    const response = await fetch('sync.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({csrf_token: <?= json_encode(csrfToken()) ?>})
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'The server could not complete the sync.');

    const results = Array.isArray(data.results) ? data.results : [];
    const errors = results.filter(result => !result.success);
    const succeeded = results.length - errors.length;
    const activeBlocks = Number(data.total_blocks || 0);
    const blockLabel = activeBlocks === 1 ? 'active OTA block' : 'active OTA blocks';
    const details = errors.length
      ? {
          type:'warning', title:'Sync completed with warnings', duration:8000,
          message:`${succeeded} of ${results.length} calendars synced. ${errors.length} failed; see the results below.`
        }
      : {
          type:'success', title:'OTA sync complete', duration:6000,
          message:`${results.length} calendars synced successfully · ${activeBlocks} ${blockLabel}.`
        };
    queueToastForReload(details);
    window.location.href = 'admin.php?section=channels';
  } catch (error) {
    syncInProgress = false;
    document.querySelectorAll('#syncBtn,#syncBtn2').forEach(b => { b.disabled=false; b.textContent='⟳ Sync Now'; });
    showToast(error.message || 'Check your connection and try again.', 'error', 'OTA sync failed', 8000);
  }
}

// ── Booking search / filter ──────────────────────────────────
function filterBookings() {
  const q      = (document.getElementById('searchQ')?.value || '').toLowerCase();
  const room   = (document.getElementById('filterRoom')?.value || '').toLowerCase();
  const source = (document.getElementById('filterSource')?.value || '').toLowerCase();
  const status = (document.getElementById('filterStatus')?.value || '').toLowerCase();
  document.querySelectorAll('#bookingsTable tbody tr').forEach(tr => {
    const s = tr.dataset;
    const match =
      (!q      || s.search?.includes(q)) &&
      (!room   || s.room?.toLowerCase().includes(room)) &&
      (!source || s.source?.toLowerCase() === source) &&
      (!status || s.status === status);
    tr.style.display = match ? '' : 'none';
  });
}

// ── Copy iCal URL ────────────────────────────────────────────
function copyUrl(rid) {
  const box = document.getElementById('url-' + rid);
  if (!box) return;
  navigator.clipboard.writeText(box.textContent).then(() => {
    const orig = box.textContent;
    box.textContent = '✓ Copied!';
    setTimeout(() => box.textContent = orig, 1500);
  });
}

// ── Analytics chart ──────────────────────────────────────────
<?php if ($section === 'analytics' && !empty($revenueMonthly)): ?>
const revenueData = {
  monthly: {
    labels: <?= json_encode(array_keys($revenueMonthly)) ?>,
    gross:  <?= json_encode(array_values(array_column($revenueMonthly,'gross'))) ?>,
    net:    <?= json_encode(array_values(array_column($revenueMonthly,'net'))) ?>,
    comm:   <?= json_encode(array_values(array_column($revenueMonthly,'commission'))) ?>,
  },
};

<?php
$quarterly = getRevenueByPeriod('quarterly');
$yearly    = getRevenueByPeriod('yearly');
?>
revenueData.quarterly = {
  labels: <?= json_encode(array_keys($quarterly)) ?>,
  gross:  <?= json_encode(array_values(array_column($quarterly,'gross'))) ?>,
  net:    <?= json_encode(array_values(array_column($quarterly,'net'))) ?>,
  comm:   <?= json_encode(array_values(array_column($quarterly,'commission'))) ?>,
};
revenueData.yearly = {
  labels: <?= json_encode(array_keys($yearly)) ?>,
  gross:  <?= json_encode(array_values(array_column($yearly,'gross'))) ?>,
  net:    <?= json_encode(array_values(array_column($yearly,'net'))) ?>,
  comm:   <?= json_encode(array_values(array_column($yearly,'commission'))) ?>,
};

let currentChart = null;
function buildChart(period) {
  const d = revenueData[period];
  if (!d) return;
  const ctx = document.getElementById('revenueChart');
  if (!ctx) return;
  if (currentChart) currentChart.destroy();
  currentChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: d.labels,
      datasets: [
        { label: 'Gross Revenue', data: d.gross, backgroundColor: 'rgba(74,124,89,0.7)', borderRadius: 4 },
        { label: 'Net Revenue',   data: d.net,   backgroundColor: 'rgba(46,92,58,0.85)', borderRadius: 4 },
        { label: 'OTA Commission',data: d.comm,  backgroundColor: 'rgba(198,40,40,0.5)', borderRadius: 4 },
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'top' } },
      scales: {
        y: { ticks: { callback: v => '₹' + v.toLocaleString('en-IN') } }
      }
    }
  });
}

function switchPeriod(period) {
  document.querySelectorAll('.atab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-' + period)?.classList.add('active');
  buildChart(period);
}

document.addEventListener('DOMContentLoaded', () => buildChart('monthly'));
<?php endif; ?>

// ── Booking form helpers ──────────────────────────────────────
function setSource(src) {
  document.getElementById('sourceInput').value = src;
  document.querySelectorAll('.source-tab').forEach(b => b.classList.toggle('active', b.dataset.src === src));
}
function setPayMethod(pm) {
  document.getElementById('pmInput').value = pm;
  document.querySelectorAll('.pay-method-btn').forEach(b => b.classList.toggle('active', b.dataset.pm === pm));
}
function syncWhatsApp() {
  const ph = document.getElementById('bkPhone')?.value;
  const wa = document.getElementById('bkWhatsApp');
  if (wa && !wa.value) wa.placeholder = ph || 'Same as phone';
}
function calcNights() {
  const ci = document.getElementById('bkCheckIn')?.value;
  const co = document.getElementById('bkCheckOut')?.value;
  const el = document.getElementById('nightsDisplay');
  if (!ci || !co || !el) return;
  const diff = Math.round((new Date(co) - new Date(ci)) / 86400000);
  if (diff > 0) { el.textContent = diff + ' night' + (diff !== 1 ? 's' : ''); el.style.display = ''; }
  else { el.textContent = ''; el.style.display = 'none'; }
}
function updateBalance() {
  const total = parseFloat(document.getElementById('bkAmount')?.value) || 0;
  const paid  = parseFloat(document.getElementById('bkPaid')?.value)   || 0;
  const bal   = Math.max(0, total - paid);
  const el    = document.getElementById('bkBalance');
  if (el) { el.value = bal > 0 ? '₹' + bal.toLocaleString('en-IN') : (total > 0 ? '✓ Fully Paid' : ''); el.style.color = bal > 0 ? 'var(--warn)' : '#16a34a'; }
}
function togglePanel(id) {
  const body = document.getElementById(id)?.querySelector('.panel-bd');
  if (body) body.style.display = body.style.display === 'none' ? '' : 'none';
}
function resetBookingForm() {
  setSource('phone'); setPayMethod('cash');
  document.getElementById('nightsDisplay').style.display = 'none';
  document.getElementById('bkBalance').value = '';
  document.getElementById('waConvId').value = '';
}

// ── FAB / Quick-Book ─────────────────────────────────────────
function openQuickBook(convId, name, phone, checkIn, checkOut) {
  // Navigate to bookings section and scroll to form
  if (window.location.search.includes('section=bookings')) {
    const panel = document.getElementById('addBookingBody');
    if (panel) panel.style.display = '';
    if (convId) {
      document.getElementById('waConvId').value = convId;
      if (name)    document.getElementById('bkGuestName').value = name;
      if (phone)   { document.getElementById('bkPhone').value = phone; document.getElementById('bkWhatsApp').value = phone; }
      if (checkIn) document.getElementById('bkCheckIn').value  = checkIn;
      if (checkOut){ document.getElementById('bkCheckOut').value = checkOut; calcNights(); }
    }
    document.getElementById('addBookingPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } else {
    const params = new URLSearchParams({ section: 'bookings' });
    if (convId)   params.set('conv', convId);
    if (name)     params.set('n',    name);
    if (phone)    params.set('p',    phone);
    if (checkIn)  params.set('ci',   checkIn);
    if (checkOut) params.set('co',   checkOut);
    window.location.href = 'admin.php?' + params.toString();
  }
}

// Auto-prefill from URL params (when arriving from WA inbox)
(function() {
  const p = new URLSearchParams(location.search);
  if (p.get('section') === 'bookings') {
    const conv = p.get('conv'), name = p.get('n'), phone = p.get('p'), ci = p.get('ci'), co = p.get('co');
    if (conv || name || phone || ci || co) {
      document.addEventListener('DOMContentLoaded', () => openQuickBook(conv, name, phone, ci, co));
    }
  }
})();

// Keyboard shortcut Ctrl/Cmd + Shift + B
document.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key.toLowerCase() === 'b') {
    e.preventDefault();
    openQuickBook();
  }
});

// ── WhatsApp Inbox JS ─────────────────────────────────────────
function filterConvs(q) {
  q = q.toLowerCase();
  document.querySelectorAll('.wa-conv-item').forEach(el => {
    el.style.display = !q || el.dataset.name?.includes(q) ? '' : 'none';
  });
}
function filterConvsStatus(status, btn) {
  document.querySelectorAll('.wa-filter').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.wa-conv-item').forEach(el => {
    el.style.display = (status === 'all' || el.dataset.status === status) ? '' : 'none';
  });
}
function insertTemplate(body) {
  const ta = document.getElementById('replyBody');
  if (ta) { ta.value = body; ta.focus(); ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; }
}
function sendReply(e) {
  const body = document.getElementById('replyBody')?.value.trim();
  if (!body) { e.preventDefault(); return false; }
  return true; // let form POST normally
}

// Auto-resize reply textarea
document.addEventListener('DOMContentLoaded', () => {
  const ta = document.getElementById('replyBody');
  if (ta) {
    ta.addEventListener('input', () => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; });
    ta.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); document.getElementById('replyForm')?.submit(); }
    });
  }
  // Scroll messages to bottom
  const msgs = document.getElementById('waMessages');
  if (msgs) msgs.scrollTop = msgs.scrollHeight;
});

function convertToBooking(convId, name, phone, checkIn, checkOut) {
  openQuickBook(convId, name, phone, checkIn, checkOut);
}

// ── Mobile sidebar toggle ────────────────────────────────────
(function() {
  const btn     = document.getElementById('mobMenuBtn');
  const sidebar = document.querySelector('.sidebar');
  const overlay = document.getElementById('mobOverlay');
  if (!btn || !sidebar) return;

  function openSidebar() {
    sidebar.classList.add('mob-open');
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    sidebar.classList.remove('mob-open');
    overlay.classList.remove('open');
    document.body.style.overflow = '';
  }
  btn.addEventListener('click', openSidebar);
  overlay.addEventListener('click', closeSidebar);
  // Close when a sidebar nav link is tapped
  sidebar.querySelectorAll('a').forEach(a => a.addEventListener('click', closeSidebar));
})();

// ── PWA service worker ───────────────────────────────────────
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('admin-sw.js', { scope: '/channel-manager/' }).catch(() => {});
}
</script>

<?php if (!empty($_SESSION['admin_logged_in'])): ?>
<!-- Booking Detail Modal -->
<div id="bkDetailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9000;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:#fff;border-radius:12px;width:min(540px,95vw);max-height:90vh;overflow-y:auto;box-shadow:0 12px 40px rgba(0,0,0,.3)">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid #eef2ee;position:sticky;top:0;background:#fff;z-index:1">
      <div>
        <div id="bkMTitle" style="font-weight:700;font-size:1rem"></div>
        <div id="bkMSub" style="font-size:.8rem;color:var(--text-muted);margin-top:.1rem"></div>
      </div>
      <button type="button" onclick="document.getElementById('bkDetailModal').style.display='none'" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#999;line-height:1">✕</button>
    </div>
    <div id="bkMBody" style="padding:1.25rem"></div>
  </div>
</div>

<!-- Edit Booking Modal -->
<div id="editBookingModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9001;align-items:center;justify-content:center" onclick="if(event.target===this)closeEditBookingModal()">
  <div style="background:#fff;border-radius:12px;width:min(640px,95vw);max-height:90vh;overflow-y:auto;box-shadow:0 12px 40px rgba(0,0,0,.3)">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border);position:sticky;top:0;background:#fff;z-index:1">
      <div>
        <div style="font-weight:700;font-size:1.05rem" id="editModalTitle">✏️ Edit Booking</div>
        <div style="font-size:.78rem;color:var(--text-muted);margin-top:.1rem">Change dates, room, guest info, payment, or status</div>
      </div>
      <button type="button" onclick="closeEditBookingModal()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#999;line-height:1">✕</button>
    </div>
    <form method="POST" action="admin.php?section=<?= htmlspecialchars($section ?? 'bookings') ?>" style="padding:1.25rem">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_booking">
      <input type="hidden" name="id" id="editBkId" value="">
      <input type="hidden" name="return_section" value="<?= htmlspecialchars($section ?? 'bookings') ?>">

      <div style="font-size:.8rem;font-weight:700;color:var(--primary);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.5rem">Stay Details</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem;margin-bottom:1rem">
        <div class="fld">
          <label>Property</label>
          <select name="room_id" id="editBkRoom" required>
            <?php foreach ($rooms as $rid => $rname): ?>
            <option value="<?= htmlspecialchars($rid) ?>"><?= htmlspecialchars($rname) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fld">
          <label>Check-in Date</label>
          <input type="date" name="check_in" id="editBkCheckIn" required onchange="calcEditNights()">
        </div>
        <div class="fld">
          <label>Check-out Date <span id="editNightsDisplay" style="color:var(--primary);font-weight:700;margin-left:.25rem"></span></label>
          <input type="date" name="check_out" id="editBkCheckOut" required onchange="calcEditNights()">
        </div>
        <div class="fld">
          <label>Status</label>
          <select name="status" id="editBkStatus">
            <option value="confirmed">Confirmed</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>
        <div class="fld">
          <label>Booking Source</label>
          <select name="source" id="editBkSource">
            <option value="direct">Direct (Website)</option>
            <option value="razorpay">Direct (Razorpay)</option>
            <option value="manual">Manual / Phone</option>
            <option value="airbnb">Airbnb</option>
            <option value="booking.com">Booking.com</option>
            <option value="agoda">Agoda</option>
            <option value="makemytrip">MakeMyTrip</option>
            <option value="blocked">Blocked Date</option>
          </select>
        </div>
      </div>

      <div style="font-size:.8rem;font-weight:700;color:var(--primary);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.5rem">Guest Details</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem;margin-bottom:1rem">
        <div class="fld">
          <label>Guest Name</label>
          <input type="text" name="guest_name" id="editBkGuestName" required placeholder="Guest Name">
        </div>
        <div class="fld">
          <label>Phone Number</label>
          <input type="tel" name="guest_phone" id="editBkPhone" placeholder="e.g. +91 9876543210">
        </div>
        <div class="fld">
          <label>WhatsApp Number</label>
          <input type="tel" name="whatsapp_number" id="editBkWa" placeholder="e.g. +91 9876543210">
        </div>
        <div class="fld">
          <label>Email Address</label>
          <input type="email" name="guest_email" id="editBkEmail" placeholder="guest@example.com">
        </div>
        <div class="fld">
          <label>Booking Reference / OTA ID</label>
          <input type="text" name="booking_ref" id="editBkRef" placeholder="e.g. HM12345678">
        </div>
      </div>

      <div style="font-size:.8rem;font-weight:700;color:var(--primary);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.5rem">Payment &amp; Pricing</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem;margin-bottom:1rem">
        <div class="fld">
          <label>Total Amount (₹)</label>
          <input type="number" name="amount" id="editBkAmount" min="0" step="1" oninput="updateEditBalance()">
        </div>
        <div class="fld">
          <label>Amount Paid (₹)</label>
          <input type="number" name="amount_paid" id="editBkPaid" min="0" step="1" oninput="updateEditBalance()">
        </div>
        <div class="fld">
          <label>Payment Method</label>
          <select name="payment_method" id="editBkPayMethod">
            <option value="cash">💵 Cash</option>
            <option value="upi">📱 UPI / GPay / PhonePe</option>
            <option value="bank_transfer">🏦 Bank Transfer</option>
            <option value="online">💳 Online / Razorpay</option>
          </select>
        </div>
        <div class="fld">
          <label>Balance Status</label>
          <input type="text" id="editBkBalance" readonly style="background:#f7faf7;font-weight:700">
        </div>
      </div>

      <div class="fld" style="margin-bottom:1.25rem">
        <label>Notes &amp; Special Requests</label>
        <textarea name="notes" id="editBkNotes" rows="2" placeholder="Special requests, arrival notes, allergy alerts..."></textarea>
      </div>

      <div style="display:flex;gap:.75rem;justify-content:flex-end;border-top:1px solid var(--border);padding-top:1rem;flex-wrap:wrap">
        <button type="button" class="btn btn-grey" onclick="closeEditBookingModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">💾 Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Mobile hamburger + overlay (only when logged in) -->
<button class="mob-menu-btn" id="mobMenuBtn" aria-label="Open menu">☰</button>
<div class="mob-sidebar-overlay" id="mobOverlay"></div>

<!-- Bottom tab navigation -->
<nav class="mob-nav" aria-label="Bottom navigation">
  <div class="mob-nav-inner">
    <?php
    $sec = $section ?? 'dashboard';
    $mobTabs = [
      ['dashboard', '📊', 'Dashboard'],
      ['day',       '📅', 'Today'],
      ['bookings',  '📋', 'Bookings'],
      ['wa_inbox',  '💬', 'WA' . ($waUnread > 0 ? " ({$waUnread})" : '')],
      ['pricing',   '💰', 'Pricing'],
    ];
    foreach ($mobTabs as [$s, $icon, $label]):
      $active = $sec === $s ? ' active' : '';
    ?>
    <a href="admin.php?section=<?= $s ?>" class="<?= $active ?>">
      <span class="mn-icon"><?= $icon ?></span>
      <?= $label ?>
    </a>
    <?php endforeach; ?>
    <a href="#" class="mn-sync" onclick="runSync();return false">
      <span class="mn-icon">⟳</span>
      Sync
    </a>
  </div>
</nav>
<?php endif; ?>

</body>
</html>
