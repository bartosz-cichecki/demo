<?php

declare(strict_types=1);

namespace App\User\Domain\User\Factory;

use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Domain\User\User;

interface UserFactoryInterface
{
    public function create(
        Id $id,
        Email $email,
    ): User;
}
