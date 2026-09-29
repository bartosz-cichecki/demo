<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\UserAccount;

use App\Client\Application\UserAccount\Query\UserAccountQueryInterface;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\User\Query\UserQueryInterface;

final readonly class UserAccountQuery implements UserAccountQueryInterface
{
    public function __construct(
        private UserQueryInterface $userQuery,
    ) {
    }

    public function findEmailByUserId(Id $userId): ?Email
    {
        $user = $this->userQuery->findById($userId);

        return null === $user ? null : Email::fromString($user->email);
    }

    public function findUserIdByEmail(Email $email): ?Id
    {
        $user = $this->userQuery->findByEmail($email);

        return null === $user ? null : new Id($user->id);
    }
}
