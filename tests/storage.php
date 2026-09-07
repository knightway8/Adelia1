<?php

declare(strict_types=1);

use Adelia\Config;
use Adelia\Database;
use Adelia\Filter;
use Adelia\Schema;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

$passed = 0;
$check = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passed++;
    echo 'PASS: ' . $message . PHP_EOL;
};
$reject = static function (Closure $operation, string $message) use ($check): void {
    try {
        $operation();
    } catch (Throwable) {
        $check(true, $message);
        return;
    }
    $check(false, $message);
};
$directory = sys_get_temp_dir() . '/adelia-storage-test-' . bin2hex(random_bytes(12));
mkdir($directory, 0o700);
register_shutdown_function(static function () use ($directory): void {
    foreach (new DirectoryIterator($directory) as $file) {
        if ($file->isFile() && !$file->isLink()) {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
});
$values = require __DIR__ . '/fixtures/config.php';
$values['tripseed'] = 'storage-tests';
$config = new Config(...$values);
$schema = new Schema($config);
$stores = [];
foreach (['sqlite' => '.test.db', 'flatfile' => '.test.json'] as $driver => $name) {
    $stores[$driver] = new Database($config->with(['dbdriver' => $driver, 'dbpath' => $directory . '/' . $name]), create: true);
}

$post = [];
foreach ($schema->columns('b_posts') as $field => $type) {
    if ($field !== 'id') {
        $post[$field] = $type === 'int' ? 0 : '';
    }
}
$fixture = [
    ['parent' => 0, 'moderated' => 1, 'bumped' => 100, 'subject' => '10', 'file' => 'one.png'],
    ['parent' => 1, 'moderated' => 1, 'bumped' => 200, 'message' => "♞\n<script>literal</script>", 'file' => 'two.png'],
    ['parent' => 1, 'moderated' => 0, 'bumped' => 250],
    ['parent' => 0, 'moderated' => 1, 'bumped' => 300, 'stickied' => 1, 'subject' => '2'],
    ['parent' => 0, 'moderated' => 0, 'bumped' => 500],
    ['parent' => 0, 'moderated' => 1, 'bumped' => 100, 'subject' => '001'],
];
foreach ($stores as $driver => $store) {
    foreach ($fixture as $index => $changes) {
        $check($store->insert('b_posts', array_replace($post, $changes)) === $index + 1, "$driver allocates consecutive post IDs");
    }
    foreach (['10', '2', 'A', 'a', "x' OR 1=1 --"] as $index => $username) {
        $store->insert('accounts', ['username' => $username, 'password' => 'test-hash', 'role' => $index % 2 + 2, 'lastactive' => $index]);
    }
    $store->insert('b_reports', ['post' => 2]);
    $store->insert('b_reports', ['post' => 4]);
    $store->insert('keywords', ['text' => 'blocked', 'action' => 'delete']);
    foreach ([100, 200, 200, 300] as $time) {
        $store->insert('logs', ['timestamp' => $time, 'account' => 1, 'message' => 'Test log']);
    }
    $check(array_column($store->rows('b_posts', Filter::all(Filter::equal('parent', 0), Filter::greater('moderated', 0)), ['stickied' => 'DESC', 'bumped' => 'DESC', 'id' => 'DESC']), 'id') === [4, 6, 1], "$driver orders pins and visible threads correctly");
    $thread = Filter::all(Filter::any(Filter::equal('id', 1), Filter::equal('parent', 1)), Filter::greater('moderated', 0));
    $check(array_column($store->rows('b_posts', $thread), 'id') === [1, 2], "$driver keeps hidden replies out of public threads");
    $check($store->count('b_posts', Filter::all($thread, Filter::notEqual('file', ''))) === 2, "$driver counts thread attachments");
    $check(array_column($store->rows('accounts', order: ['username' => 'ASC']), 'username') === ['10', '2', 'A', 'a', "x' OR 1=1 --"], "$driver sorts text without numeric-string coercion");
    $check(array_column($store->rows('logs', order: ['timestamp' => 'DESC', 'id' => 'DESC'], limit: 2, offset: 1), 'id') === [3, 2], "$driver paginates tied moderation timestamps");
    $check($store->rows('b_posts', limit: 0) === [] && $store->rows('b_posts', offset: 99) === [], "$driver handles empty result windows");
    $check($store->row('b_posts', Filter::equal('id', 99)) === [], "$driver returns an empty missing-record lookup");
    $check($store->count('b_posts', Filter::any()) === 0 && $store->count('b_posts', Filter::all()) === 6, "$driver handles empty filter groups consistently");
    $store->update('b_posts', 2, ['subject' => 'Edited', 'locked' => 1, 'file' => 'replacement.png']);
    $check($store->row('b_posts', Filter::equal('id', 2))['parent'] === 1 && $store->row('b_posts', Filter::equal('id', 2))['file'] === 'replacement.png', "$driver updates only the selected fields");
    $store->delete('b_reports', Filter::equal('post', 2));
    $check($store->count('b_reports') === 1 && $store->row('b_reports')['post'] === 4, "$driver deletes only the selected report");
    $store->delete('b_posts', Filter::equal('id', 6));
    $check($store->insert('b_posts', $post) === 7, "$driver does not reuse a deleted post ID");
    $before = $store->snapshot();
    $reject(fn(): mixed => $store->rows('accounts; DROP TABLE accounts'), "$driver rejects unrecognized tables");
    $reject(fn(): mixed => $store->rows('accounts', Filter::equal('id OR 1=1', 1)), "$driver rejects injected field names");
    $reject(fn(): mixed => $store->rows('accounts', Filter::equal('id', '1')), "$driver rejects a mismatched query type");
    $reject(fn(): mixed => $store->rows('accounts', order: ['username' => 'DESC; DELETE']), "$driver rejects injected sort directions");
    $reject(fn(): mixed => $store->rows('accounts', limit: -1), "$driver rejects invalid pagination");
    $reject(fn(): mixed => $store->insert('b_reports', ['post' => '2']), "$driver rejects a mismatched record type");
    $reject(fn(): mixed => $store->insert('b_reports', ['post' => 2, 'ip' => 'test']), "$driver rejects retired identity fields");
    $reject(function () use ($store): void {
        $store->update('b_posts', 1, ['id' => 500]);
    }, "$driver rejects changes to record IDs");
    $reject(function () use ($store): void {
        $store->update('b_posts', 1, ['message' => "\xff"]);
    }, "$driver rejects malformed UTF-8 before writing");
    $reject(function () use ($store, $before): void {
        $store->import($before);
    }, "$driver refuses to import over populated storage");
    $check($store->snapshot() === $before, "$driver leaves data intact after rejected operations");
}
$check($stores['sqlite']->snapshot() == $stores['flatfile']->snapshot(), 'Both backends produce the same complete board records');

$snapshot = $stores['sqlite']->snapshot();
foreach (['flatfile', 'sqlite'] as $driver) {
    $target = new Database($config->with(['dbdriver' => $driver, 'dbpath' => $directory . '/.converted.' . ($driver === 'sqlite' ? 'db' : 'json')]), create: true);
    $target->import($snapshot);
    $check($target->snapshot() == $snapshot, "Conversion to $driver preserves all tables, hashes, source text and ID counters");
    $snapshot = $target->snapshot();
    $check($target->insert('b_posts', $post) === 8, "Conversion to $driver preserves the next post ID");
    unset($target);
}

$path = $directory . '/.damaged.json';
$publishDamaged = static function (string $payload) use ($path): string {
    $head = ['format' => 'adelia-commit', 'version' => 2, 'store' => str_repeat('a', 32), 'revision' => 1, 'sha256' => hash('sha256', $payload)];
    $encoded = json_encode(['commit' => $head, 'payload' => $payload], JSON_THROW_ON_ERROR);
    file_put_contents($path . '.head', json_encode($head, JSON_THROW_ON_ERROR));
    file_put_contents($path . '.journal', $encoded);
    file_put_contents($path, $encoded);
    return $encoded;
};
foreach (['', '{', 'null', '{"format":"other","version":1,"tables":{}}', json_encode(array_replace($snapshot, ['version' => 999]), JSON_THROW_ON_ERROR)] as $contents) {
    $encoded = $publishDamaged($contents);
    $reject(fn(): Database => new Database($config->with(['dbdriver' => 'flatfile', 'dbpath' => $path])), 'Damaged or unsupported JSON fails closed');
    $check(file_get_contents($path) === $encoded, 'Rejected JSON is not replaced with an empty board');
}
$invalid = $snapshot;
$invalid['tables']['accounts']['rows'][] = $invalid['tables']['accounts']['rows'][0];
$publishDamaged(json_encode($invalid, JSON_THROW_ON_ERROR));
$reject(fn(): Database => new Database($config->with(['dbdriver' => 'flatfile', 'dbpath' => $path])), 'Duplicate IDs in a data file are rejected');
$publishDamaged(str_repeat(' ', 16 * 1024 * 1024 + 1));
$reject(fn(): Database => new Database($config->with(['dbdriver' => 'flatfile', 'dbpath' => $path])), 'Oversized files are rejected before decoding');
$before = $stores['flatfile']->snapshot();
$reject(function () use ($stores): void {
    $stores['flatfile']->update('b_posts', 1, ['message' => str_repeat('a', 16 * 1024 * 1024)]);
}, 'An oversized write is rejected');
$check($stores['flatfile']->snapshot() === $before, 'An oversized write preserves the last complete file');
$reject(fn(): Database => new Database($config->with(['dbdriver' => 'flatfile', 'dbpath' => $directory . '/public.json'])), 'Public JSON filenames are rejected');
$reject(fn(): Database => new Database($config->with(['dbdriver' => 'flatfile', 'dbpath' => $directory . '/.test.db'])), 'Selecting flatfile cannot overwrite an SQLite file');
$reject(fn(): Database => new Database($config->with(['dbdriver' => 'mysql'])), 'Removed database drivers are rejected');

foreach (['sqlite', 'flatfile'] as $driver) {
    $path = $directory . '/.concurrent.' . ($driver === 'sqlite' ? 'db' : 'json');
    $store = new Database($config->with(['dbdriver' => $driver, 'dbpath' => $path]), create: true);
    $workers = [];
    foreach (['a', 'b', 'c', 'd', 'reader'] as $worker) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/storage-worker.php', $driver, $path, $worker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, options: ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch storage test worker.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes, $worker];
    }
    foreach ($workers as [$process, $pipes, $worker]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $check(proc_close($process) === 0 && $error === '' && str_contains($output, 'completed'), "$driver concurrent worker $worker completes without invalid reads");
    }
    $rows = $store->rows('accounts');
    $check(count($rows) === 120 && count(array_unique(array_column($rows, 'username'))) === 120 && count(array_unique(array_column($rows, 'id'))) === 120, "$driver preserves every concurrent insert and unique ID");
    $check(array_all($rows, static fn(array $row): bool => $row['lastactive'] === 42), "$driver preserves every concurrent update");
    unset($store);
}
unset($stores);
echo $passed . ' storage checks passed.' . PHP_EOL;
