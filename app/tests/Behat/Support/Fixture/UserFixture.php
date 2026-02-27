<?php

declare(strict_types=1);

namespace App\Tests\Behat\Support\Fixture;

use App\SharedKernel\Domain\ValueObject\Id;

final readonly class UserFixture
{
    public function __construct(
        public Id $id,
        public string $email,
    ) {
    }

    public function id(): string
    {
        return (string) $this->id;
    }
}
