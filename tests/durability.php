<?php

declare(strict_types=1);

namespace Adelia {
    // Fault injection exists only in this CLI test process, never in the application.
    final class DurabilityFault
    {
        public static string $target = '';
        public static string $mode = '';
        public static string $marker = '';
    }

    function rename(string $from, string $to): bool
    {
        $match = $to === DurabilityFault::$target;
        if ($match && DurabilityFault::$mode === 'rename-fail') {
            return false;
        }
        $result = \rename($from, $to);
        if ($match && DurabilityFault::$mode === 'after-rename') {
            throw new \RuntimeException('Injected interruption after publishing a file.');
        }
        if ($match && DurabilityFault::$mode === 'kill') {
            \file_put_contents(DurabilityFault::$marker, 'ready');
            sleep(30);
            exit(3);
        }
        return $result;
    }

    function fwrite(mixed $stream, string $data): int|false
    {
        if (DurabilityFault::$mode === 'write-zero') {
            return 0;
        }
        if (DurabilityFault::$mode === 'write-short') {
            $data = substr($data, 0, max(1, intdiv(strlen($data), 2)));
        }
        return \fwrite($stream, $data);
    }

    function fsync(mixed $stream): bool
    {
        return DurabilityFault::$mode !== 'sync-fail' && \fsync($stream);
    }

    function unlink(string $path): bool
    {
        return !($path === DurabilityFault::$target && DurabilityFault::$mode === 'unlink-fail') && \unlink($path);
    }

    function move_uploaded_file(string $from, string $to): bool
    {
        // CLI has no HTTP upload registry; real multipart uploads are tested in the browser suite.
        return \rename($from, $to);
    }
}

namespace {
    use Adelia\Application;
    use Adelia\BoardMessage;
    use Adelia\CommitUncertain;
    use Adelia\Config;
    use Adelia\Database;
    use Adelia\DurabilityFault as Fault;
    use Adelia\Filter;
    use Adelia\FlatFileStore;
    use Adelia\MediaJournal;
    use Adelia\Passwords;
    use Adelia\Request;
    use Adelia\Schema;
    use Adelia\StorageFiles;
    use Adelia\StorageLock;

    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    require dirname(__DIR__) . '/bootstrap.php';
    $values = require __DIR__ . '/fixtures/config.php';
    $values['tripseed'] = 'durability-tests-only';
    $config = new Config(...$values);
    $account = ['username' => 'first', 'password' => 'test-hash', 'role' => 2, 'lastactive' => 0];
    $change = static function (Database $db) use ($account): void {
        $db->begin();
        $db->insert('accounts', array_replace($account, ['username' => 'second']));
        $db->insert('accounts', array_replace($account, ['username' => 'third']));
        $db->insert('keywords', ['text' => 'test', 'action' => 'delete']);
        $db->commit();
    };
    if (($argv[1] ?? '') === 'worker') {
        $db = new Database($config->with(['dbdriver' => 'flatfile', 'dbpath' => $argv[2]]));
        Fault::$target = $argv[2] . $argv[3];
        Fault::$mode = 'kill';
        Fault::$marker = $argv[4];
        $change($db);
        exit(2);
    }

    $passed = 0;
    $check = static function (bool $condition, string $message) use (&$passed): void {
        if (!$condition) {
            throw new RuntimeException('FAIL: ' . $message);
        }
        $passed++;
        echo 'PASS: ' . $message . PHP_EOL;
    };
    $reject = static function (Closure $operation, string $message, string $class = Throwable::class) use ($check): void {
        try {
            $operation();
        } catch (Throwable $exception) {
            $check($exception instanceof $class, $message);
            return;
        }
        $check(false, $message);
    };
    $root = sys_get_temp_dir() . '/adelia-durability-' . bin2hex(random_bytes(12));
    mkdir($root, 0o700);
    $root = str_replace('\\', '/', realpath($root));
    register_shutdown_function(static function () use ($root): void {
        Fault::$mode = '';
        chdir(dirname($root));
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($root);
    });
    $new = static function (string $name) use ($root, $config, $account): array {
        $path = $root . '/.' . $name . '.json';
        $cfg = $config->with(['dbdriver' => 'flatfile', 'dbpath' => $path]);
        $db = new Database($cfg, create: true);
        $db->insert('accounts', $account);
        // Match the canonical Windows path used by the storage engine.
        return [$db, $cfg, FlatFileStore::path($path)];
    };

