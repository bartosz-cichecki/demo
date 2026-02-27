<?php

declare(strict_types=1);

namespace App\Tests\Behat\Support\Fixture;

use App\SharedKernel\Domain\ValueObject\Id;

final readonly class ClientFixture
{
    public function __construct(
        public Id $id,
        public string $name,
        public ?string $description = null,
    ) {
    }

    public function id(): string
    {
        return (string) $this->id;
    }
}
