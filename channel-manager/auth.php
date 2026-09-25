<?php
/**
 * Staff logins, roles and the audit log.
 *
 * Roles
 *   owner      everything, including staff accounts
 *   manager    everything except staff accounts
 *   frontdesk  bookings (view/add/edit), Front Desk, bills, WhatsApp, taking
 *              payments - no deletes, refunds, voids, accounts, backups,
 *              pricing, channels, analytics or staff
 *
 * The KFS_ADMIN_PASSWORD_HASH login keeps working as a built-in owner
 * ("admin"), so adding staff can never lock the owner out, and a session that
 * was logged in before this existed carries on as that owner.
 *
 * Every page still checks $_SESSION['admin_logged_in'] first; the role check
 * is on top of it. kfsAudit() never throws.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

const KFS_ROLES = ['owner' => 'Owner', 'manager' => 'Manager', 'frontdesk' => 'Front desk'];
const KFS_PERMISSIONS = [
    'owner'     => ['*'],
    'manager'   => ['bookings.view', 'bookings.edit', 'bookings.delete', 'frontdesk', 'bills', 'whatsapp', 'payments.add',
                    'payments.refund', 'accounts', 'backups', 'audit', 'settings'],
    'frontdesk' => ['bookings.view', 'bookings.edit', 'frontdesk', 'bills', 'whatsapp', 'payments.add'],
];
const KFS_LOGIN_MAX_FAILURES = 5;
const KFS_LOGIN_WINDOW_MINUTES = 15;
const KFS_MIN_PASSWORD = 8;

/** The person logged in, or null. A pre-staff session is the built-in owner. */
function currentUser(): ?array {
    if (empty($_SESSION['admin_logged_in'])) return null;
    $u = $_SESSION['kfs_user'] ?? null;
    if (is_array($u) && isset($u['role'])) return $u;
    return ['id' => 0, 'name' => 'Owner', 'username' => 'admin', 'role' => 'owner'];
}

function userCan(string $permission, ?array $user = null): bool {
    $user ??= currentUser();
    if (!$user) return false;
    $perms = KFS_PERMISSIONS[$user['role']] ?? [];
    return in_array('*', $perms, true) || in_array($permission, $perms, true);
}

/** Stop the page unless the logged-in person may do this. */
function requirePermission(string $permission): array {
    $user = currentUser();
    if (!$user) { header('Location: admin.php'); exit; }
    if (!userCan($permission, $user)) {
        http_response_code(403);
        echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not allowed</title>'
           . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:15vh auto;padding:0 16px;text-align:center">'
           . '<h1 style="color:#1a5c3a;font-size:22px">Not allowed</h1><p>Your role (' . htmlspecialchars(KFS_ROLES[$user['role']] ?? $user['role']) . ') cannot open this page. Ask the owner if you need it.</p>'
           . '<p><a href="admin.php" style="color:#1a5c3a;font-weight:600">← Back to the dashboard</a></p></div>';
        exit;
    }
    return $user;
}

