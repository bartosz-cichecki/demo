<?php

declare(strict_types=1);

namespace App\SharedKernel\Domain\Clock;

use App\SharedKernel\Domain\ValueObject\DateTime;

final class MutableClock implements ClockInterface
{
    private DateTime $currentTime;

    public function __construct(?DateTime $currentTime = null)
    {
        $this->currentTime = $currentTime ?? DateTime::now();
    }

    public function now(): DateTime
    {
        return $this->currentTime;
    }

    public function set(DateTime|\DateTimeImmutable $currentTime): void
    {
        $this->currentTime = $currentTime instanceof DateTime ? $currentTime : new DateTime($currentTime);
    }

    public function modify(string $modifier): void
    {
        $this->currentTime = new DateTime($this->currentTime->value->modify($modifier));
    }
}
