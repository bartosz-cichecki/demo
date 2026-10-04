<?php

declare(strict_types=1);

namespace App\Client\Application\Client\Command\OnboardClient;

use App\Client\Domain\Client\Factory\ClientFactoryInterface;
use App\Client\Domain\Client\Repository\ClientRepositoryInterface;
use App\Client\Domain\ClientInvitation\Factory\ClientInvitationFactoryInterface;
use App\Client\Domain\ClientInvitation\Repository\ClientInvitationRepositoryInterface;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class OnboardClientCommandHandler
{
    public function __construct(
        private ClientFactoryInterface $clientFactory,
        private ClientRepositoryInterface $clientRepository,
        private ClientInvitationFactoryInterface $invitationFactory,
        private ClientInvitationRepositoryInterface $invitationRepository,
    ) {
    }

    public function __invoke(OnboardClientCommand $command): void
    {
        $client = $this->clientFactory->create(
            $command->id,
            $command->name,
            $command->description,
        );
        $this->clientRepository->create($client);
        $this->invitationRepository->create($this->invitationFactory->createByPlatformAdmin(
            Id::new(),
            $command->id,
            $command->adminEmail,
        ));
    }
}