    [$db, $cfg, $path] = $new('transactions');
    $before = $db->snapshot();
    $head = file_get_contents($path . '.head');
    $db->begin();
    $db->delete('accounts', Filter::equal('id', 1));
    $db->schedule('rebuild');
    $db->rollback();
    $check($db->snapshot() === $before, 'Rollback restores all tables and ID counters together');
    $db->begin();
    $db->update('accounts', 1, $account);
    $db->delete('accounts', Filter::equal('id', 999));
    $db->commit();
    $check(file_get_contents($path . '.head') === $head, 'No-op requests do not create new revisions');
    $reject(fn() => $db->schedule('media', '../settings.php'), 'Cleanup jobs reject path traversal');
    $reject(fn() => $db->schedule('unknown'), 'Unknown job kinds are rejected before saving');

    foreach (['.journal', '.head'] as $suffix) {
        Fault::$target = $path . $suffix;
        Fault::$mode = 'rename-fail';
        $reject(fn() => $change($db), 'Failed publication of ' . $suffix . ' rejects the transaction');
        Fault::$mode = '';
        $check(new Database($cfg)->snapshot() === $before, 'Failed ' . $suffix . ' leaves the complete previous commit');
    }
    foreach (['write-zero', 'sync-fail'] as $mode) {
        Fault::$mode = $mode;
        $reject(fn() => $change($db), $mode . ' rejects incomplete storage');
        Fault::$mode = '';
        $check(new Database($cfg)->snapshot() === $before, $mode . ' preserves the previous commit');
    }
    Fault::$mode = 'write-short';
    $change($db);
    Fault::$mode = '';
    $check($db->count('accounts') === 3 && $db->count('keywords') === 1, 'Partial writes are completed in a loop without losing data');
    $check(json_decode(file_get_contents($path . '.head'), true)['revision'] === json_decode($head, true)['revision'] + 1, 'Multiple table changes publish exactly one revision');
    $observer = new Database($cfg);
    $db->update('accounts', 1, ['lastactive' => 123]);
    $check($observer->row('accounts')['lastactive'] === 123, 'An existing reader notices a newer committed revision');
    $observer->update('accounts', 2, ['lastactive' => 456]);
    $check($db->row('accounts')['lastactive'] === 123 && $db->row('accounts', Filter::equal('id', 2))['lastactive'] === 456, 'A stale writer reloads before changing records');

    foreach (['.journal', '.head', ''] as $suffix) {
        [$crashDb, $crashConfig, $crashPath] = $new('kill-' . ($suffix === '' ? 'main' : substr($suffix, 1)));
        $marker = $root . '/marker';
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $crashPath, $suffix, $marker], [['pipe', 'r'], ['file', $root . '/worker-out', 'a'], ['file', $root . '/worker-err', 'a']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot launch crash test worker.');
        }
        fclose($pipes[0]);
        try {
            $deadline = microtime(true) + 10;
            do {
                clearstatcache(true, $marker);
                if (is_file($marker)) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline && proc_get_status($process)['running']);
            $check(is_file($marker), 'Worker reached the ' . ($suffix ?: 'main') . ' publication boundary');
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
        unlink($marker);
        $reopened = new Database($crashConfig);
        $committed = $suffix !== '.journal';
        $check($reopened->count('accounts') === ($committed ? 3 : 1) && $reopened->count('keywords') === ($committed ? 1 : 0), 'Killed worker recovers the complete ' . ($committed ? 'new' : 'old') . ' transaction after ' . ($suffix ?: 'main'));
        $reopened->update('accounts', 1, ['lastactive' => 99]);
        $check(new Database($crashConfig)->row('accounts')['lastactive'] === 99, 'Writing after crash recovery safely advances storage');
    }

    [$repairDb, $repairConfig, $repairPath] = $new('repair');
    Fault::$target = $repairPath;
    Fault::$mode = 'rename-fail';
    $change($repairDb);
    Fault::$mode = '';
    $check(new Database($repairConfig)->count('accounts') === 3, 'Failed main-file replacement recovers the committed journal');
    $repairDb->begin();
    $repairDb->commit();
    $check(file_get_contents($repairPath) === file_get_contents($repairPath . '.journal'), 'Repair restores the current main copy without a new revision');
    $complete = $repairDb->snapshot();
    $encoded = file_get_contents($repairPath);
    $savedHead = file_get_contents($repairPath . '.head');
    unlink($repairPath);
    $check(new Database($repairConfig)->snapshot() === $complete, 'A missing main file recovers from the exact committed journal');
    file_put_contents($repairPath, '{broken');
    $check(new Database($repairConfig)->snapshot() === $complete, 'A malformed main file recovers from the checked journal');
    file_put_contents($repairPath . '.journal', '{broken');
    $reject(fn() => new Database($repairConfig), 'Two damaged copies fail closed');
    $check(file_get_contents($repairPath) === '{broken', 'Corruption never silently initializes an empty board');
    file_put_contents($repairPath, $encoded);
    file_put_contents($repairPath . '.journal', $encoded);
    unlink($repairPath . '.head');
    $reject(fn() => new Database($repairConfig), 'A missing commit record refuses to guess a revision');
    file_put_contents($repairPath . '.head', '{broken');
    $reject(fn() => new Database($repairConfig), 'A damaged commit record refuses to guess a revision');
    file_put_contents($repairPath . '.head', $savedHead);
    $tampered = str_replace('first', 'other', $encoded);
    file_put_contents($repairPath, $tampered);
    file_put_contents($repairPath . '.journal', $tampered);
    $reject(fn() => new Database($repairConfig), 'Valid JSON with an altered payload fails checksum verification');
    file_put_contents($repairPath, $encoded);
    file_put_contents($repairPath . '.journal', $encoded);

