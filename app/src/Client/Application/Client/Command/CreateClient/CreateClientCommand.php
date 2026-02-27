<?php

declare(strict_types=1);

namespace App\Client\Application\Client\Command\CreateClient;

use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class CreateClientCommand implements CommandInterface
{
    public function __construct(
        public Id $id,
        public string $name,
        public ?string $description,
    ) {
    }
}
