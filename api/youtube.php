<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function youtube_key(): string {
    $key = getenv('YOUTUBE_API_KEY');
    if (is_string($key) && trim($key) !== '') return trim($key);

    $private = dirname(__DIR__) . '/data/youtube-key.php';
    if (is_file($private)) {
        $loaded = require $private;
        if (is_string($loaded) && trim($loaded) !== '') return trim($loaded);
    }
    return '';
}

function google_get(string $endpoint, array $params): array {
    $key = youtube_key();
    if ($key === '') respond(503, [
        'ok' => false,
        'setup_required' => true,
        'error' => 'YouTube is ready to connect, but the server API key has not been installed yet.'
    ]);

    $params['key'] = $key;
    $url = 'https://www.googleapis.com/youtube/v3/' . $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: Faveside/1.0\r\n",
        ]
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) respond(502, ['ok' => false, 'error' => 'YouTube could not be reached.']);

    $status = 200;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) $status = (int)$m[1];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) respond(502, ['ok' => false, 'error' => 'YouTube returned an unreadable response.']);
    if ($status >= 400) {
        $message = $data['error']['message'] ?? 'YouTube request failed.';
        respond($status, ['ok' => false, 'error' => $message]);
    }
    return $data;
}

$action = $_GET['action'] ?? 'status';

if ($action === 'status') {
    respond(200, ['ok' => true, 'configured' => youtube_key() !== '']);
}

if ($action === 'search') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) respond(422, ['ok' => false, 'error' => 'Enter at least 2 characters.']);
    if (mb_strlen($q) > 80) respond(422, ['ok' => false, 'error' => 'Search is too long.']);

    $search = google_get('search', [
        'part' => 'snippet',
        'type' => 'channel',
        'q' => $q,
        'maxResults' => 8,
        'safeSearch' => 'moderate',
    ]);

    $items = [];
    $channelIds = [];
    foreach (($search['items'] ?? []) as $item) {
        $channelId = (string)($item['id']['channelId'] ?? '');
        if ($channelId === '') continue;
        $channelIds[] = $channelId;
        $items[$channelId] = [
            'id' => $channelId,
            'name' => (string)($item['snippet']['channelTitle'] ?? $item['snippet']['title'] ?? 'YouTube creator'),
            'handle' => '',
            'category' => 'YouTube',
            'platform' => 'YouTube',
            'image' => (string)($item['snippet']['thumbnails']['high']['url'] ?? $item['snippet']['thumbnails']['default']['url'] ?? ''),
            'description' => (string)($item['snippet']['description'] ?? ''),
            'youtubeChannelId' => $channelId,
            'url' => 'https://www.youtube.com/channel/' . rawurlencode($channelId),
        ];
    }

    if ($channelIds) {
        $details = google_get('channels', [
            'part' => 'snippet,statistics',
            'id' => implode(',', $channelIds),
            'maxResults' => 50,
        ]);
        foreach (($details['items'] ?? []) as $channel) {
            $id = (string)($channel['id'] ?? '');
            if ($id === '' || !isset($items[$id])) continue;
            $snippet = $channel['snippet'] ?? [];
            $stats = $channel['statistics'] ?? [];
            $items[$id]['name'] = (string)($snippet['title'] ?? $items[$id]['name']);
            $items[$id]['handle'] = (string)($snippet['customUrl'] ?? '');
            $items[$id]['image'] = (string)($snippet['thumbnails']['high']['url'] ?? $items[$id]['image']);
            $items[$id]['subscriberCount'] = isset($stats['subscriberCount']) ? (int)$stats['subscriberCount'] : null;
            $items[$id]['videoCount'] = isset($stats['videoCount']) ? (int)$stats['videoCount'] : null;
            $items[$id]['viewCount'] = isset($stats['viewCount']) ? (int)$stats['viewCount'] : null;
        }
    }

    respond(200, ['ok' => true, 'results' => array_values($items)]);
}

if ($action === 'activity') {
    $channelId = trim((string)($_GET['channel_id'] ?? ''));
    if (!preg_match('/^UC[A-Za-z0-9_-]{20,30}$/', $channelId)) respond(422, ['ok' => false, 'error' => 'Invalid YouTube channel.']);

    $videos = google_get('search', [
        'part' => 'snippet',
        'type' => 'video',
        'channelId' => $channelId,
        'order' => 'date',
        'maxResults' => 5,
    ]);

    $out = [];
    foreach (($videos['items'] ?? []) as $item) {
        $videoId = (string)($item['id']['videoId'] ?? '');
        if ($videoId === '') continue;
        $out[] = [
            'videoId' => $videoId,
            'title' => (string)($item['snippet']['title'] ?? 'New video'),
            'publishedAt' => (string)($item['snippet']['publishedAt'] ?? ''),
            'thumbnail' => (string)($item['snippet']['thumbnails']['medium']['url'] ?? ''),
            'url' => 'https://www.youtube.com/watch?v=' . rawurlencode($videoId),
        ];
    }
    respond(200, ['ok' => true, 'videos' => $out]);
}

respond(404, ['ok' => false, 'error' => 'Unknown YouTube action.']);
