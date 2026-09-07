<?php

declare(strict_types=1);

use Adelia\Application;
use Adelia\BoardMessage;
use Adelia\Config;
use Adelia\Database;
use Adelia\Filter;
use Adelia\Passwords;
use Adelia\RemoteHttp;
use Adelia\Request;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$applicationRoot = $argv[1] ?? dirname(__DIR__);
require $applicationRoot . '/bootstrap.php';
$passed = 0;
$failed = [];
$check = static function (bool $ok, string $description) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo 'PASS: ' . $description . PHP_EOL;
    } else {
        $failed[] = $description;
        echo 'FAIL: ' . $description . PHP_EOL;
    }
};
$rejects = static function (Closure $operation, string $description, ?int $status = null) use ($check): void {
    try {
        $result = $operation();
        $check(false, $description);
    } catch (BoardMessage $exception) {
        $check($status === null || $exception->status === $status, $description);
    } catch (Throwable) {
        $check(false, $description . ' (unexpected exception)');
    }
};
$_GET = $_POST = $_FILES = [];
$_SERVER['PHP_SELF'] = '/imgboard.php/evil"><img id="attack" src="x">';
$check(Request::capture()->server['PHP_SELF'] === '/imgboard.php', 'Request routing ignores attacker-controlled PATH_INFO');
foreach (['1"><img src=x>', '0', '999999999999999999999999', [], ['1', 'bad']] as $deletion) {
    $_POST = ['delete' => $deletion];
    $rejects(Request::capture(...), 'Malformed deletion identifiers are rejected at capture');
}
$_POST = [];
$_GET = ['delete' => ['1']];
$rejects(Request::capture(...), 'Query parameters cannot inject an array into scalar routes');
$_GET = [];
$_POST = ['message' => "bad\xff"];
$rejects(Request::capture(...), 'Invalid UTF-8 fails as a client error');
$_POST = ['password' => "bad\0password"];
$rejects(Request::capture(...), 'Null bytes fail as a client error');
$_POST = [];
$_FILES = ['file' => ['name' => ['one.png'], 'tmp_name' => ['tmp'], 'type' => ['image/png'], 'error' => [0], 'size' => [10]]];
$rejects(Request::capture(...), 'Nested multipart uploads are rejected before reaching media code');
$_FILES = [];
$rejects(fn(): string => Passwords::hash(str_repeat('x', 73)), 'New deletion passwords cannot silently truncate at 72 bytes');
$rejects(fn(): string => Passwords::hash("has\0null"), 'Password hashing rejects null bytes with a client error');

if (class_exists(RemoteHttp::class)) {
    foreach (['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '192.0.2.1', '224.0.0.1', '255.255.255.255', '::1', '::', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1', '64:ff9b::7f00:1', '2002:7f00:1::', '2001::1', '2001:db8::1', 'ff02::1'] as $address) {
        $check(!RemoteHttp::isPublicAddress($address), 'Remote policy blocks non-public or tunneled address ' . $address);
    }
    foreach (['1.1.1.1', '8.8.8.8', '2606:4700:4700::1111'] as $address) {
        $check(RemoteHttp::isPublicAddress($address), 'Remote policy accepts native public address ' . $address);
    }
    $dnsCalls = 0;
    $publicResolver = static function (string $host) use (&$dnsCalls): array {
        $dnsCalls++;
        return $dnsCalls === 1 ? ['1.1.1.1'] : ['127.0.0.1'];
    };
    $target = RemoteHttp::target('https://media.example/image.png', $publicResolver);
    $check($dnsCalls === 1 && $target['addresses'] === ['1.1.1.1'] && $target['port'] === 443, 'Resolution selects a fixed public address set for the transfer');
    $rejects(fn(): array => RemoteHttp::target('https://media.example/', static fn(string $host): array => ['1.1.1.1', '10.0.0.1']), 'Mixed public/private DNS answers fail closed');
    foreach (['file:///etc/passwd', 'http://127.1/', 'http://2130706433/', 'http://0x7f000001/', 'http://0177.0.0.1/', 'http://0x7f.0.0.1/', 'http://localhost/', 'http://user:pass@1.1.1.1/', 'http://1.1.1.1:8080/', 'https://[::1]/', 'http://169.254.169.254/', "https://good.example/\r\nx"] as $url) {
        $rejects(fn(): array => RemoteHttp::target($url), 'Remote policy rejects unsafe URL ' . str_replace(["\r", "\n"], '', $url));
    }
}

