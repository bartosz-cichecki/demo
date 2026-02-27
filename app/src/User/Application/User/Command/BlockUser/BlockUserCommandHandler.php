<?php

declare(strict_types=1);

namespace App\User\Application\User\Command\BlockUser;

use App\User\Domain\User\Repository\UserRepositoryInterface;

final readonly class BlockUserCommandHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {
    }

    public function __invoke(BlockUserCommand $command): void
    {
        $user = $this->userRepository->get($command->id);
        $user->block($command->reason);
    }
}
