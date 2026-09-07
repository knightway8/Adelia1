<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';
$config = Adelia\Config::load('settings.php');
if ($config->dbdriver !== 'sqlite') {
    throw new RuntimeException('This legacy schema upgrade applies only to SQLite. Flat-file storage already uses the current schema.');
}
$lock = new Adelia\BoardLock('adelia.lock');
$database = new PDO('sqlite:' . $config->dbpath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database->exec('PRAGMA busy_timeout = 60000');
$retiredTable = $argv[1] ?? 'bans';
if (in_array($retiredTable, [$config->dbaccounts, $config->dbposts, $config->dbreports, $config->dblogs, $config->dbkeywords], true)) {
    throw new RuntimeException('The retired table must not be an active application table.');
}
$columns = static fn(string $table): array => array_column($database->query('PRAGMA table_info(' . Adelia\Schema::identifier($table) . ')')->fetchAll(), 'name');
$database->exec('PRAGMA secure_delete = ON');
$postTable = Adelia\Schema::identifier($config->dbposts);
if (!in_array('message_source', $columns($config->dbposts), true)) {
    $database->exec("ALTER TABLE $postTable ADD COLUMN message_source TEXT NOT NULL DEFAULT ''");
}
if (!in_array('source_known', $columns($config->dbposts), true)) {
    $database->exec("ALTER TABLE $postTable ADD COLUMN source_known BIGINT NOT NULL DEFAULT 0");
}
foreach ([$config->dbposts, $config->dbreports] as $table) {
    if (in_array('ip', $columns($table), true)) {
        $database->exec('ALTER TABLE ' . Adelia\Schema::identifier($table) . ' DROP COLUMN ip');
    }
}
$database->exec('DROP TABLE IF EXISTS ' . Adelia\Schema::identifier($retiredTable));
// Keep keyword blocking, replacing retired ban actions with rejection of the post.
$database->exec('UPDATE ' . Adelia\Schema::identifier($config->dbkeywords) . " SET action = 'delete' WHERE action LIKE 'ban%'");
foreach ($database->query('SELECT id, message FROM ' . Adelia\Schema::identifier($config->dblogs))->fetchAll() as $entry) {
    if (preg_match('/^(Banned |Lifted ban on |Added ban message to )/', $entry['message'])) {
        $database->prepare('DELETE FROM ' . Adelia\Schema::identifier($config->dblogs) . ' WHERE id = ?')->execute([$entry['id']]);
    } elseif (str_starts_with($entry['message'], 'Deleted ')) {
        $cleaned = preg_replace('/ - hmac:[a-f0-9]{64}/', '', $entry['message']);
        if ($cleaned !== $entry['message']) {
            $database->prepare('UPDATE ' . Adelia\Schema::identifier($config->dblogs) . ' SET message = ? WHERE id = ?')->execute([$cleaned, $entry['id']]);
        }
    }
}
$database->exec('VACUUM');
echo "Moderation schema upgraded. Retired identifying records removed.\n";
