<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok' => false, 'message' => 'Method not allowed.']);

// Honeypot: bots commonly fill hidden form fields. Return success so they do not retry.
if (!empty($_POST['website'] ?? '')) respond(200, ['ok' => true, 'message' => "You're on the list!"]);

$email = strtolower(trim((string)($_POST['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    respond(422, ['ok' => false, 'message' => 'Please enter a valid email address.']);
}

$dataDir = __DIR__ . '/data';
$dataFile = $dataDir . '/launch-list.csv';
$rateFile = $dataDir . '/launch-rate.json';
if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true)) {
    respond(500, ['ok' => false, 'message' => 'We could not save your signup right now. Please try again.']);
}

// Privacy-conscious rate limiting: retain only a salted one-way IP hash.
$ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|faveside-launch-list-v2');
$now = time();
$window = 60 * 60;
$maxAttempts = 12;
$rateHandle = fopen($rateFile, 'c+');
if ($rateHandle && flock($rateHandle, LOCK_EX)) {
    $raw = stream_get_contents($rateHandle);
    $rates = json_decode($raw ?: '{}', true);
    if (!is_array($rates)) $rates = [];
    foreach ($rates as $key => $times) {
        if (!is_array($times)) { unset($rates[$key]); continue; }
        $rates[$key] = array_values(array_filter($times, fn($t) => is_int($t) && $t > $now - $window));
        if (!$rates[$key]) unset($rates[$key]);
    }
    $attempts = $rates[$ipHash] ?? [];
    if (count($attempts) >= $maxAttempts) {
        flock($rateHandle, LOCK_UN);
        fclose($rateHandle);
        respond(429, ['ok' => false, 'message' => 'Too many signup attempts. Please try again later.']);
    }
    $attempts[] = $now;
    $rates[$ipHash] = $attempts;
    rewind($rateHandle);
    ftruncate($rateHandle, 0);
    fwrite($rateHandle, json_encode($rates));
    fflush($rateHandle);
    flock($rateHandle, LOCK_UN);
    fclose($rateHandle);
}

$fp = fopen($dataFile, 'c+');
if (!$fp) respond(500, ['ok' => false, 'message' => 'We could not save your signup right now. Please try again.']);
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    respond(500, ['ok' => false, 'message' => 'We could not save your signup right now. Please try again.']);
}

$duplicate = false;
rewind($fp);
while (($row = fgetcsv($fp)) !== false) {
    if (isset($row[1]) && strtolower(trim((string)$row[1])) === $email) {
        $duplicate = true;
        break;
    }
}

if (!$duplicate) {
    fseek($fp, 0, SEEK_END);
    $timestamp = gmdate('c');
    $source = substr(trim((string)($_POST['source'] ?? 'homepage')), 0, 80) ?: 'homepage';
    $consent = 'launch_updates';
    fputcsv($fp, [$timestamp, $email, $ipHash, $source, $consent]);
    fflush($fp);
}

flock($fp, LOCK_UN);
fclose($fp);

respond(200, [
    'ok' => true,
    'duplicate' => $duplicate,
    'message' => $duplicate ? "You're already on the Faveside launch list!" : "You're in! We'll let you know when Faveside is ready."
]);
