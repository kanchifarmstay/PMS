<?php
/**
 * Channel Setup Wizard — one-time guide to connect Airbnb, Booking.com, etc.
 * After this is done, cron.php handles everything automatically.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

$msg = '';
$msgType = '';

// Handle form submission — save a new calendar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_calendar') {
        $roomId   = trim($_POST['room_id']   ?? '');
        $platform = strtolower(trim($_POST['platform'] ?? ''));
        $url      = trim($_POST['ical_url']  ?? '');

        if ($roomId && $platform && $url && filter_var($url, FILTER_VALIDATE_URL)) {
            addExternalCalendar($roomId, $platform, $url);
            $msg     = "Calendar connected! Run your first sync below or wait for the cron job.";
            $msgType = 'success';
        } else {
            $msg     = "Please fill in all fields and ensure the URL is valid.";
            $msgType = 'error';
        }
    }

    if ($_POST['action'] === 'test_sync') {
        $id = (int)($_POST['cal_id'] ?? 0);
        // Fetch just this one calendar
        $cals = getExternalCalendars();
        foreach ($cals as $cal) {
            if ($cal['id'] === $id) {
                require_once __DIR__ . '/sync.php';
                // syncOneCalendar is already defined in sync.php
                $res = syncOneCalendar($cal);
                if ($res['success']) {
                    $msg = "Sync OK — {$res['imported']} new booking(s) imported.";
                    $msgType = 'success';
                } else {
                    $msg = "Sync failed: " . htmlspecialchars($res['error']);
                    $msgType = 'error';
                }
                break;
            }
        }
    }
}

$rooms     = ROOM_IDS;
$calendars = getExternalCalendars();
$platforms = ['airbnb','booking.com','agoda','makemytrip','direct'];

$cronCmd = 'php ' . escapeshellarg(__DIR__ . '/cron.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Channel Setup Wizard — Kanchi Farm Stay</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#f0f4f8;color:#1a202c;min-height:100vh}
.topbar{background:#2d3748;padding:14px 28px;display:flex;align-items:center;gap:16px}
.topbar a{color:#a0aec0;text-decoration:none;font-size:.9rem}
.topbar a:hover{color:#fff}
.topbar .title{color:#fff;font-weight:700;font-size:1.1rem;margin-right:auto}
.page{max-width:900px;margin:0 auto;padding:32px 20px}
h1{font-size:1.6rem;font-weight:700;margin-bottom:6px;color:#2d3748}
.subtitle{color:#718096;margin-bottom:32px;font-size:.95rem}
.wizard-steps{display:flex;gap:0;margin-bottom:40px;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0}
.step{flex:1;padding:16px 20px;background:#fff;border-right:1px solid #e2e8f0;position:relative}
.step:last-child{border-right:none}
.step-num{width:28px;height:28px;border-radius:50%;background:#e2e8f0;color:#4a5568;font-weight:700;font-size:.85rem;display:flex;align-items:center;justify-content:center;margin-bottom:8px}
.step.done .step-num{background:#48bb78;color:#fff}
.step.active .step-num{background:#4299e1;color:#fff}
.step h3{font-size:.9rem;font-weight:600;color:#2d3748;margin-bottom:4px}
.step p{font-size:.8rem;color:#718096}
.card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:28px;margin-bottom:24px}
.card h2{font-size:1.1rem;font-weight:700;color:#2d3748;margin-bottom:4px}
.card-sub{font-size:.85rem;color:#718096;margin-bottom:20px}
.platform-guide{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}
.guide-box{background:#f7fafc;border:1px solid #e2e8f0;border-radius:10px;padding:20px}
.guide-box h3{font-size:.95rem;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.guide-box ol{padding-left:18px;font-size:.85rem;color:#4a5568;line-height:1.8}
.guide-box .tip{background:#ebf8ff;border:1px solid #bee3f8;border-radius:6px;padding:10px 12px;font-size:.8rem;color:#2b6cb0;margin-top:12px}
.ab-icon{color:#ff5a5f;font-weight:900}
.bk-icon{color:#003580;font-weight:900}
label{display:block;font-size:.85rem;font-weight:600;color:#4a5568;margin-bottom:6px}
input,select{width:100%;padding:10px 14px;border:1px solid #e2e8f0;border-radius:8px;font-size:.9rem;color:#2d3748;background:#fff;outline:none;transition:border-color .2s}
input:focus,select:focus{border-color:#4299e1;box-shadow:0 0 0 3px rgba(66,153,225,.1)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.form-row-3{display:grid;grid-template-columns:1fr 1fr 2fr;gap:16px;margin-bottom:16px}
.btn{padding:10px 22px;border:none;border-radius:8px;font-size:.9rem;font-weight:600;cursor:pointer;transition:all .2s}
.btn-primary{background:#4299e1;color:#fff}
.btn-primary:hover{background:#3182ce}
.btn-green{background:#48bb78;color:#fff}
.btn-green:hover{background:#38a169}
.msg{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:.9rem;font-weight:500}
.msg.success{background:#f0fff4;border:1px solid #9ae6b4;color:#276749}
.msg.error{background:#fff5f5;border:1px solid #feb2b2;color:#c53030}
.cal-table{width:100%;border-collapse:collapse;font-size:.88rem}
.cal-table th{background:#f7fafc;padding:10px 14px;text-align:left;font-weight:600;color:#4a5568;border-bottom:2px solid #e2e8f0}
.cal-table td{padding:10px 14px;border-bottom:1px solid #f0f4f8;color:#2d3748}
.cal-table tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:600}
.badge-airbnb{background:#fff0f0;color:#c53030}
.badge-booking{background:#e8f0fe;color:#1a56db}
.badge-agoda{background:#fef3c7;color:#92400e}
.badge-makemytrip{background:#f0fdf4;color:#166534}
.badge-active{background:#f0fff4;color:#276749}
.cron-box{background:#1a202c;border-radius:10px;padding:20px 24px;margin:16px 0}
.cron-box code{color:#68d391;font-family:'Courier New',monospace;font-size:.9rem;word-break:break-all}
.cron-steps{counter-reset:cs}
.cron-step{display:flex;gap:14px;align-items:flex-start;padding:14px 0;border-bottom:1px solid #f0f4f8}
.cron-step:last-child{border-bottom:none}
.cron-step-num{min-width:28px;height:28px;border-radius:50%;background:#4299e1;color:#fff;font-weight:700;font-size:.85rem;display:flex;align-items:center;justify-content:center}
.cron-step-body h4{font-size:.9rem;font-weight:600;color:#2d3748;margin-bottom:4px}
.cron-step-body p{font-size:.83rem;color:#718096;line-height:1.5}
.status-ok{color:#38a169;font-weight:600}
.status-none{color:#a0aec0}
.empty-state{text-align:center;padding:32px;color:#a0aec0;font-size:.9rem}
@media(max-width:640px){.platform-guide,.form-row,.form-row-3{grid-template-columns:1fr}.wizard-steps{flex-direction:column}}
</style>
</head>
<body>
<div class="topbar">
    <span class="title">Kanchi Farm Stay — Channel Setup Wizard</span>
    <a href="admin.php">← Back to Dashboard</a>
    <a href="admin.php?section=channels">Channels</a>
</div>

<div class="page">
    <h1>Automate OTA Booking Sync</h1>
    <p class="subtitle">A one-time 3-minute setup. After this, bookings from Airbnb, Booking.com & others sync automatically every 30 minutes — no manual work needed.</p>

    <!-- Progress steps -->
    <div class="wizard-steps">
        <div class="step <?= empty($calendars) ? 'active' : 'done' ?>">
            <div class="step-num"><?= empty($calendars) ? '1' : '✓' ?></div>
            <h3>Get iCal URLs</h3>
            <p>From each OTA platform</p>
        </div>
        <div class="step <?= !empty($calendars) && count($calendars) > 0 ? 'done' : '' ?>">
            <div class="step-num">2</div>
            <h3>Connect Calendars</h3>
            <p>Paste URLs below once</p>
        </div>
        <div class="step">
            <div class="step-num">3</div>
            <h3>Set Up Cron Job</h3>
            <p>Auto-sync every 30 min</p>
        </div>
        <div class="step">
            <div class="step-num">4</div>
            <h3>Done ✓</h3>
            <p>Fully automatic forever</p>
        </div>
    </div>

    <?php if ($msg): ?>
    <div class="msg <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- Step 1: How to get iCal URLs -->
    <div class="card">
        <h2>Step 1 — Get Your iCal Export URLs</h2>
        <p class="card-sub">Each platform gives you a private iCal link. Copy it from their site — you only do this once per room per platform.</p>

        <div class="platform-guide">
            <div class="guide-box">
                <h3><span class="ab-icon">✦</span> Airbnb</h3>
                <ol>
                    <li>Go to <strong>airbnb.com</strong> → Log in</li>
                    <li>Click <strong>Calendar</strong> in the top menu</li>
                    <li>Select your <strong>listing</strong></li>
                    <li>Click <strong>Availability</strong> tab</li>
                    <li>Scroll to <strong>"Sync calendars"</strong></li>
                    <li>Click <strong>"Export calendar"</strong></li>
                    <li>Copy the <strong>webcal://...</strong> URL</li>
                    <li>Replace <code>webcal://</code> with <code>https://</code></li>
                </ol>
                <div class="tip">💡 The URL looks like: <br><code>https://www.airbnb.com/calendar/ical/XXXXXXX.ics?s=XXXXX</code></div>
            </div>

            <div class="guide-box">
                <h3><span class="bk-icon">■</span> Booking.com</h3>
                <ol>
                    <li>Log in to <strong>Booking.com Extranet</strong></li>
                    <li>Go to <strong>Calendar</strong></li>
                    <li>Click <strong>Export</strong> (top right)</li>
                    <li>Choose your <strong>property/room type</strong></li>
                    <li>Click <strong>"iCal link"</strong></li>
                    <li>Copy the full URL shown</li>
                </ol>
                <div class="tip">💡 The URL looks like: <br><code>https://ical.booking.com/v1/export?t=XXXXXXXX</code></div>
            </div>

            <div class="guide-box">
                <h3>🏨 Agoda</h3>
                <ol>
                    <li>Log in to <strong>YCS (Agoda Extranet)</strong></li>
                    <li>Go to <strong>Rates &amp; Availability</strong></li>
                    <li>Click <strong>Calendar Sync</strong></li>
                    <li>Choose <strong>Export Calendar</strong></li>
                    <li>Copy the iCal URL</li>
                </ol>
                <div class="tip">💡 Contact Agoda support if you don't see the option — some accounts need it enabled.</div>
            </div>

            <div class="guide-box">
                <h3>🔴 MakeMyTrip</h3>
                <ol>
                    <li>Log in to <strong>MMT Partner Portal</strong></li>
                    <li>Go to <strong>Property Management</strong></li>
                    <li>Find <strong>Calendar / Availability</strong></li>
                    <li>Look for <strong>iCal Export</strong> option</li>
                    <li>Copy the URL</li>
                </ol>
                <div class="tip">💡 MMT may call it "calendar feed" or "availability export". Contact partner support if needed.</div>
            </div>
        </div>
    </div>

    <!-- Step 2: Connect a calendar -->
    <div class="card">
        <h2>Step 2 — Connect a Calendar</h2>
        <p class="card-sub">Paste the iCal URL you copied above. Add one row per room per platform.</p>

        <form method="POST">
            <input type="hidden" name="action" value="add_calendar">
            <div class="form-row-3">
                <div>
                    <label>Room</label>
                    <select name="room_id" required>
                        <option value="">Select room…</option>
                        <?php foreach ($rooms as $id => $name): ?>
                        <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Platform</label>
                    <select name="platform" required>
                        <option value="">Select platform…</option>
                        <?php foreach ($platforms as $p): ?>
                        <option value="<?= $p ?>"><?= ucfirst($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>iCal URL (paste from platform)</label>
                    <input type="url" name="ical_url" placeholder="https://www.airbnb.com/calendar/ical/..." required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Connect Calendar</button>
        </form>
    </div>

    <!-- Connected calendars -->
    <div class="card">
        <h2>Connected Calendars</h2>
        <p class="card-sub">These are synced automatically by the cron job every 30 minutes.</p>

        <?php if (empty($calendars)): ?>
        <div class="empty-state">No calendars connected yet. Add your first one above ↑</div>
        <?php else: ?>
        <table class="cal-table">
            <thead>
                <tr>
                    <th>Room</th>
                    <th>Platform</th>
                    <th>Last Synced</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($calendars as $cal): ?>
                <tr>
                    <td><?= htmlspecialchars($rooms[$cal['room_id']] ?? $cal['room_id']) ?></td>
                    <td><span class="badge badge-<?= strtolower(str_replace('.','',explode('.',$cal['platform'])[0])) ?>"><?= htmlspecialchars(ucfirst($cal['platform'])) ?></span></td>
                    <td><?= $cal['last_synced'] ? date('d M H:i', strtotime($cal['last_synced'])) : '<span class="status-none">Never</span>' ?></td>
                    <td><span class="badge badge-active"><?= $cal['is_active'] ? '● Active' : 'Paused' ?></span></td>
                    <td>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="test_sync">
                            <input type="hidden" name="cal_id" value="<?= $cal['id'] ?>">
                            <button type="submit" class="btn btn-green" style="padding:6px 14px;font-size:.8rem">▶ Test Sync</button>
                        </form>
                        <a href="admin.php?action=delete_calendar&id=<?= $cal['id'] ?>" class="btn" style="padding:6px 14px;font-size:.8rem;background:#fff5f5;color:#c53030;border:1px solid #fed7d7" onclick="return confirm('Remove this calendar?')">Remove</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Step 3: Cron job -->
    <div class="card">
        <h2>Step 3 — Set Up Auto-Sync (Cron Job)</h2>
        <p class="card-sub">This is what makes everything automatic. Set it once in Hostinger and your calendars sync every 30 minutes forever.</p>

        <div class="cron-steps">
            <div class="cron-step">
                <div class="cron-step-num">1</div>
                <div class="cron-step-body">
                    <h4>Log in to Hostinger hPanel</h4>
                    <p>Go to <strong>hpanel.hostinger.com</strong> → select your hosting plan</p>
                </div>
            </div>
            <div class="cron-step">
                <div class="cron-step-num">2</div>
                <div class="cron-step-body">
                    <h4>Navigate to Cron Jobs</h4>
                    <p>In hPanel: <strong>Advanced → Cron Jobs</strong></p>
                </div>
            </div>
            <div class="cron-step">
                <div class="cron-step-num">3</div>
                <div class="cron-step-body">
                    <h4>Create a new cron job with these settings</h4>
                    <p>Set the interval to <strong>every 30 minutes</strong>, and paste this command:</p>
                    <div class="cron-box">
                        <code><?= htmlspecialchars('php /home/u123456789/domains/kanchifarmstay.com/public_html/channel-manager/cron.php') ?></code>
                    </div>
                    <p style="margin-top:8px;font-size:.82rem;color:#e53e3e">⚠️ Replace <code>u123456789</code> with your actual Hostinger username. Find it in hPanel → Hosting → your domain — the path shown is your real home directory.</p>
                </div>
            </div>
            <div class="cron-step">
                <div class="cron-step-num">4</div>
                <div class="cron-step-body">
                    <h4>Save — done!</h4>
                    <p>Bookings from Airbnb, Booking.com etc. will now appear in your PMS within 30 minutes of being made. You'll also get a WhatsApp notification for each new booking.</p>
                </div>
            </div>
        </div>

        <div style="background:#f0fff4;border:1px solid #9ae6b4;border-radius:8px;padding:16px 20px;margin-top:20px">
            <strong style="color:#276749">✓ After setup, bookings are fully automatic:</strong>
            <ul style="margin-top:8px;padding-left:20px;font-size:.88rem;color:#2f855a;line-height:1.9">
                <li>Guest books on Airbnb → Airbnb updates the iCal feed within minutes</li>
                <li>Cron runs every 30 min → sync.php fetches the feed → booking saved to PMS</li>
                <li>WhatsApp notification sent to your phone</li>
                <li>Calendar on your website shows the dates as blocked</li>
                <li>All without you doing anything</li>
            </ul>
        </div>
    </div>

    <div style="text-align:center;padding:10px 0 40px;color:#a0aec0;font-size:.83rem">
        Need help? The cron command runs <code>cron.php</code> which calls <code>sync.php</code> internally.<br>
        Check <a href="admin.php?section=channels" style="color:#4299e1">Channels page</a> to see last sync times after setup.
    </div>
</div>
</body>
</html>
