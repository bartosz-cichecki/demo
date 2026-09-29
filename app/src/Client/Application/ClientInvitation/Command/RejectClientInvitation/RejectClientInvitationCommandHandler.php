<?php

declare(strict_types=1);

namespace App\Client\Application\ClientInvitation\Command\RejectClientInvitation;

use App\Client\Domain\ClientInvitation\Exception\ClientInvitationNotAddressedToUserException;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationNotPendingException;
use App\Client\Domain\ClientInvitation\Repository\ClientInvitationRepositoryInterface;
use App\Client\Domain\ClientInvitation\Repository\Exception\ClientInvitationDoesNotExistException;

final readonly class RejectClientInvitationCommandHandler
{
    public function __construct(
        private ClientInvitationRepositoryInterface $clientInvitationRepository,
    ) {
    }

    /**
     * @throws ClientInvitationDoesNotExistException
     * @throws ClientInvitationNotAddressedToUserException
     * @throws ClientInvitationNotPendingException
     */
    public function __invoke(RejectClientInvitationCommand $command): void
    {
        $invitation = $this->clientInvitationRepository->get($command->invitationId);
        $invitation->reject($command->userId);
    }
}
