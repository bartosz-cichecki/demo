<?php

declare(strict_types=1);

namespace App\SharedKernel\Domain\Clock;

use App\SharedKernel\Domain\ValueObject\DateTime;

interface ClockInterface
{
    public function now(): DateTime;
}
