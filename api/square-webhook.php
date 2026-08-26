<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function config(): array {
    $cfg = [
        'access_token' => trim((string)(getenv('SQUARE_ACCESS_TOKEN') ?: '')),
        'webhook_signature_key' => trim((string)(getenv('SQUARE_WEBHOOK_SIGNATURE_KEY') ?: '')),
        'webhook_url' => trim((string)(getenv('SQUARE_WEBHOOK_URL') ?: 'https://faveside.com/api/square-webhook.php')),
        'api_base' => trim((string)(getenv('SQUARE_API_BASE') ?: 'https://connect.squareup.com')),
    ];
    $private = dirname(__DIR__) . '/data/square-config.php';
    if (is_file($private)) {
        $loaded = require $private;
        if (is_array($loaded)) $cfg = array_merge($cfg, $loaded);
    }
    return $cfg;
}

function square_get(string $path, array $cfg): array {
    $token = trim((string)($cfg['access_token'] ?? ''));
    if ($token === '') throw new RuntimeException('Square access token missing.');
    $base = rtrim((string)($cfg['api_base'] ?? 'https://connect.squareup.com'), '/');
    $url = $base . $path;
    $headers = [
        'Authorization: Bearer ' . $token,
        'Square-Version: 2026-08-19',
        'Accept: application/json',
    ];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException('Square could not be reached.');
    } else {
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 15, 'ignore_errors' => true, 'header' => implode("\r\n", $headers) . "\r\n"]]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) throw new RuntimeException('Square could not be reached.');
        $status = 200;
        foreach (($http_response_header ?? []) as $line) if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) $status = (int)$m[1];
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || $status >= 400) throw new RuntimeException('Square customer lookup failed.');
    return $data;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok' => false]);
$cfg = config();
$key = trim((string)($cfg['webhook_signature_key'] ?? ''));
$url = trim((string)($cfg['webhook_url'] ?? ''));
if ($key === '' || $url === '') respond(503, ['ok' => false, 'error' => 'Webhook is not configured.']);

$raw = file_get_contents('php://input');
if (!is_string($raw)) respond(400, ['ok' => false]);
$signature = (string)($_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? '');
$expected = base64_encode(hash_hmac('sha256', $url . $raw, $key, true));
if ($signature === '' || !hash_equals($expected, $signature)) respond(403, ['ok' => false]);

$event = json_decode($raw, true);
if (!is_array($event)) respond(400, ['ok' => false]);
$eventId = trim((string)($event['event_id'] ?? ''));
$type = trim((string)($event['type'] ?? ''));
if (!in_array($type, ['subscription.created', 'subscription.updated'], true)) respond(200, ['ok' => true, 'ignored' => true]);
$subscription = $event['data']['object']['subscription'] ?? null;
if (!is_array($subscription)) respond(200, ['ok' => true, 'ignored' => true]);
$customerId = trim((string)($subscription['customer_id'] ?? ''));
$status = strtoupper(trim((string)($subscription['status'] ?? '')));
if ($customerId === '') respond(200, ['ok' => true, 'ignored' => true]);

try {
    $customerResult = square_get('/v2/customers/' . rawurlencode($customerId), $cfg);
    $email = strtolower(trim((string)($customerResult['customer']['email_address'] ?? '')));
    if ($email === '') respond(200, ['ok' => true, 'ignored' => true]);

    $dataDir = dirname(__DIR__) . '/data';
    $db = new PDO('sqlite:' . $dataDir . '/faveside.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE IF NOT EXISTS square_webhook_events (event_id TEXT PRIMARY KEY, received_at TEXT NOT NULL)');
    if ($eventId !== '') {
        $seen = $db->prepare('SELECT event_id FROM square_webhook_events WHERE event_id=?');
        $seen->execute([$eventId]);
        if ($seen->fetch()) respond(200, ['ok' => true, 'duplicate' => true]);
    }

    $entitlement = match ($status) {
        'ACTIVE' => 'premium',
        'CANCELED' => 'canceled',
        'PAUSED', 'DEACTIVATED' => 'past_due',
        default => null,
    };
    if ($entitlement !== null) {
        $stmt = $db->prepare('UPDATE users SET entitlement=?, updated_at=? WHERE lower(email)=?');
        $stmt->execute([$entitlement, gmdate('c'), $email]);
    }
    if ($eventId !== '') {
        $insert = $db->prepare('INSERT OR IGNORE INTO square_webhook_events(event_id,received_at) VALUES(?,?)');
        $insert->execute([$eventId, gmdate('c')]);
    }
    respond(200, ['ok' => true]);
} catch (Throwable $e) {
    respond(500, ['ok' => false]);
}