$values = require __DIR__ . '/fixtures/config.php';
$root = sys_get_temp_dir() . '/adelia-security-' . bin2hex(random_bytes(12));
mkdir($root, 0o700);
$remove = static function (string $directory) use (&$remove): void {
    foreach (new DirectoryIterator($directory) as $file) {
        if ($file->isDot()) {
            continue;
        }
        if ($file->isDir() && !$file->isLink()) {
            $remove($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
};
try {
    foreach (['sqlite', 'flatfile'] as $driver) {
        $directory = $root . '/' . $driver;
        mkdir($directory);
        foreach (['src', 'thumb', 'res'] as $name) {
            mkdir($directory . '/' . $name);
        }
        chdir($directory);
        $_SESSION = [];
        $config = new Config(...array_replace($values, ['dbdriver' => $driver, 'dbpath' => $directory . '/.test.' . ($driver === 'sqlite' ? 'db' : 'json'), 'nofileok' => true, 'captcha' => '', 'replycaptcha' => '']));
        $db = new Database($config, create: true);
        $request = new Request([], [], [], ['PHP_SELF' => '/imgboard.php', 'REQUEST_METHOD' => 'GET']);
        $app = new Application($config, $db, $request);
        $call = static fn(string $method, mixed ...$arguments): mixed => new ReflectionMethod($app, $method)->invoke($app, ...$arguments);
        $post = $call('newPost');
        $seed = static function (array $changes) use ($db, $config, $post): int {
            return $db->insert($config->dbposts, array_replace($post, ['timestamp' => 100, 'bumped' => 100, 'nameblock' => 'Anonymous', 'message' => 'Visible reply', 'message_source' => 'Visible reply'], $changes));
        };
        $hidden = $seed(['moderated' => 0, 'message' => 'Hidden opener']);
        $reply = $seed(['parent' => $hidden, 'message' => 'Reply beneath hidden thread']);
        foreach ([['preview' => (string) $reply], ['posts' => (string) $hidden, 'since' => '0']] as $query) {
            $hiddenApp = new Application($config, $db, new Request($query, [], [], ['PHP_SELF' => '/imgboard.php', 'REQUEST_METHOD' => 'GET']));
            $rejects(static function () use ($hiddenApp): void {
                ob_start();
                try {
                    new ReflectionMethod($hiddenApp, 'handle')->invoke($hiddenApp);
                } finally {
                    ob_end_clean();
                }
            }, "$driver hides replies when their parent thread is hidden", 404);
        }
        $formatted = '';
        try {
            $formatted = $call('formatMessage', 'See >>999999999999999999999999 for a hypothetical number.');
        } catch (Throwable) {
        }
        $check(str_contains($formatted, '999999999999999999999999'), "$driver treats oversized quote IDs as text");
        $admin = $db->row('accounts');
        $rejects(fn() => $call('updateAccount', array_replace($admin, ['username' => ''])), "$driver prevents an empty login name");
        $rejects(fn() => $call('updateAccount', array_replace($admin, ['role' => 99])), "$driver prevents disabling the last super-administrator");
        $db->insert('logs', ['timestamp' => 1, 'account' => $admin['id'], 'message' => 'Renamed account <img id="attack" src="x" onerror="alert(1)">']);
        $log = $call('manageModerationLog', 0);
        $document = Dom\HTMLDocument::createFromString('<!doctype html><body>' . $log, LIBXML_NOERROR);
        $check($document->querySelector('#attack') === null, "$driver moderation logs cannot execute stored account-name HTML");
        $visible = $seed(['message' => 'Visible opener']);
        file_put_contents('catalog.html', 'obsolete catalog');
        file_put_contents('catalog.json', 'obsolete JSON');
        file_put_contents('threads.json', 'obsolete JSON');
        file_put_contents('res/' . $visible . '.json', 'obsolete JSON');
        new Application($config->with(['catalog' => false, 'json' => false]), $db, $request)->maintain();
        $check(!file_exists('catalog.html') && !file_exists('catalog.json') && !file_exists('threads.json') && !file_exists('res/' . $visible . '.json'), "$driver rebuilding removes disabled public exports");
        $password = Passwords::hash('delete-these-posts');
        $first = $seed(['parent' => $visible, 'password' => $password]);
        $second = $seed(['parent' => $visible, 'password' => $password]);
        $deletion = new Application($config, $db, new Request(['delete' => ''], ['delete' => [(string) $first, (string) $second], 'password' => 'delete-these-posts'], [], ['PHP_SELF' => '/imgboard.php', 'REQUEST_METHOD' => 'POST']));
        try {
            ob_start();
            $deletion->run();
        } catch (BoardMessage $exception) {
            if ($exception->status !== 200) {
                throw $exception;
            }
        } finally {
            ob_end_clean();
        }
        $check($db->row($config->dbposts, Filter::equal('id', $first)) === [] && $db->row($config->dbposts, Filter::equal('id', $second)) === [] && $db->row($config->dbposts, Filter::equal('id', $visible)) !== [], "$driver public multi-delete removes each selected reply and preserves its parent");
        $first = $seed(['parent' => $visible, 'password' => $password]);
        $second = $seed(['parent' => $visible, 'password' => Passwords::hash('different-password')]);
        $deletion = new Application($config, $db, new Request(['delete' => ''], ['delete' => [(string) $first, (string) $second], 'password' => 'delete-these-posts'], [], ['PHP_SELF' => '/imgboard.php', 'REQUEST_METHOD' => 'POST']));
        $rejects(static function () use ($deletion): void {
            ob_start();
            try {
                $deletion->run();
            } finally {
                ob_end_clean();
            }
        }, "$driver multi-delete requires authorization for every selected post");
        $check($db->row($config->dbposts, Filter::equal('id', $first)) !== [] && $db->row($config->dbposts, Filter::equal('id', $second)) !== [], "$driver failed multi-delete preserves all selected records");
        unset($db, $app, $deletion, $hiddenApp, $call, $seed);
    }
} finally {
    chdir($applicationRoot);
    gc_collect_cycles();
    $remove($root);
}
echo $passed . ' security checks passed; ' . count($failed) . ' failed.' . PHP_EOL;
exit($failed === [] ? 0 : 1);
