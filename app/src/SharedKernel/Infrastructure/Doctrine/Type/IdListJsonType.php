<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine\Type;

use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

final class IdListJsonType extends Type
{
    public const string NAME = 'domain_id_list_json';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonTypeDeclarationSQL($column);
    }

    /**
     * @return array<Id>
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): array
    {
        if (null === $value || '' === $value) {
            return [];
        }

        if (\is_array($value)) {
            $decoded = $value;
        } elseif (\is_string($value)) {
            $decoded = json_decode($value, true);
            if (\JSON_ERROR_NONE !== json_last_error()) {
                throw new ConversionException(\sprintf('Could not convert database value to "%s": invalid JSON (%s).', self::NAME, json_last_error_msg()));
            }
        } else {
            throw new ConversionException(\sprintf('Could not convert database value of type "%s" to "%s": expected string or array.', get_debug_type($value), self::NAME));
        }

        if (!\is_array($decoded)) {
            throw new ConversionException(\sprintf('Could not convert database value to "%s": decoded value is not an array.', self::NAME));
        }

        if (!array_is_list($decoded)) {
            throw new ConversionException(\sprintf('Could not convert database value to "%s": expected a JSON list, got a JSON object.', self::NAME));
        }

        return array_map(static function (mixed $element): Id {
            if (!\is_string($element)) {
                throw new ConversionException(\sprintf('Could not convert database value to "%s": each element must be a string, got "%s".', self::NAME, get_debug_type($element)));
            }

            return new Id($element);
        }, $decoded);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_array($value)) {
            throw new ConversionException(\sprintf('Could not convert PHP value of type "%s" to "%s": expected array.', get_debug_type($value), self::NAME));
        }

        /** @var array<Id> $value */
        $encoded = json_encode(array_map(static fn (Id $id): string => (string) $id, $value));

        if (false === $encoded) {
            throw new ConversionException(\sprintf('Could not convert PHP value to "%s": JSON encoding failed (%s).', self::NAME, json_last_error_msg()));
        }

        return $encoded;
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
