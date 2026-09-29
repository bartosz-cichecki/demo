<?php

declare(strict_types=1);

namespace App\Client\Application\ClientInvitation\Saga;

use App\Client\Application\Client\Query\ClientQueryInterface;
use App\Client\Application\IntegrationEvent\ClientInvitationCreatedIntegrationEvent;
use App\Client\Domain\ClientInvitation\Event\ClientInvitationCreated;
use App\SharedKernel\Application\IntegrationEvent\IntegrationEventPublisherInterface;

final readonly class ClientInvitationSaga
{
    public function __construct(
        private ClientQueryInterface $clientQuery,
        private IntegrationEventPublisherInterface $integrationEventPublisher,
    ) {
    }

    public function onClientInvitationCreated(ClientInvitationCreated $event): void
    {
        $client = $this->clientQuery->findById($event->clientId);
        if (null === $client) {
            throw new \RuntimeException(\sprintf('Client %s of invitation %s does not exist.', $event->clientId, $event->clientInvitationId));
        }

        $this->integrationEventPublisher->publish(new ClientInvitationCreatedIntegrationEvent(
            (string) $event->clientInvitationId,
            (string) $event->clientId,
            $client->name,
            $event->email,
            $event->role,
            $event->occurredAt->toStorageString(),
        ));
    }
}
