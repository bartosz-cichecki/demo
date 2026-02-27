<?php

declare(strict_types=1);

namespace App\User\Application\User\Command\LogInUserByEmail;

use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Email;

final readonly class LogInUserByEmailCommand implements CommandInterface
{
    public function __construct(
        public Email $email,
    ) {
    }
}
