<?php
/**
 * Meta WhatsApp Business Cloud API Webhook
 * 1. Verify endpoint:  GET  ?hub.mode=subscribe&hub.challenge=...&hub.verify_token=...
 * 2. Receive messages: POST (JSON payload from Meta)
 *
 * Set this URL in Meta Developer Console →
 *   WhatsApp → Configuration → Webhook → Callback URL:
 *   https://kanchifarmstay.com/channel-manager/wa-webhook.php
 *   Verify token: (value of META_WA_VERIFY_TOKEN in config.php)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp.php';

// ── Webhook verification (GET) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (
        ($_GET['hub_mode']         ?? '') === 'subscribe' &&
        ($_GET['hub_verify_token'] ?? '') === META_WA_VERIFY_TOKEN
    ) {
        http_response_code(200);
        echo (int)$_GET['hub_challenge'];
    } else {
        http_response_code(403);
        echo 'Forbidden';
    }
    exit;
}

// ── Incoming message (POST) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

// Basic structure check
$entry   = $payload['entry'][0]   ?? null;
$changes = $entry['changes'][0]   ?? null;
$value   = $changes['value']      ?? null;

if (!$value) { http_response_code(200); echo 'ok'; exit; }

// Process each message
foreach (($value['messages'] ?? []) as $msg) {
    $from      = preg_replace('/\D/', '', $msg['from'] ?? '');
    $msgId     = $msg['id'] ?? '';
    $type      = $msg['type'] ?? 'text';
    $body      = '';

    if ($type === 'text') {
        $body = $msg['text']['body'] ?? '';
    } elseif ($type === 'image') {
        $body = '[Image received]';
    } elseif ($type === 'document') {
        $body = '[Document: ' . ($msg['document']['filename'] ?? 'file') . ']';
    } elseif ($type === 'audio') {
        $body = '[Voice message]';
    } else {
        $body = "[{$type} message]";
    }

    if (!$from || !$body) continue;

    // Get contact name from contacts array if present
    $contacts = $value['contacts'] ?? [];
    $guestName = 'Unknown Guest';
    foreach ($contacts as $c) {
        if (preg_replace('/\D/', '', $c['wa_id'] ?? '') === $from) {
            $guestName = $c['profile']['name'] ?? 'Unknown Guest';
            break;
        }
    }

    // Create or get conversation
    $conv   = getOrCreateConversation($from, $guestName);
    $convId = (int)$conv['id'];

    // Detect booking inquiry
    $isInquiry = isBookingInquiry($body);
    $extracted = $isInquiry ? parseWAInquiry($body) : [];

    // Save message
    addWAMessage($convId, 'guest', $body, [
        'is_inquiry'          => $isInquiry ? 1 : 0,
        'extracted_check_in'  => $extracted['check_in']  ?? '',
        'extracted_check_out' => $extracted['check_out'] ?? '',
        'extracted_guests'    => $extracted['guests']    ?? 0,
        'extracted_room'      => $extracted['room']      ?? '',
        'meta_message_id'     => $msgId,
    ]);

    // Update conversation status
    $newStatus = $isInquiry ? 'new_inquiry' : 'awaiting_reply';
    updateConversation($convId, [
        'guest_name' => $guestName,
        'status'     => $conv['status'] === 'confirmed' ? 'confirmed' : $newStatus,
    ]);

    // Notify admin via CallMeBot
    $notification = "WA Inbox — New message from {$guestName} (+{$from}):\n{$body}";
    if ($isInquiry) $notification .= "\n⚡ BOOKING INQUIRY detected!";
    sendWhatsAppNotification($notification);
}

http_response_code(200);
echo 'ok';
