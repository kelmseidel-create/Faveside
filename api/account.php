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

function normalize_email(string $email): string {
    return strtolower(trim($email));
}

function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

function require_user(): int {
    if (empty($_SESSION['user_id'])) respond(401, ['ok' => false, 'error' => 'Please sign in.']);
    return (int) $_SESSION['user_id'];
}

$dataDir = dirname(__DIR__) . '/data';
if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
    respond(500, ['ok' => false, 'error' => 'Account storage is unavailable.']);
}

try {
    $db = new PDO('sqlite:' . $dataDir . '/faveside.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        display_name TEXT NOT NULL DEFAULT "",
        entitlement TEXT NOT NULL DEFAULT "free",
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS user_state (
        user_id INTEGER PRIMARY KEY,
        state_json TEXT NOT NULL DEFAULT "{}",
        updated_at TEXT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )');
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'error' => 'Account storage is unavailable on this server.']);
}

$action = $_GET['action'] ?? 'me';

if ($action === 'register') {
    require_post();
    $input = body();
    $email = normalize_email((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $name = trim((string)($input['name'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(422, ['ok' => false, 'error' => 'Enter a valid email address.']);
    if (strlen($password) < 10) respond(422, ['ok' => false, 'error' => 'Use at least 10 characters for your password.']);
    if (mb_strlen($name) > 50) respond(422, ['ok' => false, 'error' => 'Name is too long.']);

    $now = gmdate('c');
    try {
        $stmt = $db->prepare('INSERT INTO users(email,password_hash,display_name,entitlement,created_at,updated_at) VALUES(?,?,?,?,?,?)');
        $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name, 'free', $now, $now]);
        $userId = (int)$db->lastInsertId();
        $stmt = $db->prepare('INSERT INTO user_state(user_id,state_json,updated_at) VALUES(?,?,?)');
        $stmt->execute([$userId, '{}', $now]);
    } catch (PDOException $e) {
        if (str_contains(strtolower($e->getMessage()), 'unique')) respond(409, ['ok' => false, 'error' => 'An account with that email already exists.']);
        throw $e;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    respond(201, ['ok' => true, 'user' => ['id' => $userId, 'email' => $email, 'name' => $name, 'entitlement' => 'free']]);
}

if ($action === 'login') {
    require_post();
    $input = body();
    $email = normalize_email((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $stmt = $db->prepare('SELECT id,email,password_hash,display_name,entitlement FROM users WHERE email=? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) respond(401, ['ok' => false, 'error' => 'Email or password is incorrect.']);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    respond(200, ['ok' => true, 'user' => ['id' => (int)$user['id'], 'email' => $user['email'], 'name' => $user['display_name'], 'entitlement' => $user['entitlement']]]);
}

if ($action === 'logout') {
    require_post();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
    respond(200, ['ok' => true]);
}

if ($action === 'me') {
    if (empty($_SESSION['user_id'])) respond(200, ['ok' => true, 'user' => null]);
    $stmt = $db->prepare('SELECT id,email,display_name,entitlement FROM users WHERE id=? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) { $_SESSION = []; respond(200, ['ok' => true, 'user' => null]); }
    respond(200, ['ok' => true, 'user' => ['id' => (int)$user['id'], 'email' => $user['email'], 'name' => $user['display_name'], 'entitlement' => $user['entitlement']]]);
}

if ($action === 'state') {
    $userId = require_user();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare('SELECT state_json,updated_at FROM user_state WHERE user_id=?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $state = $row ? json_decode($row['state_json'], true) : [];
        respond(200, ['ok' => true, 'state' => is_array($state) ? $state : [], 'updated_at' => $row['updated_at'] ?? null]);
    }
    require_post();
    $input = body();
    $state = $input['state'] ?? null;
    if (!is_array($state)) respond(422, ['ok' => false, 'error' => 'State must be an object.']);
    $encoded = json_encode($state, JSON_UNESCAPED_SLASHES);
    if ($encoded === false || strlen($encoded) > 250000) respond(413, ['ok' => false, 'error' => 'Account data is too large.']);
    $now = gmdate('c');
    $stmt = $db->prepare('INSERT INTO user_state(user_id,state_json,updated_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET state_json=excluded.state_json, updated_at=excluded.updated_at');
    $stmt->execute([$userId, $encoded, $now]);
    respond(200, ['ok' => true, 'updated_at' => $now]);
}

respond(404, ['ok' => false, 'error' => 'Unknown account action.']);
