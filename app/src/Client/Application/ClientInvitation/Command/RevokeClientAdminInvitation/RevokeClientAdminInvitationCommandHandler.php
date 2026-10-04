<?php

declare(strict_types=1);

namespace App\Client\Application\ClientInvitation\Command\RevokeClientAdminInvitation;

use App\Client\Domain\ClientInvitation\Repository\ClientInvitationRepositoryInterface;

final readonly class RevokeClientAdminInvitationCommandHandler
{
    public function __construct(
        private ClientInvitationRepositoryInterface $invitationRepository,
    ) {
    }

    public function __invoke(RevokeClientAdminInvitationCommand $command): void
    {
        $this->invitationRepository->getPendingForClientAndEmail($command->clientId, $command->email)->revokeByPlatformAdmin();
    }
}
