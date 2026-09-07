<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';
$config = Adelia\Config::load('settings.php');
$action = $argv[1] ?? '';
if (!in_array($action, ['init', 'check', 'repair', 'upgrade'], true) || count($argv ?? []) !== 2) {
    throw new InvalidArgumentException('Usage: php bin/storage.php init|check|repair|upgrade');
}
$lock = new Adelia\BoardLock('adelia.lock');
if ($action === 'upgrade') {
    if ($config->dbdriver !== 'flatfile') {
        throw new InvalidArgumentException('The JSON upgrade applies only to flat-file storage.');
    }
    Adelia\FlatFileStore::upgrade($config->dbpath, new Adelia\Schema($config));
}
if ($action === 'init' && (file_exists($config->dbpath) || is_link($config->dbpath))) {
    throw new RuntimeException('The database already exists. Initialization did not overwrite it.');
}
$database = new Adelia\Database($config, create: $action === 'init');
if ($action === 'repair') {
    $database->begin();
    $database->commit();
}
$snapshot = $database->snapshot();
echo 'Storage verified: ' . $config->dbdriver . PHP_EOL;
foreach ($snapshot['tables'] as $table => $data) {
    echo $table . ': ' . count($data['rows']) . ' records' . PHP_EOL;
}
if ($action === 'init') {
    echo "Run php imgboard.php to create accounts and public pages.\n";
}
