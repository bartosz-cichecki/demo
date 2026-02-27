<?php

declare(strict_types=1);

namespace App\SharedKernel\Domain\ValueObject;

use Ramsey\Uuid\Uuid;

final readonly class Id
{
    private string $value;

    public function __construct(string $value)
    {
        $v = trim($value);
        if ('' === $v || !Uuid::isValid($v)) {
            throw new \InvalidArgumentException('Id must be a valid UUID.');
        }
        $this->value = $v;
    }

    public static function new(): self
    {
        return new self(Uuid::uuid7()->toString());
    }

    public function equals(self $other): bool
    {
        return $this->value === (string) $other;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
