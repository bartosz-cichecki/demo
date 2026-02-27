<?php

declare(strict_types=1);

namespace App\SharedKernel\Domain\ValueObject;

final readonly class Email
{
    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $raw): self
    {
        $value = mb_strtolower(trim($raw));
        if ('' === $value || false === filter_var($value, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email must be a valid address.');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
