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

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
