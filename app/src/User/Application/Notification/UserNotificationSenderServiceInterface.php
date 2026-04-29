<?php

declare(strict_types=1);

namespace App\User\Application\Notification;

interface UserNotificationSenderServiceInterface
{
    public function sendUserRegisteredNotification(string $userId, string $email, string $registeredAt): void;
}
