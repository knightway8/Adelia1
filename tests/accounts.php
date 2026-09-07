<?php

declare(strict_types=1);

use Adelia\Application;
use Adelia\Config;
use Adelia\Database;
use Adelia\Filter;
use Adelia\Passwords;
use Adelia\Request;
use Adelia\Tripcodes;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';
$passed = 0;
$check = static function (bool $ok, string $message) use (&$passed): void {
    if (!$ok) {
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
$values = require __DIR__ . '/fixtures/config.php';
$check(!array_key_exists('adminpass', $values) && !array_key_exists('modpass', $values), 'Settings contain no account password options');
$values['tripseed'] = 'account-tests-only-key';
$config = new Config(...$values);
$folder = sys_get_temp_dir() . '/adelia-accounts-' . bin2hex(random_bytes(12));
mkdir($folder, 0o700);
try {
    foreach (['sqlite', 'flatfile'] as $driver) {
        $cfg = $config->with(['dbdriver' => $driver, 'dbpath' => $folder . '/.test.' . ($driver === 'sqlite' ? 'db' : 'json')]);
        $db = new Database($cfg, create: true);
        $app = new Application($cfg, $db, new Request([], [], [], ['REQUEST_METHOD' => 'GET', 'PHP_SELF' => 'imgboard.php']));
        $initialize = new ReflectionMethod($app, 'initializeAccounts');
        $initialize->invoke($app);
        $account = $db->row('accounts');
        $check($db->count('accounts') === 1 && $account['username'] === 'admin' && Passwords::verify('password', $account['password']), "$driver initializes admin / password once");
        $check($account['password'] !== 'password', "$driver stores a salted password hash");
        $db->update('accounts', $account['id'], ['password' => Passwords::hash('Changed-test-passphrase-783')]);
        $initialize->invoke($app);
        $check($db->count('accounts') === 1 && Passwords::verify('Changed-test-passphrase-783', $db->row('accounts')['password']) && !Passwords::verify('password', $db->row('accounts')['password']), "$driver startup never resets the changed password");
        $db->update('accounts', $account['id'], ['username' => 'newadmin']);
        $initialize->invoke($app);
        $check($db->row('accounts', Filter::equal('username', 'admin')) === [], "$driver startup never recreates a renamed admin");
        unset($initialize, $app, $db);
    }
    foreach (['password', 'short', str_repeat('x', 65), str_repeat('♞', 30), "long-password\0value"] as $password) {
        $reject(fn() => Passwords::validateAccountPassword($password), 'An invalid new account password is rejected');
    }
    Passwords::validateAccountPassword('A long chess passphrase ♞');
    $check(true, 'A Unicode passphrase is accepted');
    $key = 'tripcode-test-private-key';
    $check(Tripcodes::split('Ada', $key) === ['Ada', ''], 'A plain name stays a plain name');
    $legacy = Tripcodes::split('Ada#old-secret', $key);
    $check($legacy === ['Ada', substr(strtr(base64_encode(hash_hmac('sha256', 'old-secret', $key, true)), '+/', '-_'), 0, 12)], 'Existing single-hash tripcodes keep their original identity');
    $check(Tripcodes::split('Ada!old-secret', $key) === $legacy, 'Existing exclamation syntax remains equivalent');
    $secure = Tripcodes::split('Ada##private-chess-secret', $key);
    $check($secure[0] === 'Ada' && preg_match('/^![a-zA-Z0-9_-]{22}$/D', $secure[1]) === 1, 'Double-hash syntax creates a 128-bit secure tripcode');
    $check(Tripcodes::split('Other name##private-chess-secret', $key)[1] === $secure[1], 'Changing only the display name preserves the secure identity');
    $check(Tripcodes::split('Ada##different-secret', $key)[1] !== $secure[1], 'Different secrets produce different secure identities');
    $check(Tripcodes::split('Ada##private-chess-secret', 'another-board-key')[1] !== $secure[1], 'The board private key separates identities between boards');
    $check(Tripcodes::split('Ada#private-chess-secret', $key)[1] !== substr($secure[1], 1), 'Secure and existing tripcode modes are distinct');
    $check(Tripcodes::split('Ada##♞-秘密', $key) === Tripcodes::split('Ada##♞-秘密', $key), 'Unicode secrets produce stable secure identities');
    $reject(fn() => Tripcodes::split('Ada##', $key), 'Secure tripcodes reject an empty secret');
    $reject(fn() => Tripcodes::split('Ada##secret', ''), 'Tripcodes require a private board key');
    $reject(fn() => Tripcodes::split(str_repeat('x', 4097), $key), 'Oversized name input is rejected');
    $reject(fn() => Tripcodes::split("Ada##\xff", $key), 'Invalid UTF-8 is rejected');
    $check(!str_contains(json_encode($secure, JSON_THROW_ON_ERROR), 'private-chess-secret'), 'Tripcode results contain no raw secret');
} finally {
    foreach (new DirectoryIterator($folder) as $file) {
        if ($file->isFile()) {
            unlink($file->getPathname());
        }
    }
    rmdir($folder);
}
echo "$passed account/tripcode checks passed." . PHP_EOL;
