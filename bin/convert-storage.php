<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

$driver = $argv[1] ?? '';
$destination = $argv[2] ?? '';
if (!in_array($driver, ['sqlite', 'flatfile'], true) || $destination === '' || count($argv ?? []) !== 3) {
    throw new InvalidArgumentException('Usage: php bin/convert-storage.php sqlite|flatfile NEW_FILE');
}
if (str_contains($destination, "\0") || str_contains($destination, '://') || !preg_match('/^\.[a-zA-Z0-9_-]+\.' . ($driver === 'flatfile' ? 'json' : 'db') . '$/D', basename($destination))) {
    throw new InvalidArgumentException('Choose a new hidden local .json or .db filename.');
}
$directory = realpath(dirname($destination));
if ($directory === false || !is_dir($directory)) {
    throw new InvalidArgumentException('The destination directory must already exist.');
}
$destination = $directory . DIRECTORY_SEPARATOR . basename($destination);
$lock = new Adelia\BoardLock('adelia.lock');
$destinationLock = new Adelia\StorageLock($destination . '.lock');
if (file_exists($destination) || is_link($destination) || file_exists($destination . '.head') || file_exists($destination . '.journal')) {
    throw new RuntimeException('The destination already exists. Nothing was overwritten.');
}
$config = Adelia\Config::load('settings.php');
if (!is_file($config->dbpath) && !($config->dbdriver === 'flatfile' && is_file($config->dbpath . '.head'))) {
    throw new RuntimeException('The source database does not exist. Conversion will not create an empty source board.');
}
$source = new Adelia\Database($config);
$snapshot = $source->snapshot();
$temporary = $directory . DIRECTORY_SEPARATOR . '.adelia-convert-' . bin2hex(random_bytes(12)) . ($driver === 'flatfile' ? '.json' : '.db');
try {
    $target = new Adelia\Database($config->with(['dbdriver' => $driver, 'dbpath' => $temporary]), create: true, initial: $snapshot);
    if ($target->snapshot() != $snapshot) {
        throw new RuntimeException('The converted data failed verification. The source was not changed.');
    }
    unset($target);
    if (PHP_OS_FAMILY !== 'Windows' && !chmod($temporary, 0o600)) {
        throw new RuntimeException('Unable to secure the converted data.');
    }
    // Publish the commit record last. A partial destination is never opened as an empty board.
    foreach ($driver === 'flatfile' ? ['', '.journal', '.head'] : [''] as $suffix) {
        if (!link($temporary . $suffix, $destination . $suffix)) {
            throw new RuntimeException('Unable to publish the converted files without overwriting. Source storage is intact; inspect any partial destination.');
        }
        Adelia\StorageFiles::syncDirectory($directory);
    }
} finally {
    unset($target);
    foreach ([$temporary, $temporary . '.lock', $temporary . '.head', $temporary . '.journal'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
echo "Conversion verified. Original storage, settings and uploaded files are unchanged.\n";
echo 'For flat-file storage keep the .json, .head and .journal files together.' . PHP_EOL;
echo 'New storage: ' . $destination . "\n";
echo 'While the board is stopped, set dbdriver to ' . $driver . ' and dbpath to the new filename in settings.php, then run php imgboard.php.' . "\n";
