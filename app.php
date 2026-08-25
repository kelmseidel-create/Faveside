<?php
declare(strict_types=1);

$path = __DIR__ . '/app.html';
$html = @file_get_contents($path);
if ($html === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Faveside is temporarily unavailable.';
    exit;
}

$sync = '<script src="app-sync.js"></script>' . "\n  ";
$html = preg_replace('/<script>\s*const KEY=/', $sync . '<script>\n    const KEY=', $html, 1) ?? $html;
$html = str_replace('</body>', '  <script src="parent-sync.js"></script>' . "\n</body>", $html);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
