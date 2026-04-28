<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Infrastructure\Doctrine\Type;

use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Infrastructure\Doctrine\Type\DateTimeType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class DateTimeTypeTest extends TestCase
{
    private DateTimeType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new DateTimeType();
        $this->platform = new PostgreSQLPlatform();
    }

    public function testWritesUtcStorageString(): void
    {
        $value = new DateTime(new \DateTimeImmutable('2024-01-15 10:30:00', new \DateTimeZone('Europe/Warsaw')));

        $databaseValue = $this->type->convertToDatabaseValue($value, $this->platform);

        $this->assertSame('2024-01-15 09:30:00', $databaseValue);
    }

    public function testWritesDateTimeImmutableAsUtcStorageString(): void
    {
        $value = new \DateTimeImmutable('2024-01-15 10:30:00', new \DateTimeZone('Europe/Warsaw'));

        $databaseValue = $this->type->convertToDatabaseValue($value, $this->platform);

        $this->assertSame('2024-01-15 09:30:00', $databaseValue);
    }

    public function testReadsStorageStringWithoutTimezoneAsUtc(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Warsaw');

        try {
            $value = $this->type->convertToPHPValue('2024-01-15 10:30:00', $this->platform);
        } finally {
            date_default_timezone_set($previousTimezone);
        }

        $this->assertInstanceOf(DateTime::class, $value);
        $this->assertSame('2024-01-15 10:30:00', $value->toStorageString());
        $this->assertSame('UTC', $value->value->getTimezone()->getName());
    }

    public function testReadsStorageStringWithTimezoneAsUtc(): void
    {
        $value = $this->type->convertToPHPValue('2024-01-15T10:30:00+01:00', $this->platform);

        $this->assertInstanceOf(DateTime::class, $value);
        $this->assertSame('2024-01-15 09:30:00', $value->toStorageString());
    }

    public function testReadsDateTimeImmutableAsUtc(): void
    {
        $value = $this->type->convertToPHPValue(
            new \DateTimeImmutable('2024-01-15 10:30:00', new \DateTimeZone('Europe/Warsaw')),
            $this->platform,
        );

        $this->assertInstanceOf(DateTime::class, $value);
        $this->assertSame('2024-01-15 09:30:00', $value->toStorageString());
    }

    public function testNullValuesRoundTrip(): void
    {
        $this->assertNull($this->type->convertToPHPValue(null, $this->platform));
        $this->assertNull($this->type->convertToDatabaseValue(null, $this->platform));
    }
}
