<?php

declare(strict_types=1);

namespace App\User\Application\User\Command\BlockUser;

use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class BlockUserCommand implements CommandInterface
{
    public function __construct(
        public Id $id,
        public string $reason,
    ) {
    }
}
