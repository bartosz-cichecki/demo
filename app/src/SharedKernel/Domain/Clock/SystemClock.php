<?php

declare(strict_types=1);

namespace App\SharedKernel\Domain\Clock;

use App\SharedKernel\Domain\ValueObject\DateTime;

final class SystemClock implements ClockInterface
{
    public function now(): DateTime
    {
        return DateTime::now();
    }
}
