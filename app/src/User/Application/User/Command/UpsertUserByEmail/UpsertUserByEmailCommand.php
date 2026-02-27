<?php

declare(strict_types=1);

namespace App\User\Application\User\Command\UpsertUserByEmail;

use App\SharedKernel\Application\CommandBus\CommandInterface;

final readonly class UpsertUserByEmailCommand implements CommandInterface
{
    public function __construct(
        public string $email,
    ) {
    }
}
