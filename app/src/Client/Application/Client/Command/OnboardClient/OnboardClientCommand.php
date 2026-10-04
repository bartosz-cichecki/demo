<?php

declare(strict_types=1);

namespace App\Client\Application\Client\Command\OnboardClient;

use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class OnboardClientCommand implements CommandInterface
{
    public function __construct(
        public Id $id,
        public string $name,
        public ?string $description,
        public Email $adminEmail,
    ) {
    }
}
