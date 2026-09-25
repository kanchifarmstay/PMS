<?php
/**
 * Serves one stored guest ID photo to a logged-in admin. The file name must be
 * exactly one frontdesk-service.php wrote (booking id / 24 hex chars .ext), so
 * this cannot be pointed at any other file. Never cached.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/frontdesk-service.php';

startSecureSession();
if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); exit('Access denied.'); }
require_once __DIR__ . '/auth.php';
requirePermission('frontdesk');

$path = fdIdFilePath((string)($_GET['f'] ?? ''));
if ($path === null) { http_response_code(404); exit('Not found.'); }

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
header('Content-Type: ' . $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))]);
header('Content-Disposition: inline; filename="guest-id.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
readfile($path);