    Fault::$target = $repairPath . '.head';
    Fault::$mode = 'after-rename';
    $reject(fn() => $repairDb->update('accounts', 1, ['lastactive' => 789]), 'An error after the commit point reports an uncertain acknowledgement', CommitUncertain::class);
    Fault::$mode = '';
    $check(new Database($repairConfig)->row('accounts')['lastactive'] === 789, 'An uncertain acknowledgement does not roll back published records');
    file_put_contents($repairPath . '.journal', '{broken');
    $reject(fn() => new Database($repairConfig), 'An older valid main copy cannot replace a damaged newer committed journal');
    $busy = new StorageLock($root . '/busy.lock');
    $reject(fn() => new StorageLock($root . '/busy.lock', timeout: 0.03), 'Lock contention times out without proceeding unlocked', BoardMessage::class);
    $busy->release();

    $legacyPath = $root . '/.legacy.json';
    $legacy = $complete;
    unset($legacy['tables']['adelia_jobs']);
    file_put_contents($legacyPath, json_encode($legacy, JSON_THROW_ON_ERROR));
    FlatFileStore::upgrade($legacyPath, new Schema($config));
    $check(new Database($config->with(['dbdriver' => 'flatfile', 'dbpath' => $legacyPath]))->snapshot() === $complete, 'Explicit legacy upgrade preserves every record and ID counter');
    $check(json_decode(file_get_contents($legacyPath . '.legacy'), true) === $legacy, 'Legacy upgrade keeps an unchanged private original');
    $reject(fn() => FlatFileStore::upgrade($legacyPath, new Schema($config)), 'Upgrade cannot overwrite an already committed store');

