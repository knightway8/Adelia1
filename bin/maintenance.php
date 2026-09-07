<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';
$config = Adelia\Config::load('settings.php');
date_default_timezone_set($config->timezone);
$request = new Adelia\Request([], [], [], ['PHP_SELF' => 'imgboard.php', 'REQUEST_METHOD' => 'GET']);
new Adelia\Application($config, new Adelia\Database($config), $request)->maintain();
echo "Pages rebuilt and committed media cleanup completed.\n";
