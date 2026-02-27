<?php

declare(strict_types=1);

namespace App\User\Domain\User\Repository;

use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Domain\User\Repository\Exception\UserDoesNotExistException;
use App\User\Domain\User\User;

interface UserRepositoryInterface
{
    public function create(User $user): void;

    /**
     * @throws UserDoesNotExistException
     */
    public function get(Id $id): User;

    public function findByEmail(Email $email): ?User;
}
