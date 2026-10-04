<?php

declare(strict_types=1);

namespace App\Client\Application\ClientInvitation\Command\InviteClientAdmin;

use App\Client\Domain\Client\Repository\ClientRepositoryInterface;
use App\Client\Domain\ClientInvitation\Factory\ClientInvitationFactoryInterface;
use App\Client\Domain\ClientInvitation\Repository\ClientInvitationRepositoryInterface;

final readonly class InviteClientAdminCommandHandler
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private ClientInvitationFactoryInterface $invitationFactory,
        private ClientInvitationRepositoryInterface $invitationRepository,
    ) {
    }

    public function __invoke(InviteClientAdminCommand $command): void
    {
        $this->clientRepository->get($command->clientId);
        $this->invitationRepository->create($this->invitationFactory->createByPlatformAdmin(
            $command->id,
            $command->clientId,
            $command->email,
        ));
    }
}