    // Exercise real public deletion and maintenance, using only temporary board data.
    foreach (['flatfile', 'sqlite'] as $driver) {
        $board = $root . '/' . $driver;
        mkdir($board);
        foreach (['src', 'thumb', 'res'] as $folder) {
            mkdir($board . '/' . $folder);
        }
        chdir($board);
        $boardConfig = $config->with(['dbdriver' => $driver, 'dbpath' => $board . '/.board.' . ($driver === 'flatfile' ? 'json' : 'db')]);
        $boardDb = new Database($boardConfig, create: true);
        $request = new Request(['delete' => ''], ['delete' => '1', 'password' => 'delete-test'], [], ['REQUEST_METHOD' => 'POST', 'PHP_SELF' => 'imgboard.php']);
        $app = new Application($boardConfig, $boardDb, $request);
        $call = static fn(string $method, mixed ...$args): mixed => new ReflectionMethod($app, $method)->invoke($app, ...$args);
        $call('initializeAccounts');
        $boardDb->begin();
        foreach ([0, 1] as $parent) {
            $post = $call('newPost', $parent);
            $post['message'] = 'Durability fixture';
            $post['password'] = Passwords::hash('delete-test');
            $post['file'] = 'image-' . $parent . '.png';
            $post['thumb'] = 'thumb-' . $parent . '.png';
            file_put_contents('src/' . $post['file'], 'test-image');
            file_put_contents('thumb/' . $post['thumb'], 'test-thumb');
            $call('insertPost', $post);
        }
        $boardDb->commit();
        $app->maintain();
        $original = $boardDb->snapshot();
        if ($driver === 'flatfile') {
            $admin = $boardDb->row('accounts');
            $_SESSION['account_id'] = $admin['id'];
            $_SESSION['account_fingerprint'] = hash('sha256', $admin['password']);
            $picture = imagecreatetruecolor(20, 20);
            imagepng($picture, $root . '/replacement.png');
            $oldPost = $boardDb->row('b_posts');
            $edit = new Application($boardConfig, $boardDb, new Request(
                ['manage' => '', 'edit' => '1'],
                ['subject' => 'Changed', 'message' => 'Changed text', 'revision' => $call('postRevision', $oldPost)],
                ['file' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => $root . '/replacement.png', 'name' => 'replacement.png']],
                ['REQUEST_METHOD' => 'POST', 'PHP_SELF' => 'imgboard.php'],
            ));
            Fault::$target = FlatFileStore::path($boardConfig->dbpath) . '.head';
            Fault::$mode = 'rename-fail';
            $reject(fn() => $edit->run(), 'Failed moderator replacement cannot commit partial changes');
            Fault::$mode = '';
            $check(new Database($boardConfig)->snapshot() === $original && count(glob('src/*')) === 2 && count(glob('thumb/*')) === 2 && is_file('src/image-0.png'), 'Failed replacement preserves old text, attachment and audit records and removes the new orphan');
            unset($edit);
            $oldFingerprint = $_SESSION['account_fingerprint'];
            $passwordChange = new Application($boardConfig, $boardDb, new Request(['manage' => '', 'changepassword' => ''], ['current_password' => Passwords::INITIAL_PASSWORD, 'password' => 'New-test-passphrase-943', 'confirm' => 'New-test-passphrase-943'], [], ['REQUEST_METHOD' => 'POST', 'PHP_SELF' => 'imgboard.php']));
            Fault::$mode = 'rename-fail';
            $reject(fn() => $passwordChange->run(), 'A failed password commit is reported');
            Fault::$mode = '';
            $check($boardDb->snapshot() === $original && $_SESSION['account_fingerprint'] === $oldFingerprint, 'Failed password change preserves the old credential, session and audit records');
            unset($passwordChange);
            $uncertainPreview = new Application($boardConfig, $boardDb, new Request(['preview' => '1'], [], [], ['REQUEST_METHOD' => 'GET', 'PHP_SELF' => 'imgboard.php']));
            Fault::$mode = 'after-rename';
            $reject(fn() => $uncertainPreview->run(), 'An uncertain save returns an actionable service error', BoardMessage::class);
            Fault::$mode = '';
            $check($boardDb->row('accounts')['lastactive'] > 0 && $boardDb->rows('b_posts') === $original['tables']['b_posts']['rows'], 'Uncertain acknowledgement preserves the committed account update and existing posts');
            $original = $boardDb->snapshot();
            unset($uncertainPreview);
            $_SESSION = [];
            Fault::$mode = 'rename-fail';
            $reject(fn() => $app->run(), 'A failed deletion commit is reported');
            Fault::$mode = '';
            $check(new Database($boardConfig)->snapshot() === $original && is_file('src/image-0.png') && is_file('thumb/thumb-1.png') && is_file('res/1.html'), 'Failed deletion keeps the thread, replies, images and static page');
        }
        Fault::$target = 'index.html';
        Fault::$mode = 'rename-fail';
        $reject(fn() => $app->run(), $driver . ' reports that a saved deletion still needs page maintenance', BoardMessage::class);
        Fault::$mode = '';
        $check($boardDb->count('b_posts') === 0 && $boardDb->count('adelia_jobs') > 0 && is_file('src/image-0.png'), $driver . ' commits deletion and durable cleanup jobs before removing images');
        $retry = new Application($boardConfig, new Database($boardConfig), $request);
        $retry->maintain();
        $check($boardDb->count('adelia_jobs') === 0 && !is_file('src/image-0.png') && !is_file('thumb/thumb-1.png') && !is_file('res/1.html'), $driver . ' restart finishes pending page and attachment cleanup');
        $retry->maintain();
        $check($boardDb->count('adelia_jobs') === 0, $driver . ' repeated recovery is idempotent');
        $journal = new MediaJournal();
        $journal->reserve('src/orphan.png');
        $journal->reserve('src/kept.png');
        file_put_contents('src/orphan.png', 'orphan');
        file_put_contents('src/kept.png', 'referenced');
        Fault::$mode = 'sync-fail';
        $reject(fn() => $journal->prepare(['src/kept.png' => true]), $driver . ' cannot commit an attachment whose flush fails');
        Fault::$mode = '';
        $journal->prepare(['src/kept.png' => true]);
        $journal->reserve('src/missing.png');
        $reject(fn() => $journal->prepare(['src/missing.png' => true]), $driver . ' cannot commit a missing reserved attachment');
        $journal->recover(['src/kept.png' => true]);
        $check(!is_file('src/orphan.png') && is_file('src/kept.png'), $driver . ' interrupted-upload recovery removes only unreferenced reservations');
        $journal->reserve('src/stubborn.png');
        file_put_contents('src/stubborn.png', 'orphan');
        Fault::$target = $board . '/src/stubborn.png';
        Fault::$mode = 'unlink-fail';
        $reject(fn() => $journal->recover([]), $driver . ' failed file cleanup keeps a retryable journal');
        Fault::$mode = '';
        $journal->recover([]);
        $check(!is_file('src/stubborn.png'), $driver . ' a later retry finishes the failed cleanup');
        unset($call, $retry, $app, $boardDb);
    }
    echo "$passed durability checks passed." . PHP_EOL;
}
