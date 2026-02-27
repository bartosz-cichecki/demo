<?php

declare(strict_types=1);

namespace App\User\Domain\User\Factory;

use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Domain\User\Outside\UserOutsideInterface;
use App\User\Domain\User\User;

final readonly class UserFactory implements UserFactoryInterface
{
    public function __construct(
        private UserOutsideInterface $userOutside,
    ) {
    }

    public function create(
        Id $id,
        Email $email,
    ): User {
        return new User(
            $this->userOutside,
            $id,
            $email,
        );
    }
}
