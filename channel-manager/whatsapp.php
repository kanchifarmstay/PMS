<?php
require_once __DIR__ . '/config.php';

function sendWhatsAppNotification(string $message): void {
    if (WHATSAPP_PROVIDER === 'none') return;
    if (WHATSAPP_PROVIDER === 'callmebot') {
        _sendViaCallMeBot($message);
    }
}

function _sendViaCallMeBot(string $message): void {
    $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
        'phone'  => WHATSAPP_PHONE,
        'text'   => $message,
        'apikey' => CALLMEBOT_API_KEY,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'KanchiFarmStay-ChannelManager/1.0',
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function buildBookingMessage(array $b): string {
    $source = strtoupper($b['source'] ?? 'DIRECT');
    $nights = (int)ceil((strtotime($b['check_out']) - strtotime($b['check_in'])) / 86400);
    $msg    = "New Booking - Kanchi Farm Stay\n\n";
    $msg   .= "Room: {$b['room_name']}\n";
    $msg   .= "Check-in:  {$b['check_in']}\n";
    $msg   .= "Check-out: {$b['check_out']} ({$nights} night" . ($nights !== 1 ? 's' : '') . ")\n";
    $msg   .= "Guest: " . ($b['guest_name'] ?: 'Guest') . "\n";
    if (!empty($b['guest_phone'])) $msg .= "Phone: {$b['guest_phone']}\n";
    if (!empty($b['whatsapp_number']) && $b['whatsapp_number'] !== $b['guest_phone'])
        $msg .= "WhatsApp: {$b['whatsapp_number']}\n";
    if (!empty($b['guest_email'])) $msg .= "Email: {$b['guest_email']}\n";
    if (!empty($b['amount']) && $b['amount'] > 0) {
        $msg .= "Total: Rs." . number_format($b['amount']) . "\n";
        $paid = (float)($b['amount_paid'] ?? 0);
        if ($paid > 0) {
            $balance = $b['amount'] - $paid;
            $method  = ucfirst($b['payment_method'] ?? 'cash');
            $msg .= "Paid: Rs." . number_format($paid) . " via {$method}\n";
            if ($balance > 0) $msg .= "Balance due: Rs." . number_format($balance) . "\n";
        }
    }
    $msg .= "Source: {$source}\n";
    if (!empty($b['booking_ref'])) $msg .= "Ref: {$b['booking_ref']}\n";
    $msg .= "\nBooking ID: #" . ($b['id'] ?? 'N/A');
    return $msg;
}

// ── Meta WhatsApp Business Cloud API ─────────────────────────

function sendMetaWAText(string $to, string $text): bool {
    $token   = META_WA_TOKEN;
    $phoneId = META_WA_PHONE_ID;
    if (!$token || !$phoneId) return false;
    $to = preg_replace('/\D/', '', $to);
    $url = "https://graph.facebook.com/v18.0/{$phoneId}/messages";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS     => json_encode([
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['body' => $text],
        ]),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

function sendMetaWABookingConfirmation(array $b): bool {
    $wa = $b['whatsapp_number'] ?: $b['guest_phone'] ?? '';
    if (!$wa) return false;
    $nights  = (int)ceil((strtotime($b['check_out']) - strtotime($b['check_in'])) / 86400);
    $balance = max(0, ($b['amount'] ?? 0) - ($b['amount_paid'] ?? 0));
    $pdfUrl  = SITE_URL . '/channel-manager/booking-pdf.php?id=' . ($b['id'] ?? 0);
    $msg  = "Hi " . ($b['guest_name'] ?: 'there') . "! 🌿\n\n";
    $msg .= "*Booking Confirmed — Kanchi Farm Stay*\n";
    $msg .= "━━━━━━━━━━━━━━━\n";
    $msg .= "📍 Room: {$b['room_name']}\n";
    $msg .= "📅 Check-in: " . date('D, d M Y', strtotime($b['check_in'])) . " (3 PM)\n";
    $msg .= "🚪 Check-out: " . date('D, d M Y', strtotime($b['check_out'])) . " (11 AM)\n";
    $msg .= "🌙 Duration: {$nights} night" . ($nights !== 1 ? 's' : '') . "\n";
    if (!empty($b['amount']) && $b['amount'] > 0) {
        $msg .= "💰 Total: ₹" . number_format($b['amount']) . "\n";
        if ($balance > 0) $msg .= "⏳ Balance at check-in: ₹" . number_format($balance) . "\n";
    }
    $msg .= "━━━━━━━━━━━━━━━\n";
    $msg .= "📄 Your confirmation: {$pdfUrl}\n\n";
    $msg .= "We look forward to welcoming you! 🏡\n— Kanchi Farm Stay";
    return sendMetaWAText($wa, $msg);
}

// Guest-facing WhatsApp reply from inbox
function sendWAReply(int $conversationId, string $body): bool {
    require_once __DIR__ . '/db.php';
    $conv = getWAConversation($conversationId);
    if (!$conv) return false;

    addWAMessage($conversationId, 'staff', $body);

    // Try Meta API first, fall back to CallMeBot
    if (META_WA_TOKEN && META_WA_PHONE_ID) {
        return sendMetaWAText($conv['phone'], $body);
    }
    sendWhatsAppNotification($body); // CallMeBot (sends to YOUR number, not guest — demo only)
    return true;
}
