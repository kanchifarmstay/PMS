<?php
/**
 * Daily backups of the one SQLite file that holds every booking, bill,
 * payment and WhatsApp log.
 *
 * VACUUM INTO writes a consistent snapshot of the live database (SQLite 3.27+;
 * Hostinger runs 3.50), so a backup taken while a guest is paying is still a
 * valid database. Every copy is opened and integrity-checked before it counts;
 * a bad copy is deleted rather than kept looking like a backup. The newest
 * KFS_BACKUP_KEEP copies are kept.
 *
 * The copies live next to the database, outside the web root. Off-site copies
 * come from the "Download" button on backups.php and from the VPS pulling this
 * directory nightly.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

const KFS_BACKUP_KEEP = 30;
const KFS_BACKUP_PATTERN = '/^calendar-(\d{8})-(\d{6})\.db$/';

function kfsBackupDir(): string {
    $dir = dirname(DB_PATH) . '/backups';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/** @return list<array{name:string,path:string,size:int,time:int}> newest first */
function listBackups(): array {
    $out = [];
    foreach (glob(kfsBackupDir() . '/calendar-*.db') ?: [] as $path) {
        $name = basename($path);
        if (!preg_match(KFS_BACKUP_PATTERN, $name, $m)) continue;
        $time = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2], new DateTimeZone(PROPERTY_TIMEZONE));
        $out[] = ['name' => $name, 'path' => $path, 'size' => (int)filesize($path), 'time' => $time ? $time->getTimestamp() : (int)filemtime($path)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/** A backup file name the download page may serve: exact pattern, inside the backup dir, no path tricks. */
function backupPathFor(string $name): ?string {
    if (!preg_match(KFS_BACKUP_PATTERN, $name)) return null;
    $path = kfsBackupDir() . '/' . $name;
    return is_file($path) ? $path : null;
}

/**
 * Take a backup now.
 * @return array{ok:bool, name?:string, size?:int, bookings?:int, error?:string}
 */
function createBackup(?int $now = null): array {
    $now ??= time();
    $dir = kfsBackupDir();
    if (!is_dir($dir) || !is_writable($dir)) return ['ok' => false, 'error' => 'Backup folder is not writable: ' . $dir];
    $name = 'calendar-' . date('Ymd-His', $now) . '.db';
    $final = $dir . '/' . $name;
    $tmp = $final . '.part';
    @unlink($tmp);
    try {
        getDB()->exec('VACUUM INTO ' . getDB()->quote($tmp));
        $copy = new PDO('sqlite:' . $tmp);
        $copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $check = (string)$copy->query('PRAGMA integrity_check')->fetchColumn();
        $bookings = (int)$copy->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
        $copy = null;
        if ($check !== 'ok') {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Integrity check failed on the new copy: ' . $check];
        }
        if (!rename($tmp, $final)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Could not move the backup into place.'];
        }
        @chmod($final, 0600);
        pruneBackups();
        return ['ok' => true, 'name' => $name, 'size' => (int)filesize($final), 'bookings' => $bookings];
    } catch (Throwable $e) {
        @unlink($tmp);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pruneBackups(int $keep = KFS_BACKUP_KEEP): int {
    $removed = 0;
    foreach (array_slice(listBackups(), $keep) as $old) {
        if (@unlink($old['path'])) $removed++;
    }
    return $removed;
}

/** The cron entry point: one backup per calendar day (IST). Never throws. */
function runDailyBackup(?int $now = null): array {
    $now ??= time();
    try {
        $today = date('Ymd', $now);
        foreach (listBackups() as $b) {
            if (date('Ymd', $b['time']) === $today) return ['status' => 'already_done', 'name' => $b['name']];
        }
        $r = createBackup($now);
        if (!$r['ok']) error_log('Daily backup failed: ' . $r['error']);
        return ['status' => $r['ok'] ? 'created' : 'failed'] + $r;
    } catch (Throwable $e) {
        error_log('Daily backup crashed: ' . $e->getMessage());
        return ['status' => 'failed', 'error' => $e->getMessage()];
    }
}
