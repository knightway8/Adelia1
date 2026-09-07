<?php

declare(strict_types=1);

namespace Adelia;

final readonly class BoardLock
{
    private StorageLock $lock;

    public function __construct(string $filename)
    {
        $this->lock = new StorageLock($filename);
    }

    public function __destruct()
    {
        $this->lock->release();
    }
}
