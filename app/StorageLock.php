<?php

declare(strict_types=1);

namespace Adelia;

final class StorageLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(string $path, int $mode = LOCK_EX, float $timeout = 10.0)
    {
        if (is_link($path)) {
            throw new \RuntimeException('A storage lock cannot be a symbolic link.');
        }
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the storage lock.');
        }
        try {
            if (PHP_OS_FAMILY !== 'Windows' && !chmod($path, 0o600)) {
                throw new \RuntimeException('Cannot protect the storage lock.');
            }
            $deadline = hrtime(true) + (int) ($timeout * 1e9);
            do {
                $blocked = 0;
                if (flock($handle, $mode | LOCK_NB, $blocked)) {
                    $this->handle = $handle;
                    return;
                }
                if (!$blocked) {
                    throw new \RuntimeException('The filesystem does not support the required lock.');
                }
                usleep(10_000);
            } while (hrtime(true) < $deadline);
            throw new BoardMessage('Storage is busy. Please try again shortly.', status: 503);
        } catch (\Throwable $exception) {
            fclose($handle);
            throw $exception;
        }
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
