<?php

declare(strict_types=1);

namespace App\SharedKernel\Domain\ValueObject;

final readonly class DateTime
{
    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    public \DateTimeImmutable $value;

    public function __construct(\DateTimeImmutable $value)
    {
        $this->value = self::normalizeToUtc($value);
    }

    public static function now(): self
    {
        return new self(new \DateTimeImmutable('now', self::utc()));
    }

    public static function fromStorageString(string $value): self
    {
        return new self(new \DateTimeImmutable($value, self::utc()));
    }

    public function toStorageString(): string
    {
        return $this->value->format(self::STORAGE_FORMAT);
    }

    private static function normalizeToUtc(\DateTimeImmutable $value): \DateTimeImmutable
    {
        if ('UTC' === $value->getTimezone()->getName()) {
            return $value;
        }

        return $value->setTimezone(self::utc());
    }

    private static function utc(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
}
