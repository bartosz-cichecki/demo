<?php

declare(strict_types=1);

namespace App\User\Application\IntegrationEventSubscriber;

use App\User\Application\IntegrationEvent\UserRegisteredIntegrationEvent;
use App\User\Application\Notification\UserNotificationSenderServiceInterface;

final readonly class SendUserRegisteredNotificationSubscriber
{
    public function __construct(
        private UserNotificationSenderServiceInterface $userNotificationSender,
    ) {
    }

    public function onUserRegistered(UserRegisteredIntegrationEvent $event): void
    {
        $this->userNotificationSender->sendUserRegisteredNotification(
            $event->userId,
            $event->email,
            $event->registeredAt,
        );
    }
}
