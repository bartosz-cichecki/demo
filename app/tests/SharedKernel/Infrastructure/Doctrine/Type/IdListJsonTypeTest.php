<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Infrastructure\Doctrine\Type;

use App\SharedKernel\Domain\ValueObject\Id;
use App\SharedKernel\Infrastructure\Doctrine\Type\IdListJsonType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use PHPUnit\Framework\TestCase;

final class IdListJsonTypeTest extends TestCase
{
    private IdListJsonType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new IdListJsonType();
        $this->platform = new PostgreSQLPlatform();
    }

    public function testRoundTrip(): void
    {
        $id1 = Id::new();
        $id2 = Id::new();
        $original = [$id1, $id2];

        $dbValue = $this->type->convertToDatabaseValue($original, $this->platform);
        $phpValue = $this->type->convertToPHPValue($dbValue, $this->platform);

        $this->assertCount(2, $phpValue);
        $this->assertInstanceOf(Id::class, $phpValue[0]);
        $this->assertInstanceOf(Id::class, $phpValue[1]);

        $resultStrings = array_map(static fn (Id $id): string => (string) $id, $phpValue);
        $this->assertContains((string) $id1, $resultStrings);
        $this->assertContains((string) $id2, $resultStrings);
    }

    public function testEmptyArrayRoundTrip(): void
    {
        $dbValue = $this->type->convertToDatabaseValue([], $this->platform);
        $phpValue = $this->type->convertToPHPValue($dbValue, $this->platform);

        $this->assertSame([], $phpValue);
    }

    public function testNullDatabaseValueReturnsEmptyArray(): void
    {
        $phpValue = $this->type->convertToPHPValue(null, $this->platform);

        $this->assertSame([], $phpValue);
    }

    public function testNullPhpValueReturnsNull(): void
    {
        $dbValue = $this->type->convertToDatabaseValue(null, $this->platform);

        $this->assertNull($dbValue);
    }

    public function testGetName(): void
    {
        $this->assertSame('domain_id_list_json', $this->type->getName());
    }

    public function testConvertToPHPValueAcceptsArrayFromDriver(): void
    {
        $id1 = Id::new();
        $id2 = Id::new();
        $raw = [(string) $id1, (string) $id2];

        $result = $this->type->convertToPHPValue($raw, $this->platform);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(Id::class, $result[0]);
        $this->assertInstanceOf(Id::class, $result[1]);

        $resultStrings = array_map(static fn (Id $id): string => (string) $id, $result);
        $this->assertContains((string) $id1, $resultStrings);
        $this->assertContains((string) $id2, $resultStrings);
    }

    public function testEmptyStringReturnsEmptyArray(): void
    {
        $result = $this->type->convertToPHPValue('', $this->platform);

        $this->assertSame([], $result);
    }

    public function testJsonObjectThrows(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToPHPValue('{"a":"' . Id::new() . '"}', $this->platform);
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToPHPValue('not-json', $this->platform);
    }

    public function testNonStringElementsInJsonStringThrow(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToPHPValue('[123]', $this->platform);
    }

    public function testNonStringElementsInArrayThrow(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToPHPValue([123], $this->platform);
    }

    public function testInvalidTypeThrows(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToPHPValue(42, $this->platform);
    }

    public function testNonArrayPhpValueThrowsOnWrite(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToDatabaseValue('not-an-array', $this->platform);
    }
}
