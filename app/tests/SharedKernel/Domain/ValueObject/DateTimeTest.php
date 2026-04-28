<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Domain\ValueObject;

use App\SharedKernel\Domain\ValueObject\DateTime;
use PHPUnit\Framework\TestCase;

final class DateTimeTest extends TestCase
{
    public function testCanBeCreatedFromDateTimeImmutable(): void
    {
        $inner = new \DateTimeImmutable('2024-01-15 10:30:00', new \DateTimeZone('UTC'));

        $dateTime = new DateTime($inner);

        $this->assertSame($inner, $dateTime->value);
    }

    public function testNowReturnsInstance(): void
    {
        $dateTime = DateTime::now();

        $this->assertInstanceOf(DateTime::class, $dateTime);
        $this->assertInstanceOf(\DateTimeImmutable::class, $dateTime->value);
        $this->assertSame('UTC', $dateTime->value->getTimezone()->getName());
    }

    public function testNowIsIndependentFromDefaultTimezone(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Warsaw');

        try {
            $dateTime = DateTime::now();
        } finally {
            date_default_timezone_set($previousTimezone);
        }

        $this->assertSame('UTC', $dateTime->value->getTimezone()->getName());
    }

    public function testStorageStringWithoutTimezoneIsInterpretedAsUtc(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Warsaw');

        try {
            $dateTime = DateTime::fromStorageString('2024-01-15 10:30:00');
        } finally {
            date_default_timezone_set($previousTimezone);
        }

        $this->assertSame('2024-01-15 10:30:00', $dateTime->toStorageString());
        $this->assertSame('UTC', $dateTime->value->getTimezone()->getName());
    }

    public function testInputWithDifferentTimezoneIsNormalizedToUtc(): void
    {
        $inner = new \DateTimeImmutable('2024-01-15 10:30:00', new \DateTimeZone('Europe/Warsaw'));

        $dateTime = new DateTime($inner);

        $this->assertSame('2024-01-15 09:30:00', $dateTime->toStorageString());
        $this->assertSame('UTC', $dateTime->value->getTimezone()->getName());
    }

    public function testStorageStringWithTimezoneIsNormalizedToUtc(): void
    {
        $dateTime = DateTime::fromStorageString('2024-01-15T10:30:00+01:00');

        $this->assertSame('2024-01-15 09:30:00', $dateTime->toStorageString());
    }
}
