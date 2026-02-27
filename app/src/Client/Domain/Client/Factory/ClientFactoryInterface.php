<?php

declare(strict_types=1);

namespace App\Client\Domain\Client\Factory;

use App\Client\Domain\Client\Client;
use App\SharedKernel\Domain\ValueObject\Id;

interface ClientFactoryInterface
{
    public function create(
        Id $id,
        string $name,
        ?string $description,
    ): Client;
}
