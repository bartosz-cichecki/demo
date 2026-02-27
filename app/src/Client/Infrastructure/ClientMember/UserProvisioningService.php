<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\ClientMember;

use App\Client\Application\ClientMember\Port\UserProvisioningServiceInterface;
use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\User\Command\UpsertUserByEmail\UpsertUserByEmailCommand;
use App\User\Application\User\Query\UserQueryInterface;

final readonly class UserProvisioningService implements UserProvisioningServiceInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private UserQueryInterface $userQuery,
    ) {
    }

    public function ensureUserExists(string $email): Id
    {
        $normalized = Email::fromString($email);

        $this->commandBus->dispatch(new UpsertUserByEmailCommand((string) $normalized));

        $user = $this->userQuery->findByEmail($normalized);
        if (null === $user) {
            throw new \RuntimeException(\sprintf('User provisioning failed for email: %s', $email));
        }

        return new Id($user->id);
    }
}
