<?php

declare(strict_types=1);

namespace App\User\Application\User\Command\LogInUserByEmail;

use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Domain\User\Factory\UserFactoryInterface;
use App\User\Domain\User\Repository\UserRepositoryInterface;

final readonly class LogInUserByEmailCommandHandler
{
    public function __construct(
        private UserFactoryInterface $userFactory,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    public function __invoke(LogInUserByEmailCommand $command): void
    {
        $email = $command->email;

        $user = $this->userRepository->findByEmail($email);
        if (null === $user) {
            $user = $this->userFactory->create(Id::new(), $email);
            $this->userRepository->create($user);
        }

        $user->markLoggedIn();
    }
}
