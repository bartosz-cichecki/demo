<?php

declare(strict_types=1);

namespace App\Client\Application\ClientInvitation\Command\RevokeClientAdminInvitation;

use App\Client\Domain\ClientInvitation\Exception\ClientInvitationNotPendingException;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationRoleNotAllowedException;
use App\Client\Domain\ClientInvitation\Repository\ClientInvitationRepositoryInterface;
use App\Client\Domain\ClientInvitation\Repository\Exception\PendingClientInvitationDoesNotExistException;

final readonly class RevokeClientAdminInvitationCommandHandler
{
    public function __construct(
        private ClientInvitationRepositoryInterface $invitationRepository,
    ) {
    }

    /**
     * @throws PendingClientInvitationDoesNotExistException
     * @throws ClientInvitationRoleNotAllowedException
     * @throws ClientInvitationNotPendingException
     */
    public function __invoke(RevokeClientAdminInvitationCommand $command): void
    {
        $invitation = $this->invitationRepository->getPendingForClientAndEmail($command->clientId, $command->email);
        $invitation->revokeByPlatformAdmin();
    }
}
