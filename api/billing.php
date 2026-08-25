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
function require_user(): int {
    if (empty($_SESSION['user_id'])) respond(401, ['ok' => false, 'error' => 'Please sign in first.']);
    return (int)$_SESSION['user_id'];
}

$dataDir = dirname(__DIR__) . '/data';
if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
    respond(500, ['ok' => false, 'error' => 'Billing storage is unavailable.']);
}

try {
    $db = new PDO('sqlite:' . $dataDir . '/faveside.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('CREATE TABLE IF NOT EXISTS promotions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code_hash TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL,
        entitlement TEXT NOT NULL DEFAULT "complimentary",
        max_redemptions INTEGER,
        redemption_count INTEGER NOT NULL DEFAULT 0,
        expires_at TEXT,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS promotion_redemptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        promotion_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        redeemed_at TEXT NOT NULL,
        UNIQUE(promotion_id, user_id),
        FOREIGN KEY(promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )');

    // Family & Friends launch code. Only the SHA-256 hash is stored in source.
    $seed = $db->prepare('INSERT OR IGNORE INTO promotions(code_hash,label,entitlement,max_redemptions,redemption_count,expires_at,active,created_at) VALUES(?,?,?,?,0,NULL,1,?)');
    $seed->execute([
        '35cace7996a66fe8e4340d074c7987e9ce5e3828df28314cfb7f6ec5f0324736',
        'Family & Friends',
        'complimentary',
        20,
        gmdate('c')
    ]);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'error' => 'Billing storage is unavailable on this server.']);
}

$action = $_GET['action'] ?? 'status';
$userId = require_user();

if ($action === 'status') {
    $stmt = $db->prepare('SELECT entitlement FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) respond(404, ['ok' => false, 'error' => 'Account not found.']);
    respond(200, ['ok' => true, 'entitlement' => $user['entitlement']]);
}

if ($action === 'redeem') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    $input = body();
    $code = strtoupper(trim((string)($input['code'] ?? '')));
    if ($code === '' || strlen($code) > 80) respond(422, ['ok' => false, 'error' => 'Enter a valid code.']);

    $hash = hash('sha256', $code);
    $stmt = $db->prepare('SELECT * FROM promotions WHERE code_hash=? LIMIT 1');
    $stmt->execute([$hash]);
    $promo = $stmt->fetch();
    if (!$promo || !(int)$promo['active']) respond(404, ['ok' => false, 'error' => 'That code is not valid.']);
    if (!empty($promo['expires_at']) && strtotime($promo['expires_at']) < time()) respond(410, ['ok' => false, 'error' => 'That code has expired.']);
    if ($promo['max_redemptions'] !== null && (int)$promo['redemption_count'] >= (int)$promo['max_redemptions']) respond(410, ['ok' => false, 'error' => 'That code has reached its redemption limit.']);

    try {
        $db->beginTransaction();
        $existing = $db->prepare('SELECT id FROM promotion_redemptions WHERE promotion_id=? AND user_id=?');
        $existing->execute([(int)$promo['id'], $userId]);
        if (!$existing->fetch()) {
            $now = gmdate('c');
            $insert = $db->prepare('INSERT INTO promotion_redemptions(promotion_id,user_id,redeemed_at) VALUES(?,?,?)');
            $insert->execute([(int)$promo['id'], $userId, $now]);
            $count = $db->prepare('UPDATE promotions SET redemption_count=redemption_count+1 WHERE id=?');
            $count->execute([(int)$promo['id']]);
        }
        $grant = $db->prepare('UPDATE users SET entitlement=?, updated_at=? WHERE id=?');
        $grant->execute([$promo['entitlement'], gmdate('c'), $userId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        respond(500, ['ok' => false, 'error' => 'We could not apply that code. Please try again.']);
    }

    respond(200, [
        'ok' => true,
        'entitlement' => $promo['entitlement'],
        'promotion' => $promo['label'],
        'message' => 'Premium access is now active on your account.'
    ]);
}

respond(404, ['ok' => false, 'error' => 'Unknown billing action.']);
