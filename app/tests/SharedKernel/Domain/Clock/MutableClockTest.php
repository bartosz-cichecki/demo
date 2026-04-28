<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Domain\Clock;

use App\SharedKernel\Domain\Clock\MutableClock;
use App\SharedKernel\Domain\ValueObject\DateTime;
use PHPUnit\Framework\TestCase;

final class MutableClockTest extends TestCase
{
    public function testCanBeSetDeterministically(): void
    {
        $clock = new MutableClock();

        $clock->set(DateTime::fromStorageString('2024-01-15 10:30:00'));

        $this->assertSame('2024-01-15 10:30:00', $clock->now()->toStorageString());
    }

    public function testCanBeMovedDeterministically(): void
    {
        $clock = new MutableClock(DateTime::fromStorageString('2024-01-15 10:30:00'));

        $clock->modify('+90 minutes');

        $this->assertSame('2024-01-15 12:00:00', $clock->now()->toStorageString());
    }

    public function testSetNormalizesDateTimeImmutableToUtc(): void
    {
        $clock = new MutableClock();

        $clock->set(new \DateTimeImmutable('2024-01-15 10:30:00', new \DateTimeZone('Europe/Warsaw')));

        $this->assertSame('2024-01-15 09:30:00', $clock->now()->toStorageString());
    }
}
