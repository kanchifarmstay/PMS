<?php
/**
 * Notification Test Page
 * Tests WhatsApp and email delivery. Admin-only.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/whatsapp.php';

startSecureSession();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: admin.php'); exit;
}

$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    // ── Test WhatsApp ───────────────────────────────────────
    if ($action === 'test_whatsapp') {
        if (CALLMEBOT_API_KEY === 'YOUR_API_KEY_HERE') {
            $results[] = ['type' => 'error', 'msg' => 'CallMeBot API key not set. Follow the setup steps below first.'];
        } else {
            $msg = "Test from Kanchi Farm Stay PMS\nWhatsApp notifications are working! ✅\n" . date('d M Y H:i');
            sendWhatsAppNotification($msg);
            $results[] = ['type' => 'success', 'msg' => 'WhatsApp sent to +' . WHATSAPP_PHONE . '. Check your phone!'];
        }
    }

    // ── Test Email ──────────────────────────────────────────
    if ($action === 'test_email') {
        $to      = 'ops@kanchifarmstay.com';
        $from    = 'ops@kanchifarmstay.com';
        $subject = 'Test Email — Kanchi Farm Stay PMS ' . date('H:i');
        $html    = "
<html><body style='font-family:Arial,sans-serif;padding:24px;color:#2d3748'>
<h2 style='color:#2e5c3a'>✅ Email is working!</h2>
<p>This test email was sent from your Kanchi Farm Stay PMS at " . date('d M Y H:i:s') . ".</p>
<p>You will receive booking emails at this address whenever a guest completes a payment.</p>
<hr style='border:1px solid #e2e8f0'>
<p style='color:#718096;font-size:12px'>Sent from: " . $_SERVER['SERVER_NAME'] . "</p>
</body></html>";

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Kanchi Farm Stay <{$from}>\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        $sent = mail($to, $subject, $html, $headers, "-f{$from}");
        if ($sent) {
            $results[] = ['type' => 'success', 'msg' => "Email dispatched to {$to}. Check your inbox (and spam folder)."];
        } else {
            $results[] = ['type' => 'error', 'msg' => 'mail() returned false — PHP mail may be disabled on this server.'];
        }
    }
}

$keySet   = CALLMEBOT_API_KEY !== 'YOUR_API_KEY_HERE';
$phoneOk  = WHATSAPP_PHONE    !== '91XXXXXXXXXX';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Notification Test — Kanchi Farm Stay</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#f0f4f0;padding:24px;color:#1a202c}
.card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:24px;margin-bottom:20px;max-width:680px}
h1{font-size:1.3rem;font-weight:700;color:#2e5c3a;margin-bottom:4px}
h2{font-size:1rem;font-weight:700;margin-bottom:16px;color:#2d3748}
.sub{color:#718096;font-size:.85rem;margin-bottom:20px}
.status{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;font-size:.88rem;font-weight:600;margin-bottom:10px}
.status.ok{background:#f0fff4;color:#276749;border:1px solid #9ae6b4}
.status.warn{background:#fffbeb;color:#92400e;border:1px solid #fcd34d}
.status.err{background:#fff5f5;color:#c53030;border:1px solid #feb2b2}
.msg.success{background:#f0fff4;border:1px solid #9ae6b4;color:#276749;padding:12px 16px;border-radius:8px;margin-bottom:12px;font-size:.88rem}
.msg.error{background:#fff5f5;border:1px solid #feb2b2;color:#c53030;padding:12px 16px;border-radius:8px;margin-bottom:12px;font-size:.88rem}
label{display:block;font-size:.83rem;font-weight:600;color:#4a5568;margin-bottom:6px}
input[type=text]{width:100%;padding:10px 14px;border:1px solid #e2e8f0;border-radius:8px;font-size:.9rem;margin-bottom:12px}
.btn{padding:10px 20px;border:none;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;margin-right:8px}
.btn-green{background:#4a7c59;color:#fff}
.btn-blue{background:#3182ce;color:#fff}
.btn-wa{background:#25d366;color:#fff}
ol{padding-left:20px;font-size:.85rem;color:#4a5568;line-height:2}
ol code{background:#f7fafc;padding:2px 6px;border-radius:4px;font-size:.83rem}
a{color:#3182ce}
.back{display:inline-block;margin-bottom:16px;font-size:.85rem;color:#4a7c59;text-decoration:none}
</style>
</head>
<body>
<a href="admin.php" class="back">← Back to Dashboard</a>

<div class="card">
    <h1>🔔 Notification Test & Setup</h1>
    <p class="sub">Validate that WhatsApp and email alerts are working for every new booking.</p>

    <?php foreach ($results as $r): ?>
    <div class="msg <?= $r['type'] ?>"><?= htmlspecialchars($r['msg']) ?></div>
    <?php endforeach; ?>

    <!-- Status -->
    <div class="status <?= $phoneOk ? 'ok' : 'err' ?>">
        <?= $phoneOk ? '✅' : '❌' ?> WhatsApp Phone: <?= WHATSAPP_PHONE ?>
    </div>
    <div class="status <?= $keySet ? 'ok' : 'warn' ?>">
        <?= $keySet ? '✅' : '⚠️' ?> CallMeBot API Key: <?= $keySet ? substr(CALLMEBOT_API_KEY, 0, 4) . '****' : 'NOT SET' ?>
    </div>
</div>

<!-- Step 1: WhatsApp Setup -->
<div class="card">
    <h2>Step 1 — Activate CallMeBot for WhatsApp</h2>
    <?php if (!$keySet): ?>
    <ol>
        <li>Save this number in your phone contacts: <strong>+34 644 69 07 99</strong> (name it "CallMeBot")</li>
        <li>Open WhatsApp and send this exact message to that contact:<br>
            <code>I allow callmebot to send me messages</code></li>
        <li>Within 1 minute you'll receive a WhatsApp reply with your <strong>API key</strong></li>
        <li>Add it to the server environment as <code>KFS_CALLMEBOT_API_KEY</code>, then reload this page.</li>
    </ol>
    <?php else: ?>
    <p style="color:#276749;font-size:.88rem;margin-bottom:16px">✅ API key is configured. Click below to send a test message to +<?= WHATSAPP_PHONE ?>.</p>
    <form method="POST">
    <?= csrfField() ?>
        <input type="hidden" name="action" value="test_whatsapp">
        <button type="submit" class="btn btn-wa">💬 Send Test WhatsApp</button>
    </form>
    <?php endif; ?>
</div>

<!-- Step 2: Email Test -->
<div class="card">
    <h2>Step 2 — Test Email Delivery</h2>
    <p style="color:#4a5568;font-size:.85rem;margin-bottom:16px">
        Sends a test email to <strong>ops@kanchifarmstay.com</strong>. Check your inbox and spam folder.
    </p>
    <form method="POST">
    <?= csrfField() ?>
        <input type="hidden" name="action" value="test_email">
        <button type="submit" class="btn btn-blue">📧 Send Test Email</button>
    </form>
    <p style="color:#718096;font-size:.8rem;margin-top:12px">
        If email lands in spam: add <strong>ops@kanchifarmstay.com</strong> to your contacts,
        or log in to Hostinger hPanel → <strong>Email → Spam Filters</strong> and whitelist your own domain.
    </p>
</div>

<!-- How it works -->
<div class="card">
    <h2>📋 What Happens on Every Direct Booking</h2>
    <table style="width:100%;font-size:.85rem;border-collapse:collapse">
        <tr style="background:#f7fafc"><th style="padding:8px 12px;text-align:left;color:#4a5568">Trigger</th><th style="padding:8px 12px;text-align:left;color:#4a5568">Action</th></tr>
        <tr><td style="padding:8px 12px;border-top:1px solid #e2e8f0">Guest clicks Pay</td><td style="padding:8px 12px;border-top:1px solid #e2e8f0">⏳ Email to ops: "Booking Started"</td></tr>
        <tr style="background:#f7fafc"><td style="padding:8px 12px;border-top:1px solid #e2e8f0">Payment succeeds</td><td style="padding:8px 12px;border-top:1px solid #e2e8f0">✅ Email to ops: full booking details</td></tr>
        <tr><td style="padding:8px 12px;border-top:1px solid #e2e8f0">Payment succeeds</td><td style="padding:8px 12px;border-top:1px solid #e2e8f0">💬 WhatsApp to +916383726094</td></tr>
        <tr style="background:#f7fafc"><td style="padding:8px 12px;border-top:1px solid #e2e8f0">Payment succeeds</td><td style="padding:8px 12px;border-top:1px solid #e2e8f0">📧 Confirmation email to guest</td></tr>
        <tr><td style="padding:8px 12px;border-top:1px solid #e2e8f0">Payment succeeds</td><td style="padding:8px 12px;border-top:1px solid #e2e8f0">📋 Booking saved to PMS dashboard</td></tr>
    </table>
</div>
</body>
</html>
