<?php
declare(strict_types=1);

function sendJsonHeaders(string $methods = 'GET, POST, OPTIONS'): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . SITE_URL);
    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: Content-Type');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

function readJsonRequest(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($decoded)) throw new InvalidArgumentException('Invalid JSON request.');
    return $decoded;
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function paymentIsConfigured(): bool
{
    return RAZORPAY_KEY_ID !== '' && RAZORPAY_KEY_SECRET !== '';
}
