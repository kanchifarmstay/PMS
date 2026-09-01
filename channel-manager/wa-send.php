<?php
/**
 * AJAX endpoint — send a WhatsApp reply from the admin inbox.
 * POST /channel-manager/wa-send.php
 * Body: { conversation_id, body, action? }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/whatsapp.php';

startSecureSession();
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];
requireValidCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? null));

$convId = (int)($data['conversation_id'] ?? 0);
$body   = trim($data['body'] ?? '');
$action = $data['action'] ?? 'reply';  // 'reply' | 'mark_read' | 'update_status'

if (!$convId) {
    echo json_encode(['ok' => false, 'error' => 'Missing conversation_id']);
    exit;
}

$conv = getWAConversation($convId);
if (!$conv) {
    echo json_encode(['ok' => false, 'error' => 'Conversation not found']);
    exit;
}

if ($action === 'mark_read') {
    markConversationRead($convId);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'update_status') {
    $newStatus = $data['status'] ?? 'awaiting_reply';
    updateConversation($convId, ['status' => $newStatus]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'reply') {
    if (!$body) {
        echo json_encode(['ok' => false, 'error' => 'Empty message body']);
        exit;
    }
    $sent = sendWAReply($convId, $body);
    updateConversation($convId, ['status' => 'awaiting_reply']);
    echo json_encode(['ok' => true, 'sent_via_meta' => $sent]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
