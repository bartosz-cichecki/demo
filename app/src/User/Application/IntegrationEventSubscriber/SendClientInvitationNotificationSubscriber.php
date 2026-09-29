<?php

declare(strict_types=1);

namespace App\User\Application\IntegrationEventSubscriber;

use App\Client\Application\IntegrationEvent\ClientInvitationCreatedIntegrationEvent;
use App\User\Application\Notification\UserNotificationSenderServiceInterface;

final readonly class SendClientInvitationNotificationSubscriber
{
    public function __construct(
        private UserNotificationSenderServiceInterface $userNotificationSender,
    ) {
    }

    public function onClientInvitationCreated(ClientInvitationCreatedIntegrationEvent $event): void
    {
        $this->userNotificationSender->sendClientInvitationNotification(
            $event->invitationId,
            $event->email,
            $event->clientName,
            $event->role,
        );
    }
}
