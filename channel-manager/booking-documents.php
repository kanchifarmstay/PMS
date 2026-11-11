<?php
declare(strict_types=1);

/**
 * Guest documents (Aadhaar / Passport / ID) for one booking:
 * view uploaded documents, upload new ID cards with UIDAI masking,
 * and manage guest records.
 *
 *   booking-documents.php?id=N
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/frontdesk-service.php';
require_once __DIR__ . '/wa-templates.php';
require_once __DIR__ . '/auth.php';

startSecureSession();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }
requirePermission('bookings.edit');

function dh(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$b = $id ? getBookingById($id) : null;
if (!$b) { http_response_code(404); die('Booking not found.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken($_POST['csrf_token'] ?? null);
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'upload') {
            $guestId = fdAddGuestId($id, (array)$_POST, $_FILES['id_photo'] ?? null);
            $docName = trim((string)($_POST['name'] ?? 'Guest'));
            $docType = (string)($_POST['id_type'] ?? 'aadhaar');
            kfsAudit('guest_id_uploaded', 'booking', $id, "{$docName} ({$docType}) ID #{$guestId}");
            $msg = 'Document uploaded successfully.';
            header('Location: booking-documents.php?id=' . $id . '&ok=' . rawurlencode($msg)); exit;
        } elseif ($act === 'delete') {
            $guestId = (int)($_POST['guest_id'] ?? 0);
            fdDeleteGuestId($guestId);
            kfsAudit('guest_id_deleted', 'booking', $id, "Guest ID #{$guestId}");
            $msg = 'Document record deleted.';
            header('Location: booking-documents.php?id=' . $id . '&ok=' . rawurlencode($msg)); exit;
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
    } catch (InvalidArgumentException $e) {
        header('Location: booking-documents.php?id=' . $id . '&err=' . rawurlencode($e->getMessage())); exit;
    }
}

$docs = fdGuestsForBooking($id);
$hasAadhaar = false;
foreach ($docs as $d) {
    if ($d['id_type'] === 'aadhaar') { $hasAadhaar = true; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Guest Documents · Booking #<?= dh(waBookingNo($b)) ?> — Kanchi Farm Stay</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 14px; color: #111827; background: #f3f4f6; }
  a { color: #1a5c3a; text-decoration: none; }
  a:hover { text-decoration: underline; }
  .wrap { max-width: 900px; margin: 0 auto; padding: 20px 16px 60px; }
  .top { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
  .top h1 { font-size: 20px; margin: 0 auto 0 0; color: #1a5c3a; }
  .btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; color: #111827; padding: 8px 14px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
  .btn:hover { background: #f9fafb; text-decoration: none; }
  .btn-primary { background: #1a5c3a; border-color: #1a5c3a; color: #fff; }
  .btn-primary:hover { background: #14492e; color: #fff; }
  .btn-danger { color: #991b1b; border-color: #fca5a5; background: #fff; }
  .btn-danger:hover { background: #fef2f2; color: #991b1b; }
  .btn-sm { padding: 4px 9px; font-size: 12px; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; }
  .card h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .6px; color: #1a5c3a; margin: 0 0 10px; }
  .sub-note { font-size: 12.5px; color: #6b7280; margin: 0 0 14px; }
  .sum { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
  .sum div b { display: block; font-size: 18px; font-weight: 800; color: #111827; }
  .sum div span { font-size: 12px; color: #6b7280; }
  .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
  .ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
  .err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .tbl-wrap { overflow-x: auto; margin-top: 6px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 10px 8px; border-top: 1px solid #f0f0f0; vertical-align: middle; }
  th { font-size: 11px; color: #6b7280; text-transform: uppercase; border-top: 0; white-space: nowrap; }
  .pill { display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 99px; }
  .pill-aadhaar { background: #e8f5ee; color: #1a5c3a; }
  .pill-passport { background: #e0e7ff; color: #3730a3; }
  .pill-other { background: #f3f4f6; color: #374151; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 14px; align-items: end; }
  label { display: block; font-size: 11px; font-weight: 600; color: #4b5563; margin-bottom: 4px; }
  input, select { width: 100%; font: inherit; font-size: 13.5px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; }
  input:focus, select:focus { outline: none; border-color: #1a5c3a; box-shadow: 0 0 0 2px rgba(26,92,58,0.15); }
  .hint { font-size: 11.5px; color: #6b7280; margin-top: 3px; }
  .foreign-fields { display: none; margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e5e7eb; }
  .empty { text-align: center; padding: 24px 16px; color: #6b7280; font-size: 13px; }
  .notice { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; font-size: 12px; color: #475569; line-height: 1.5; }
  .notice strong { color: #1e293b; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>🪪 Guest Documents · #<?= dh(waBookingNo($b)) ?></h1>
    <a class="btn" href="admin.php?section=bookings">← Bookings</a>
    <a class="btn" href="admin.php">Dashboard</a>
    <a class="btn" href="booking-payments.php?id=<?= (int)$b['id'] ?>">💰 Payments</a>
    <a class="btn" href="frontdesk.php">🛎️ Front Desk</a>
  </div>

  <?php if (isset($_GET['ok'])): ?><div class="flash ok">✓ <?= dh($_GET['ok']) ?></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="flash err"><?= dh($_GET['err']) ?></div><?php endif; ?>

  <!-- Booking summary -->
  <div class="card">
    <p style="margin:0 0 10px">
      <strong><?= dh($b['guest_name']) ?></strong> · <?= dh($b['room_name']) ?> · <?= dh(waDateRange($b['check_in'], $b['check_out'])) ?> · <?= dh(waSourceLabel($b['source'])) ?> · <?= dh(ucfirst($b['status'])) ?>
      <?php if (!empty($b['guest_phone'])): ?> · 📞 <?= dh($b['guest_phone']) ?><?php endif; ?>
    </p>
    <div class="sum">
      <div>
        <b><?= count($docs) ?></b>
        <span>Document<?= count($docs) === 1 ? '' : 's' ?> on file</span>
      </div>
      <div>
        <b><?= $hasAadhaar ? '<span style="color:#1a5c3a">✓ Uploaded</span>' : '<span style="color:#d97706">Pending</span>' ?></b>
        <span>Aadhaar status</span>
      </div>
      <div>
        <b><?= dh(ucfirst(fdStayStatus($b))) ?></b>
        <span>Stay status</span>
      </div>
    </div>
  </div>

  <!-- Upload ID / Aadhaar form -->
  <div class="card">
    <h2>Upload Guest ID / Aadhaar</h2>
    <div class="sub-note">Upload guest photo ID (Aadhaar, Passport, DL, etc.). For Aadhaar, UIDAI compliance rules apply: full numbers are automatically masked to <code>XXXX-XXXX-last4</code> and files are stored privately.</div>
    <form method="POST" enctype="multipart/form-data" id="docForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">

      <div class="grid">
        <div>
          <label for="docName">Guest Full Name *</label>
          <input type="text" name="name" id="docName" value="<?= dh($b['guest_name']) ?>" required maxlength="120">
        </div>
        <div>
          <label for="docType">ID Type *</label>
          <select name="id_type" id="docType" required>
            <?php foreach (FD_ID_TYPES as $k => $label): ?>
            <option value="<?= dh($k) ?>" <?= $k === 'aadhaar' ? 'selected' : '' ?>><?= dh($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="docNumber">ID Number *</label>
          <input type="text" name="id_number" id="docNumber" placeholder="e.g. 9012 or 1234 5678 9012" required autocomplete="off">
          <div class="hint">For Aadhaar: last 4 digits or full number</div>
        </div>
        <div>
          <label for="docPhone">Guest Phone</label>
          <input type="tel" name="phone" id="docPhone" value="<?= dh($b['guest_phone']) ?>" placeholder="Optional phone number">
        </div>
        <div>
          <label for="docNat">Nationality</label>
          <input type="text" name="nationality" id="docNat" value="Indian">
        </div>
        <div>
          <label for="docFile">Document Photo / PDF (max 5 MB) *</label>
          <input type="file" name="id_photo" id="docFile" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment" required>
          <div class="hint">JPG, PNG, WEBP, or PDF</div>
        </div>
      </div>

      <!-- Additional foreign national fields (Form C) -->
      <div class="foreign-fields" id="foreignFields">
        <div class="grid">
          <div><label>Passport Number *</label><input type="text" name="passport_no" id="passportNo"></div>
          <div><label>Passport Expiry</label><input type="date" name="passport_expiry"></div>
          <div><label>Visa Number</label><input type="text" name="visa_no"></div>
          <div><label>Visa Type</label><input type="text" name="visa_type" placeholder="e.g. Tourist, e-Visa"></div>
          <div><label>Visa Expiry</label><input type="date" name="visa_expiry"></div>
          <div><label>Arrived in India on</label><input type="date" name="arrived_india_on"></div>
          <div><label>Coming From</label><input type="text" name="coming_from"></div>
          <div><label>Next Destination</label><input type="text" name="next_destination"></div>
        </div>
      </div>

      <div style="margin-top:16px">
        <button type="submit" class="btn btn-primary" id="submitBtn">📤 Upload Document</button>
      </div>
    </form>
  </div>

  <!-- Uploaded documents list -->
  <div class="card">
    <h2>Uploaded Documents (<?= count($docs) ?>)</h2>
    <?php if (empty($docs)): ?>
      <div class="empty">
        📭 No ID documents attached to this booking yet.<br>
        Use the form above to upload an Aadhaar card or government photo ID.
      </div>
    <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>Guest</th>
              <th>Type</th>
              <th>ID Number</th>
              <th>Nationality</th>
              <th>File</th>
              <th>Recorded At</th>
              <th style="text-align:right">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($docs as $d): ?>
            <tr>
              <td>
                <strong><?= dh($d['name']) ?></strong>
                <?php if (!empty($d['phone'])): ?><div style="font-size:12px;color:#6b7280"><?= dh($d['phone']) ?></div><?php endif; ?>
              </td>
              <td>
                <span class="pill <?= $d['id_type'] === 'aadhaar' ? 'pill-aadhaar' : ($d['id_type'] === 'passport' ? 'pill-passport' : 'pill-other') ?>">
                  <?= dh(FD_ID_TYPES[$d['id_type']] ?? $d['id_type']) ?>
                </span>
              </td>
              <td style="font-family:monospace;font-size:12.5px">
                <?= dh($d['id_number']) ?>
                <?php if (!empty($d['is_foreign']) && !empty($d['passport_no'])): ?>
                  <div style="font-size:11px;color:#6b7280">Passport: <?= dh($d['passport_no']) ?></div>
                <?php endif; ?>
              </td>
              <td><?= dh($d['nationality']) ?></td>
              <td>
                <?php if (!empty($d['id_photo'])): ?>
                  <a class="btn btn-sm" href="guest-id-file.php?f=<?= rawurlencode($d['id_photo']) ?>" target="_blank" rel="noopener">👁️ View Document</a>
                <?php else: ?>
                  <span style="color:#9ca3af;font-size:12px">No file</span>
                <?php endif; ?>
              </td>
              <td style="font-size:12px;color:#6b7280;white-space:nowrap">
                <?= dh($d['created_at'] ?? '—') ?>
              </td>
              <td style="text-align:right">
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this ID record for <?= dh(addslashes($d['name'])) ?>?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <input type="hidden" name="guest_id" value="<?= (int)$d['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger" title="Delete document">🗑️ Delete</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Compliance Note -->
  <div class="notice">
    <strong>🔒 UIDAI &amp; Data Protection Compliance</strong><br>
    UIDAI rules prohibit keeping full 12-digit Aadhaar numbers in databases. The PMS automatically masks the number, storing only the last four digits (<code>XXXX-XXXX-last4</code>). Document images/PDFs are stored in a private directory outside the web root and are accessible only to authenticated staff through <code>guest-id-file.php</code>.
  </div>
</div>

<script>
(function() {
  var natInput = document.getElementById('docNat');
  var foreignBox = document.getElementById('foreignFields');
  var passNo = document.getElementById('passportNo');
  var typeSelect = document.getElementById('docType');
  var numInput = document.getElementById('docNumber');

  function checkNat() {
    var v = (natInput.value || '').trim().toLowerCase();
    var isForeign = v !== '' && ['india', 'indian', 'in', 'ind'].indexOf(v) === -1;
    foreignBox.style.display = isForeign ? 'block' : 'none';
    if (isForeign && passNo) {
      passNo.required = (typeSelect.value !== 'passport');
    } else if (passNo) {
      passNo.required = false;
    }
  }

  function checkType() {
    if (typeSelect.value === 'aadhaar') {
      numInput.placeholder = 'e.g. 9012 or 1234 5678 9012';
    } else {
      numInput.placeholder = 'ID number';
    }
    checkNat();
  }

  natInput.addEventListener('input', checkNat);
  typeSelect.addEventListener('change', checkType);
  checkType();

  // Validate file size on change
  var fileInput = document.getElementById('docFile');
  if (fileInput) {
    fileInput.addEventListener('change', function() {
      if (this.files && this.files[0] && this.files[0].size > 5 * 1024 * 1024) {
        alert('The selected file is larger than 5 MB. Please choose a smaller photo or compress it.');
        this.value = '';
      }
    });
  }
})();
</script>
</body>
</html>
