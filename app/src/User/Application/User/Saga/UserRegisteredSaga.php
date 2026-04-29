<?php

declare(strict_types=1);

namespace App\User\Application\User\Saga;

use App\SharedKernel\Application\IntegrationEvent\IntegrationEventPublisherInterface;
use App\User\Application\IntegrationEvent\UserRegisteredIntegrationEvent;
use App\User\Domain\User\Event\UserRegistered;

final readonly class UserRegisteredSaga
{
    public function __construct(
        private IntegrationEventPublisherInterface $integrationEventPublisher,
    ) {
    }

    public function onUserRegistered(UserRegistered $event): void
    {
        $this->integrationEventPublisher->publish(new UserRegisteredIntegrationEvent(
            (string) $event->userId,
            $event->email,
            $event->occurredAt->toStorageString(),
        ));
    }
}
