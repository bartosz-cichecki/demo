<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine\Type;

use App\SharedKernel\Domain\ValueObject\DateTime;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class DateTimeType extends Type
{
    public const string NAME = 'domain_datetime';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTimeTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTime
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return new DateTime($value);
        }

        \assert(\is_string($value));

        return new DateTime(new \DateTimeImmutable($value));
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        \assert($value instanceof DateTime);

        return $value->value->format('Y-m-d H:i:s');
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
