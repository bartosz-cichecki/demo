<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Domain\ValueObject;

use App\SharedKernel\Domain\ValueObject\DateTime;
use PHPUnit\Framework\TestCase;

final class DateTimeTest extends TestCase
{
    public function testCanBeCreatedFromDateTimeImmutable(): void
    {
        // Given: a DateTimeImmutable
        $inner = new \DateTimeImmutable('2024-01-15 10:30:00');

        // When: creating DateTime
        $dateTime = new DateTime($inner);

        // Then: value is preserved
        $this->assertSame($inner, $dateTime->value);
    }

    public function testNowReturnsInstance(): void
    {
        // When: calling now()
        $dateTime = DateTime::now();

        // Then: returns DateTime with current time
        $this->assertInstanceOf(DateTime::class, $dateTime);
        $this->assertInstanceOf(\DateTimeImmutable::class, $dateTime->value);
    }
}
