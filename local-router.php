<?php

declare(strict_types=1);
// Router for PHP's local development server. Hosted sites use their own web-server rules.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$root = __DIR__;
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
header('X-Adelia-Local: 1');
header('X-Content-Type-Options: nosniff');

function localNotFound(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}

// Reject traversal, hidden files and Windows path aliases before resolving paths.
if (str_contains($path, '\\') || str_contains($path, ':') || str_contains($path, "\0") || preg_match('~(?:^|/)\.|[. ](?:/|$)~', $path)) {
    localNotFound();
}

if ($path === '/imgboard.php' || $path === '/inc/captcha.php') {
    require $root . $path;
    return true;
}

if ($path === '/') {
    $path = '/index.html';
}

// The board's static outputs and media are the only other public files.
$allowed = preg_match('~^/(?:index|catalog|[0-9]+)\.html$~', $path)
    || preg_match('~^/(?:catalog|threads)\.json$~', $path)
    || preg_match('~^/res/[0-9]+\.(?:html|json)$~', $path)
    || preg_match('~^/js/[^/]+\.js$~', $path)
    || preg_match('~^/stylesheets/(?:[a-zA-Z0-9_+.-]+/)*[a-zA-Z0-9_+.-]+\.(?:css|png|gif|jpe?g|svg|ico|woff2?|ttf|eot)$~i', $path)
    || preg_match('~^/(?:src|thumb)/[^/]+\.(?:jpe?g|png|gif|webp|ico|swf|aac|flac|ogg|opus|mp3|mp4|wav|webm)$~i', $path)
    || in_array($path, ['/favicon.ico', '/lock.png', '/sticky.png', '/swf_thumbnail.png', '/video_overlay.png'], true);

$file = realpath($root . $path);
if (!$allowed || $file === false || !is_file($file) || !str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $root) . '/')) {
    localNotFound();
}

$types = [
    'html' => 'text/html; charset=utf-8', 'json' => 'application/json',
    'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
    'swf' => 'application/x-shockwave-flash', 'aac' => 'audio/aac',
    'flac' => 'audio/flac', 'ogg' => 'audio/ogg', 'opus' => 'audio/ogg',
    'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'wav' => 'audio/wav',
    'webm' => 'video/webm', 'svg' => 'image/svg+xml',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
    'eot' => 'application/vnd.ms-fontobject',
];
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . $types[$extension]);
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-cache');
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    readfile($file);
}
return true;
