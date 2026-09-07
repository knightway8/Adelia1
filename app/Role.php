<?php

declare(strict_types=1);

namespace Adelia;

enum Role: int
{
    case SuperAdministrator = 1;
    case Administrator = 2;
    case Moderator = 3;
    case Disabled = 99;

    public function canAdminister(): bool
    {
        return match ($this) {
            self::SuperAdministrator, self::Administrator => true,
            default => false,
        };
    }
}
