<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine\Type;

use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class IdType extends Type
{
    public const string NAME = 'domain_id';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Id
    {
        if (null === $value) {
            return null;
        }

        \assert(\is_string($value));

        return new Id($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        \assert($value instanceof Id);

        return (string) $value;
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
