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

function valid_channel_id(string $channelId): bool {
    return (bool)preg_match('/^UC[A-Za-z0-9_-]{20,30}$/', $channelId);
}

function youtube_feed(string $channelId): array {
    if (!valid_channel_id($channelId)) respond(422, ['ok' => false, 'error' => 'Invalid YouTube channel.']);

    $url = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($channelId);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => "Accept: application/atom+xml, application/xml;q=0.9\r\nUser-Agent: Faveside/1.0\r\n",
        ]
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) return [];

    $status = 200;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) $status = (int)$m[1];
    }
    if ($status >= 400) return [];

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    if ($xml === false) return [];
    $xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
    $xml->registerXPathNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
    $xml->registerXPathNamespace('media', 'http://search.yahoo.com/mrss/');

    $out = [];
    foreach (($xml->xpath('//atom:entry') ?: []) as $entry) {
        $yt = $entry->children('http://www.youtube.com/xml/schemas/2015');
        $media = $entry->children('http://search.yahoo.com/mrss/');
        $videoId = trim((string)($yt->videoId ?? ''));
        if ($videoId === '') continue;
        $thumbnail = '';
        if (isset($media->group)) {
            $thumbs = $media->group->children('http://search.yahoo.com/mrss/')->thumbnail;
            if (isset($thumbs[0])) $thumbnail = (string)$thumbs[0]['url'];
        }
        $out[] = [
            'videoId' => $videoId,
            'title' => trim((string)($entry->title ?? 'New video')),
            'publishedAt' => trim((string)($entry->published ?? '')),
            'thumbnail' => $thumbnail,
            'url' => 'https://www.youtube.com/watch?v=' . rawurlencode($videoId),
        ];
        if (count($out) >= 5) break;
    }
    return $out;
}

function google_get(string $endpoint, array $params): array {
    $key = youtube_key();
    if ($key === '') respond(503, [
        'ok' => false,
        'setup_required' => true,
        'error' => 'Live YouTube search needs the server API key. Followed-channel updates still work.'
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
    respond(200, [
        'ok' => true,
        'configured' => youtube_key() !== '',
        'activity_available' => true,
        'search_available' => youtube_key() !== ''
    ]);
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
    if (!valid_channel_id($channelId)) respond(422, ['ok' => false, 'error' => 'Invalid YouTube channel.']);

    $feed = youtube_feed($channelId);
    if ($feed) respond(200, ['ok' => true, 'videos' => $feed, 'source' => 'feed']);

    if (youtube_key() === '') respond(502, ['ok' => false, 'error' => 'YouTube updates are temporarily unavailable for this channel.']);

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
    respond(200, ['ok' => true, 'videos' => $out, 'source' => 'api']);
}

respond(404, ['ok' => false, 'error' => 'Unknown YouTube action.']);
