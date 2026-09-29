<?php

declare(strict_types=1);

namespace App\Client\Application\ClientInvitation\Command\RevokeClientInvitation;

use App\Client\Domain\ClientInvitation\Exception\ClientInvitationNotPendingException;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationRoleNotAllowedException;
use App\Client\Domain\ClientInvitation\Repository\ClientInvitationRepositoryInterface;
use App\Client\Domain\ClientInvitation\Repository\Exception\ClientInvitationDoesNotExistException;

final readonly class RevokeClientInvitationCommandHandler
{
    public function __construct(
        private ClientInvitationRepositoryInterface $clientInvitationRepository,
    ) {
    }

    /**
     * @throws ClientInvitationDoesNotExistException
     * @throws ClientInvitationRoleNotAllowedException
     * @throws ClientInvitationNotPendingException
     */
    public function __invoke(RevokeClientInvitationCommand $command): void
    {
        $invitation = $this->clientInvitationRepository->getForClient($command->invitationId, $command->clientId);
        $invitation->revokeByClientAdmin();
    }
}
