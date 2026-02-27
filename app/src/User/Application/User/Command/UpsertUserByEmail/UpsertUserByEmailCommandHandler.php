<?php

declare(strict_types=1);

namespace App\User\Application\User\Command\UpsertUserByEmail;

use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Domain\User\Factory\UserFactoryInterface;
use App\User\Domain\User\Repository\UserRepositoryInterface;

final readonly class UpsertUserByEmailCommandHandler
{
    public function __construct(
        private UserFactoryInterface $userFactory,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    public function __invoke(UpsertUserByEmailCommand $command): void
    {
        $email = Email::fromString($command->email);

        $existingUser = $this->userRepository->findByEmail($email);
        if (null !== $existingUser) {
            return;
        }

        $user = $this->userFactory->create(
            Id::new(),
            $email,
        );
        $this->userRepository->create($user);
    }
}
