<?php

declare(strict_types=1);

namespace App\User\Application\User\Command\UnblockUser;

use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class UnblockUserCommand implements CommandInterface
{
    public function __construct(
        public Id $id,
    ) {
    }
}
