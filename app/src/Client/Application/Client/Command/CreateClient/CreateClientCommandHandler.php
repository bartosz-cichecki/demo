<?php

declare(strict_types=1);

namespace App\Client\Application\Client\Command\CreateClient;

use App\Client\Domain\Client\Factory\ClientFactoryInterface;
use App\Client\Domain\Client\Repository\ClientRepositoryInterface;

final readonly class CreateClientCommandHandler
{
    public function __construct(
        private ClientFactoryInterface $clientFactory,
        private ClientRepositoryInterface $clientRepository,
    ) {
    }

    public function __invoke(CreateClientCommand $command): void
    {
        $client = $this->clientFactory->create(
            $command->id,
            $command->name,
            $command->description,
        );
        $this->clientRepository->create($client);
    }
}
