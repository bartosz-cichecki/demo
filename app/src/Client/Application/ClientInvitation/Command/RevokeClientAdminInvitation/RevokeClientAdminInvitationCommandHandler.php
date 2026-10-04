<?php

declare(strict_types=1);

namespace App\Client\Application\ClientInvitation\Command\RevokeClientAdminInvitation;

use App\Client\Application\ClientInvitation\Query\ClientInvitationQueryInterface;
use App\Client\Domain\ClientInvitation\Repository\ClientInvitationRepositoryInterface;
use App\Client\Domain\ClientInvitation\Repository\Exception\PendingClientInvitationDoesNotExistException;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class RevokeClientAdminInvitationCommandHandler
{
    public function __construct(
        private ClientInvitationQueryInterface $invitationQuery,
        private ClientInvitationRepositoryInterface $invitationRepository,
    ) {
    }

    public function __invoke(RevokeClientAdminInvitationCommand $command): void
    {
        $pending = $this->invitationQuery->findPendingByClientAndEmail($command->clientId, $command->email);
        if (null === $pending) {
            throw new PendingClientInvitationDoesNotExistException($command->clientId);
        }
        $this->invitationRepository->getForClient(new Id($pending->id), $command->clientId)->revokeByPlatformAdmin();
    }
}
