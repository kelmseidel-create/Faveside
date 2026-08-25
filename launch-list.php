<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Simple honeypot: bots often fill hidden fields.
if (!empty($_POST['website'] ?? '')) {
    echo json_encode(['ok' => true, 'message' => "You're on the list!"]);
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

$dataDir = __DIR__ . '/data';
$dataFile = $dataDir . '/launch-list.csv';

if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'We could not save your signup right now. Please try again.']);
    exit;
}

$fp = fopen($dataFile, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'We could not save your signup right now. Please try again.']);
    exit;
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'We could not save your signup right now. Please try again.']);
    exit;
}

$duplicate = false;
rewind($fp);
while (($row = fgetcsv($fp)) !== false) {
    if (isset($row[1]) && strtolower(trim($row[1])) === $email) {
        $duplicate = true;
        break;
    }
}

if (!$duplicate) {
    fseek($fp, 0, SEEK_END);
    $timestamp = gmdate('c');
    $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|faveside-launch-list');
    fputcsv($fp, [$timestamp, $email, $ipHash]);
    fflush($fp);
}

flock($fp, LOCK_UN);
fclose($fp);

echo json_encode([
    'ok' => true,
    'duplicate' => $duplicate,
    'message' => $duplicate ? "You're already on the Faveside launch list!" : "You're in! We'll let you know when Faveside is ready."
]);
