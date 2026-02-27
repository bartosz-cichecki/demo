<?php

declare(strict_types=1);

namespace App\SharedKernel\Domain\ValueObject;

final readonly class DateTime
{
    public function __construct(public \DateTimeImmutable $value)
    {
    }

    public static function now(): self
    {
        return new self(new \DateTimeImmutable());
    }
}
