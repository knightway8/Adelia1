<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';
$values = require __DIR__ . '/fixtures/config.php';
$values['dbdriver'] = $argv[1] ?? throw new InvalidArgumentException('Missing driver.');
$values['dbpath'] = $argv[2] ?? throw new InvalidArgumentException('Missing path.');
$database = new Adelia\Database(new Adelia\Config(...$values));
$worker = $argv[3] ?? throw new InvalidArgumentException('Missing worker name.');
for ($index = 0; $index < 30; $index++) {
    if ($worker === 'reader') {
        $database->snapshot();
        usleep(2000);
        continue;
    }
    $id = $database->insert('accounts', ['username' => $worker . '-' . $index, 'password' => 'test-hash', 'role' => 3, 'lastactive' => 0]);
    $database->update('accounts', $id, ['lastactive' => 42]);
}
echo "Worker completed.\n";
