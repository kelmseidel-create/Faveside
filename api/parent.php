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
function clean_name(string $name): string {
    return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
}
function creator_key(array $creator): string {
    $handle = strtolower(trim((string)($creator['handle'] ?? '')));
    $name = strtolower(trim((string)($creator['name'] ?? '')));
    return $handle !== '' ? 'h:' . $handle : 'n:' . $name;
}

$dataDir = dirname(__DIR__) . '/data';
try {
    $db = new PDO('sqlite:' . $dataDir . '/faveside.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('CREATE TABLE IF NOT EXISTS child_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS child_creator_approvals (
        profile_id INTEGER NOT NULL,
        creator_key TEXT NOT NULL,
        approved_at TEXT NOT NULL,
        PRIMARY KEY(profile_id, creator_key),
        FOREIGN KEY(profile_id) REFERENCES child_profiles(id) ON DELETE CASCADE
    )');
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'error' => 'Parent Controls storage is unavailable.']);
}

$userId = require_user();
$stmt = $db->prepare('SELECT entitlement FROM users WHERE id=? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) respond(404, ['ok' => false, 'error' => 'Account not found.']);
if (!in_array($user['entitlement'], ['premium', 'complimentary'], true)) {
    respond(403, ['ok' => false, 'error' => 'Parent Controls are a Faveside+ feature.', 'entitlement' => $user['entitlement']]);
}

function owned_profile(PDO $db, int $profileId, int $userId): array {
    $stmt = $db->prepare('SELECT id,user_id,name,active,created_at,updated_at FROM child_profiles WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$profileId, $userId]);
    $profile = $stmt->fetch();
    if (!$profile) respond(404, ['ok' => false, 'error' => 'Child profile not found.']);
    return $profile;
}
function load_creators(PDO $db, int $userId): array {
    $stmt = $db->prepare('SELECT state_json FROM user_state WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $state = $row ? json_decode((string)$row['state_json'], true) : [];
    $creators = is_array($state) && isset($state['creators']) && is_array($state['creators']) ? $state['creators'] : [];
    $out = [];
    foreach ($creators as $creator) {
        if (!is_array($creator) || trim((string)($creator['name'] ?? '')) === '') continue;
        $creator['_key'] = creator_key($creator);
        $out[] = $creator;
    }
    return $out;
}

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $profiles = $db->prepare('SELECT id,name,active,created_at,updated_at FROM child_profiles WHERE user_id=? ORDER BY id ASC');
    $profiles->execute([$userId]);
    $rows = $profiles->fetchAll();
    foreach ($rows as &$profile) {
        $ap = $db->prepare('SELECT creator_key FROM child_creator_approvals WHERE profile_id=? ORDER BY creator_key');
        $ap->execute([(int)$profile['id']]);
        $profile['approved_keys'] = array_column($ap->fetchAll(), 'creator_key');
        $profile['active'] = (bool)$profile['active'];
    }
    respond(200, ['ok' => true, 'profiles' => $rows, 'creators' => load_creators($db, $userId)]);
}

if ($action === 'create') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    $input = body();
    $name = clean_name((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 30) respond(422, ['ok' => false, 'error' => 'Enter a first name up to 30 characters.']);
    $count = $db->prepare('SELECT COUNT(*) FROM child_profiles WHERE user_id=?');
    $count->execute([$userId]);
    if ((int)$count->fetchColumn() >= 8) respond(409, ['ok' => false, 'error' => 'This account already has the maximum of 8 child profiles.']);
    $now = gmdate('c');
    $insert = $db->prepare('INSERT INTO child_profiles(user_id,name,active,created_at,updated_at) VALUES(?,?,1,?,?)');
    $insert->execute([$userId, $name, $now, $now]);
    respond(201, ['ok' => true, 'profile' => ['id' => (int)$db->lastInsertId(), 'name' => $name, 'active' => true, 'approved_keys' => []]]);
}

if ($action === 'toggle') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    $input = body();
    $profileId = (int)($input['profile_id'] ?? 0);
    $profile = owned_profile($db, $profileId, $userId);
    $active = !empty($input['active']) ? 1 : 0;
    $stmt = $db->prepare('UPDATE child_profiles SET active=?,updated_at=? WHERE id=? AND user_id=?');
    $stmt->execute([$active, gmdate('c'), $profileId, $userId]);
    respond(200, ['ok' => true, 'active' => (bool)$active]);
}

if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    $input = body();
    $profileId = (int)($input['profile_id'] ?? 0);
    owned_profile($db, $profileId, $userId);
    $stmt = $db->prepare('DELETE FROM child_profiles WHERE id=? AND user_id=?');
    $stmt->execute([$profileId, $userId]);
    respond(200, ['ok' => true]);
}

if ($action === 'approve') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    $input = body();
    $profileId = (int)($input['profile_id'] ?? 0);
    $key = trim((string)($input['creator_key'] ?? ''));
    $approved = !empty($input['approved']);
    owned_profile($db, $profileId, $userId);
    $valid = false;
    foreach (load_creators($db, $userId) as $creator) {
        if (($creator['_key'] ?? '') === $key) { $valid = true; break; }
    }
    if (!$valid) respond(422, ['ok' => false, 'error' => 'That creator is not on the parent account.']);
    if ($approved) {
        $stmt = $db->prepare('INSERT OR IGNORE INTO child_creator_approvals(profile_id,creator_key,approved_at) VALUES(?,?,?)');
        $stmt->execute([$profileId, $key, gmdate('c')]);
    } else {
        $stmt = $db->prepare('DELETE FROM child_creator_approvals WHERE profile_id=? AND creator_key=?');
        $stmt->execute([$profileId, $key]);
    }
    respond(200, ['ok' => true, 'approved' => $approved]);
}

if ($action === 'child_feed') {
    $profileId = (int)($_GET['profile_id'] ?? 0);
    $profile = owned_profile($db, $profileId, $userId);
    if (!(bool)$profile['active']) respond(423, ['ok' => false, 'error' => 'This child profile is paused.']);
    $ap = $db->prepare('SELECT creator_key FROM child_creator_approvals WHERE profile_id=?');
    $ap->execute([$profileId]);
    $allowed = array_flip(array_column($ap->fetchAll(), 'creator_key'));
    $creators = array_values(array_filter(load_creators($db, $userId), fn($c) => isset($allowed[$c['_key'] ?? ''])));
    foreach ($creators as &$c) unset($c['_key']);
    respond(200, ['ok' => true, 'profile' => ['id' => (int)$profile['id'], 'name' => $profile['name']], 'creators' => $creators]);
}

respond(404, ['ok' => false, 'error' => 'Unknown Parent Controls action.']);
