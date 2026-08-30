<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime'=>0, 'path'=>'/', 'secure'=>str_starts_with(SITE_URL, 'https://'),
        'httponly'=>true, 'samesite'=>'Strict',
    ]);
    session_start();
}

function verifyAdminPassword(string $password): bool
{
    return ADMIN_PASSWORD_HASH !== '' && password_verify($password, ADMIN_PASSWORD_HASH);
}

function isValidRoomId(string $roomId): bool
{
    return isset(ROOM_IDS[$roomId]);
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(?string $token): bool
{
    $stored = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && $token !== '' && is_string($stored) && $stored !== '' && hash_equals($stored, $token);
}

function requireValidCsrfToken(?string $token): void
{
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        exit('Invalid request token.');
    }
}

function isPublicIp(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function isSafeCalendarUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) return false;
    if (isset($parts['user']) || isset($parts['pass'])) return false;
    if (isset($parts['port']) && (int)$parts['port'] !== 443) return false;

    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) return isPublicIp($host);

    $resolved = gethostbynamel($host);
    if ($resolved === false || $resolved === []) return false;
    foreach ($resolved as $ip) {
        if (!isPublicIp($ip)) return false;
    }
    return true;
}

