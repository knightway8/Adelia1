<?php

declare(strict_types=1);

use Adelia\BoardMessage;
use Adelia\Captcha;
use Adelia\Config;
use Adelia\Database;
use Adelia\Filter;
use Adelia\Schema;
use Adelia\Passwords;
use Adelia\Process;
use Adelia\Request;
use Adelia\Role;
use Adelia\Session;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

final class Checks
{
    public int $passed = 0;

    public function check(bool $condition, string $description): void
    {
        if (!$condition) {
            throw new RuntimeException('FAIL: ' . $description);
        }
        $this->passed++;
        echo 'PASS: ' . $description . PHP_EOL;
    }

    public function rejects(Closure $operation, string $exceptionClass, string $description): void
    {
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $this->check($exception instanceof $exceptionClass, $description);
            return;
        }
        $this->check(false, $description);
    }
}

$checks = new Checks();
$values = require __DIR__ . '/fixtures/config.php';
$values['tripseed'] = bin2hex(random_bytes(32));
$config = new Config(...$values);
$driver = $argv[1] ?? 'sqlite';
$temporary = sys_get_temp_dir() . '/.adelia-check-' . bin2hex(random_bytes(12)) . '.json';
register_shutdown_function(static function () use ($temporary): void {
    foreach ([$temporary, $temporary . '.lock', $temporary . '.head', $temporary . '.journal'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});
$config = $config->with(['dbdriver' => $driver, 'dbpath' => $driver === 'sqlite' ? ':memory:' : $temporary]);
$database = new Database($config, create: true);
$table = $config->dbaccounts;
$checks->check($database->count($table) === 0, 'A fresh storage schema is initialized');
$password = Passwords::hash('test-only-password');
$id = $database->insert($config->dbaccounts, ['username' => "test' OR 1=1 --", 'password' => $password, 'role' => Role::Moderator->value, 'lastactive' => 0]);
$row = $database->row($table, Filter::equal('id', $id));
$checks->check($row['id'] === $id && $row['role'] === 3, 'Stored rows retain native integer types');
$checks->check($row['username'] === "test' OR 1=1 --", 'SQL metacharacters are stored as data');
$checks->check($database->row($table, Filter::equal('username', "' OR 1=1 --")) === [], 'Prepared lookup cannot be changed by injected SQL');
$database->update($config->dbaccounts, $id, ['lastactive' => 123]);
$checks->check($database->row($table, Filter::equal('id', $id))['lastactive'] === 123, 'Prepared updates target the selected row');
$checks->rejects(fn(): string => Schema::identifier('accounts; DROP TABLE accounts'), InvalidArgumentException::class, 'Unsafe SQL identifiers are rejected');
$checks->check(Passwords::verify('test-only-password', $row['password']) && !Passwords::verify('wrong', $row['password']), 'Passwords are hashed and verified');
$checks->check(Role::Administrator->canAdminister() && !Role::Moderator->canAdminister(), 'Roles enforce administrator privileges');
$checks->check($config->with(['boardtitle' => 'Test board'])->title === 'Test board', 'Configuration cloning and the computed title work');
$checks->check(Request::integer('123') === 123 && Request::ids('1,2,3') === [1, 2, 3], 'Request identifiers normalize to integers');
foreach (['-1', '1.5', '1e5', '1 OR 1=1', ['1']] as $invalid) {
    $checks->rejects(fn(): int => Request::integer($invalid), BoardMessage::class, 'Malformed identifiers are rejected');
}
$_GET = [];
$_POST = ['name' => ['nested']];
$_FILES = [];
$checks->rejects(Request::capture(...), BoardMessage::class, 'Nested request fields are rejected');
$_POST = ['delete' => ['1', '2']];
$checks->check(Request::capture()->form['delete'] === ['1', '2'], 'Deletion lists remain supported');
$checks->check(strlen(Session::token()) === 64 && Session::token() === Session::token(), 'CSRF tokens are stable within a session');
$checks->check(str_contains(Session::decorate('<form method="post"></form>'), 'name="_csrf"'), 'Dynamic forms receive CSRF tokens');
$_SESSION['adeliacaptcha'] = 'abc23';
$_SESSION['adeliacaptcha_time'] = time();
Captcha::verify(' ABC23 ');
$checks->check(!isset($_SESSION['adeliacaptcha']), 'CAPTCHA normalization consumes the challenge');
$checks->rejects(static function (): void {
    Captcha::verify('abc23');
}, BoardMessage::class, 'A CAPTCHA cannot be replayed');
$_SESSION['adeliacaptcha'] = 'abc23';
$_SESSION['adeliacaptcha_time'] = time() - 601;
$checks->rejects(static function (): void {
    Captcha::verify('abc23');
}, BoardMessage::class, 'Expired CAPTCHAs are rejected');
$checks->check(trim(Process::run([PHP_BINARY, '-r', 'echo $argv[1];', 'a & b'])) === 'a & b', 'External program arguments do not use shell interpretation');
$checks->rejects(static fn(): string => Process::run([PHP_BINARY, '-r', 'exit(2);']), BoardMessage::class, 'External program failures are handled');

$schema = new Schema($config);
$postColumns = array_keys($schema->columns($config->dbposts));
$reportColumns = array_keys($schema->columns($config->dbreports));
$checks->check(!in_array('ip', $postColumns, true) && !in_array('ip', $reportColumns, true), 'Fresh post and report schemas contain no IP field');
$checks->check(!isset($database->snapshot()['tables']['bans']), 'Fresh databases have no retired ban table');
$_SERVER['REMOTE_ADDR'] = '203.0.113.18';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.19';
$checks->check(!isset(Request::capture()->server['REMOTE_ADDR']) && !isset(Request::capture()->server['HTTP_CF_CONNECTING_IP']), 'Request capture does not retain poster network addresses');
$testApp = new Adelia\Application($config->with(['spoilertext' => true]), $database, new Request([], [], [], ['PHP_SELF' => 'imgboard.php', 'REQUEST_METHOD' => 'GET']));
$call = static fn(string $method, mixed ...$arguments): mixed => new ReflectionMethod($testApp, $method)->invoke($testApp, ...$arguments);
$formatted = $call('formatMessage', "<script>alert(1)</script>\n>quote\n<spoiler>move</spoiler>");
$checks->check(!str_contains($formatted, '<script>') && str_contains($formatted, '&lt;script&gt;'), 'The shared post formatter escapes executable markup');
$checks->check(str_contains($formatted, 'class="quote unkfunc"') && str_contains($formatted, 'class="spoiler"'), 'The formatter retains quote and spoiler formatting');
$post = $call('newPost');
$post['message_source'] = "Original <text>\n\nwith spacing";
$checks->check($call('editableMessage', $post) === $post['message_source'], 'The editor preserves source text without an HTML round trip');
$post['source_known'] = 0;
$post['message'] = 'Older<br><span class="spoiler">move</span><script>unsafe()</script>';
$checks->check($call('editableMessage', $post) === "Older\n<spoiler>move</spoiler>", 'Older rendered posts are read inertly as editable text');
$checks->rejects(fn(): mixed => $call('requireModerator'), BoardMessage::class, 'The editor guard rejects an anonymous account');
new ReflectionProperty($testApp, 'loggedin')->setValue($testApp, true);
new ReflectionProperty($testApp, 'account')->setValue($testApp, ['role' => Role::Disabled->value]);
$checks->rejects(fn(): mixed => $call('requireModerator'), BoardMessage::class, 'The editor guard rejects a disabled account');
new ReflectionProperty($testApp, 'account')->setValue($testApp, ['role' => Role::Moderator->value]);
$checks->check($call('requireModerator') === null, 'The editor guard permits the moderator role');
$revision = $call('postRevision', $post);
$post['subject'] = 'A concurrent change';
$checks->check($revision !== $call('postRevision', $post), 'Editor revision tokens change with post content');

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
$phpFiles = 0;
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    if (!preg_match('/^<\?php\s+declare\(strict_types=1\);/', $source)) {
        throw new RuntimeException('Missing strict types: ' . $file->getPathname());
    }
    $phpFiles++;
}
$checks->check($phpFiles > 15, 'Every PHP file declares strict types');
$methods = 0;
foreach (glob(dirname(__DIR__) . '/app/*.php') ?: [] as $file) {
    $name = 'Adelia\\' . basename($file, '.php');
    if (!class_exists($name) && !trait_exists($name) && !enum_exists($name) && !interface_exists($name)) {
        throw new RuntimeException('Cannot load ' . $name);
    }
    foreach (new ReflectionClass($name)->getMethods() as $method) {
        if (!str_starts_with($method->getDeclaringClass()->getName(), 'Adelia\\')) {
            continue;
        }
        foreach ($method->getParameters() as $parameter) {
            if (!$parameter->hasType()) {
                throw new RuntimeException('Untyped parameter: ' . $name . '::' . $method->name);
            }
        }
        if (!$method->isConstructor() && !$method->isDestructor() && !$method->hasReturnType()) {
            throw new RuntimeException('Untyped return: ' . $name . '::' . $method->name);
        }
        $methods++;
    }
}
$checks->check($methods > 100, 'All application methods have native parameter and return types');
echo $driver . ': ' . $checks->passed . ' checks passed; ' . $phpFiles . ' PHP files audited.' . PHP_EOL;