function kfsClientIp(): string {
    return mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Record who did what. Never throws. */
function kfsAudit(string $action, string $entity = '', int|string|null $entityId = null, string $detail = '', ?array $user = null): void {
    try {
        $user ??= currentUser();
        getDB()->prepare("INSERT INTO audit_log (user_id, username, action, entity, entity_id, detail, ip) VALUES (?,?,?,?,?,?,?)")
            ->execute([$user['id'] ?? null, $user['username'] ?? '', mb_substr($action, 0, 60), mb_substr($entity, 0, 40),
                $entityId === null ? null : (string)$entityId, mb_substr($detail, 0, 500), kfsClientIp()]);
    } catch (Throwable $e) {
        error_log('Audit log write failed: ' . $e->getMessage());
    }
}

function kfsRecentFailures(string $username): int {
    $q = getDB()->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'login_failed' AND username = ?
        AND created_at >= datetime('now', ?)");
    $q->execute([strtolower($username), '-' . KFS_LOGIN_WINDOW_MINUTES . ' minutes']);
    return (int)$q->fetchColumn();
}

/**
 * Check a username + password. Returns the user array, or a string explaining
 * why not. Writes login / login_failed / login_locked to the audit log.
 */
function kfsAttemptLogin(string $username, string $password): array|string {
    $username = strtolower(trim($username));
    if ($username === '') $username = 'admin';
    $guest = ['id' => null, 'username' => $username];
    if (kfsRecentFailures($username) >= KFS_LOGIN_MAX_FAILURES) {
        kfsAudit('login_locked', 'user', null, 'Too many failed attempts', $guest);
        return 'Too many wrong passwords. Try again in ' . KFS_LOGIN_WINDOW_MINUTES . ' minutes.';
    }
    $user = null;
    if ($username === 'admin' && verifyAdminPassword($password)) {
        $user = ['id' => 0, 'name' => 'Owner', 'username' => 'admin', 'role' => 'owner'];
    } else {
        $q = getDB()->prepare('SELECT * FROM users WHERE username = ?');
        $q->execute([$username]);
        $row = $q->fetch();
        if ($row && (int)$row['active'] === 1 && password_verify($password, $row['password_hash'])) {
            $user = ['id' => (int)$row['id'], 'name' => $row['name'], 'username' => $row['username'], 'role' => $row['role']];
            getDB()->prepare("UPDATE users SET last_login_at = datetime('now') WHERE id = ?")->execute([$row['id']]);
        }
    }
    if ($user === null) {
        kfsAudit('login_failed', 'user', null, '', $guest);
        return 'Incorrect username or password.';
    }
    kfsAudit('login', 'user', $user['id'], '', $user);
    return $user;
}

function kfsStartUserSession(array $user): void {
    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['kfs_user'] = $user;
}

// ── Staff accounts ───────────────────────────────────────────

function listUsers(): array {
    return getDB()->query('SELECT id, name, username, role, active, created_at, last_login_at FROM users ORDER BY active DESC, name')->fetchAll();
}

function validatePassword(string $password): void {
    if (mb_strlen($password) < KFS_MIN_PASSWORD) throw new InvalidArgumentException('The password must be at least ' . KFS_MIN_PASSWORD . ' characters.');
}

function createUser(string $name, string $username, string $role, string $password): int {
    $name = trim($name);
    $username = strtolower(trim($username));
    if ($name === '') throw new InvalidArgumentException('Enter the person\'s name.');
    if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) throw new InvalidArgumentException('Username: 3-30 letters, digits, dot, dash or underscore.');
    if ($username === 'admin') throw new InvalidArgumentException('"admin" is reserved for the owner login.');
    if (!isset(KFS_ROLES[$role])) throw new InvalidArgumentException('Choose a role.');
    validatePassword($password);
    try {
        getDB()->prepare('INSERT INTO users (name, username, role, password_hash) VALUES (?,?,?,?)')
            ->execute([mb_substr($name, 0, 80), $username, $role, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        throw new InvalidArgumentException('That username is already taken.');
    }
    return (int)getDB()->lastInsertId();
}

function updateUser(int $id, string $role, bool $active): void {
    if (!isset(KFS_ROLES[$role])) throw new InvalidArgumentException('Choose a role.');
    getDB()->prepare('UPDATE users SET role = ?, active = ? WHERE id = ?')->execute([$role, $active ? 1 : 0, $id]);
}

function setUserPassword(int $id, string $password): void {
    validatePassword($password);
    getDB()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
}

/** Change your own password. The built-in owner changes theirs in kfs.env, not here. */
function changeOwnPassword(array $user, string $current, string $new): void {
    if ((int)$user['id'] === 0) throw new InvalidArgumentException('The built-in owner password is set in kfs.env (KFS_ADMIN_PASSWORD_HASH).');
    $q = getDB()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $q->execute([(int)$user['id']]);
    $hash = (string)$q->fetchColumn();
    if ($hash === '' || !password_verify($current, $hash)) throw new InvalidArgumentException('Your current password is not right.');
    setUserPassword((int)$user['id'], $new);
}

function auditRows(array $f, int $limit = 200): array {
    $where = [];
    $args = [];
    if (($f['user'] ?? '') !== '') { $where[] = 'username = ?'; $args[] = $f['user']; }
    if (($f['action'] ?? '') !== '') { $where[] = 'action = ?'; $args[] = $f['action']; }
    if (($f['q'] ?? '') !== '') { $where[] = '(detail LIKE ? OR entity_id = ?)'; $args[] = '%' . $f['q'] . '%'; $args[] = ltrim(preg_replace('/\D/', '', $f['q']), '0'); }
    $q = getDB()->prepare('SELECT * FROM audit_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . (int)$limit);
    $q->execute($args);
    return $q->fetchAll();
}
