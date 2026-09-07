<?php

declare(strict_types=1);

namespace Adelia;

final class BoardMessage extends \RuntimeException
{
    public function __construct(string $message, public readonly int $goBack = 1, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
