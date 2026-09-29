<?php

declare(strict_types=1);

namespace App\User\Application\Notification;

interface UserNotificationSenderServiceInterface
{
    /**
     * Must be idempotent per invitation: a retried delivery must not notify twice.
     */
    public function sendClientInvitationNotification(string $invitationId, string $email, string $clientName, string $role): void;
}
