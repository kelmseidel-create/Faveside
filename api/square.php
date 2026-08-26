<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('faveside_session');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) respond(400, ['ok' => false, 'error' => 'Invalid request.']);
    return $data;
}

function config(): array {
    $cfg = [
        'access_token' => trim((string)(getenv('SQUARE_ACCESS_TOKEN') ?: '')),
        'location_id' => trim((string)(getenv('SQUARE_LOCATION_ID') ?: '')),
        'monthly_plan_variation_id' => trim((string)(getenv('SQUARE_MONTHLY_PLAN_VARIATION_ID') ?: '')),
        'annual_plan_variation_id' => trim((string)(getenv('SQUARE_ANNUAL_PLAN_VARIATION_ID') ?: '')),
        'monthly_checkout_url' => trim((string)(getenv('SQUARE_MONTHLY_CHECKOUT_URL') ?: '')),
        'annual_checkout_url' => trim((string)(getenv('SQUARE_ANNUAL_CHECKOUT_URL') ?: '')),
        'api_base' => trim((string)(getenv('SQUARE_API_BASE') ?: 'https://connect.squareup.com')),
    ];
    $private = dirname(__DIR__) . '/data/square-config.php';
    if (is_file($private)) {
        $loaded = require $private;
        if (is_array($loaded)) $cfg = array_merge($cfg, $loaded);
    }
    return $cfg;
}

function square_request(string $method, string $path, ?array $payload, array $cfg): array {
    $token = trim((string)($cfg['access_token'] ?? ''));
    if ($token === '') respond(503, ['ok' => false, 'setup_required' => true, 'error' => 'Square checkout is not configured yet.']);
    $base = rtrim((string)($cfg['api_base'] ?? 'https://connect.squareup.com'), '/');
    $url = $base . $path;
    $rawPayload = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($payload !== null && $rawPayload === false) respond(500, ['ok' => false, 'error' => 'Could not prepare checkout.']);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Square-Version: 2026-08-19',
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $rawPayload);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) respond(502, ['ok' => false, 'error' => $error ?: 'Square could not be reached.']);
    } else {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $payload === null ? '' : $rawPayload,
        ]]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) respond(502, ['ok' => false, 'error' => 'Square could not be reached.']);
        $status = 200;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) $status = (int)$m[1];
        }
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) respond(502, ['ok' => false, 'error' => 'Square returned an unreadable response.']);
    if ($status >= 400) {
        $message = $data['errors'][0]['detail'] ?? $data['errors'][0]['code'] ?? 'Square checkout could not be created.';
        respond($status, ['ok' => false, 'error' => $message]);
    }
    return $data;
}

if (empty($_SESSION['user_id'])) respond(401, ['ok' => false, 'error' => 'Please sign in first.']);
$userId = (int)$_SESSION['user_id'];
$dataDir = dirname(__DIR__) . '/data';

try {
    $db = new PDO('sqlite:' . $dataDir . '/faveside.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $stmt = $db->prepare('SELECT id,email,display_name,entitlement FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) respond(404, ['ok' => false, 'error' => 'Account not found.']);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'error' => 'Account storage is unavailable.']);
}

$cfg = config();
$action = $_GET['action'] ?? 'status';

if ($action === 'status') {
    $monthly = !empty($cfg['monthly_checkout_url']) || (!empty($cfg['access_token']) && !empty($cfg['location_id']) && !empty($cfg['monthly_plan_variation_id']));
    $annual = !empty($cfg['annual_checkout_url']) || (!empty($cfg['access_token']) && !empty($cfg['location_id']) && !empty($cfg['annual_plan_variation_id']));
    respond(200, ['ok' => true, 'configured' => $monthly || $annual, 'monthly' => $monthly, 'annual' => $annual]);
}

if ($action === 'checkout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    $input = body();
    $plan = strtolower(trim((string)($input['plan'] ?? 'monthly')));
    if (!in_array($plan, ['monthly', 'annual'], true)) respond(422, ['ok' => false, 'error' => 'Choose a valid plan.']);

    $directUrl = trim((string)($cfg[$plan . '_checkout_url'] ?? ''));
    if ($directUrl !== '') respond(200, ['ok' => true, 'url' => $directUrl]);

    $location = trim((string)($cfg['location_id'] ?? ''));
    $variation = trim((string)($cfg[$plan . '_plan_variation_id'] ?? ''));
    if ($location === '' || $variation === '') respond(503, ['ok' => false, 'setup_required' => true, 'error' => 'Square checkout is ready in Faveside, but the Square plan IDs still need to be connected.']);

    $amount = $plan === 'annual' ? 3999 : 499;
    $name = $plan === 'annual' ? 'Faveside+ Annual' : 'Faveside+ Monthly';
    $request = [
        'idempotency_key' => bin2hex(random_bytes(16)),
        'description' => 'Faveside+ ' . $plan . ' subscription for user ' . $userId,
        'quick_pay' => [
            'name' => $name,
            'price_money' => ['amount' => $amount, 'currency' => 'USD'],
            'location_id' => $location,
            'subscription_plan_id' => $variation,
        ],
        'pre_populated_data' => ['buyer_email' => (string)$user['email']],
        'checkout_options' => [
            'redirect_url' => 'https://faveside.com/account.php?checkout=success',
            'ask_for_shipping_address' => false,
        ],
    ];
    $result = square_request('POST', '/v2/online-checkout/payment-links', $request, $cfg);
    $url = (string)($result['payment_link']['url'] ?? '');
    if ($url === '') respond(502, ['ok' => false, 'error' => 'Square did not return a checkout link.']);
    respond(200, ['ok' => true, 'url' => $url]);
}

respond(404, ['ok' => false, 'error' => 'Unknown Square action.']);
