<?php

declare(strict_types=1);

namespace App\User\Application\User\Query;

use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\User\Query\Dto\UserDto;

interface UserQueryInterface
{
    public function findById(Id $id): ?UserDto;

    public function findByEmail(Email $email): ?UserDto;
}
