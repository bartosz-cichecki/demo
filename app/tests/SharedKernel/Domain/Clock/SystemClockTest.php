<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Domain\Clock;

use App\SharedKernel\Domain\Clock\SystemClock;
use App\SharedKernel\Domain\ValueObject\DateTime;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    public function testReturnsCurrentTimeInUtc(): void
    {
        $clock = new SystemClock();

        $now = $clock->now();

        $this->assertInstanceOf(DateTime::class, $now);
        $this->assertSame('UTC', $now->value->getTimezone()->getName());
    }
}
