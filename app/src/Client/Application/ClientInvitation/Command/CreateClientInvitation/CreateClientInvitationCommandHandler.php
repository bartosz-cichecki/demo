<?php

declare(strict_types=1);

namespace App\Client\Application\ClientInvitation\Command\CreateClientInvitation;

use App\Client\Domain\ClientInvitation\Exception\ClientInvitationRoleNotAllowedException;
use App\Client\Domain\ClientInvitation\Exception\InviteeAlreadyMemberException;
use App\Client\Domain\ClientInvitation\Exception\PendingClientInvitationAlreadyExistsException;
use App\Client\Domain\ClientInvitation\Factory\ClientInvitationFactoryInterface;
use App\Client\Domain\ClientInvitation\Repository\ClientInvitationRepositoryInterface;

final readonly class CreateClientInvitationCommandHandler
{
    public function __construct(
        private ClientInvitationFactoryInterface $clientInvitationFactory,
        private ClientInvitationRepositoryInterface $clientInvitationRepository,
    ) {
    }

    /**
     * @throws ClientInvitationRoleNotAllowedException
     * @throws PendingClientInvitationAlreadyExistsException
     * @throws InviteeAlreadyMemberException
     */
    public function __invoke(CreateClientInvitationCommand $command): void
    {
        $invitation = $this->clientInvitationFactory->createByClientAdmin(
            $command->id,
            $command->clientId,
            $command->email,
            $command->role,
        );
        $this->clientInvitationRepository->create($invitation);
    }
}
