<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Notification;

use App\User\Application\Notification\UserNotificationSenderServiceInterface;

final readonly class FileUserNotificationSenderService implements UserNotificationSenderServiceInterface
{
    public function __construct(
        private string $filePath,
    ) {
    }

    public function sendClientInvitationNotification(string $invitationId, string $email, string $clientName, string $role): void
    {
        $line = json_encode([
            'type' => 'client_invitation',
            'invitationId' => $invitationId,
            'email' => $email,
            'clientName' => $clientName,
            'role' => $role,
            'message' => \sprintf('You were invited to join %s as %s. Log in to accept or reject the invitation.', $clientName, $role),
        ], \JSON_THROW_ON_ERROR);

        $directory = \dirname($this->filePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Notification directory "%s" could not be created.', $directory));
        }

        $handle = fopen($this->filePath, 'c+');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Notification file "%s" could not be opened.', $this->filePath));
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException(\sprintf('Notification file "%s" could not be locked.', $this->filePath));
            }

            $contents = stream_get_contents($handle);
            if (false === $contents) {
                throw new \RuntimeException(\sprintf('Notification file "%s" could not be read.', $this->filePath));
            }

            // Business idempotency for a crash after delivery but before the consumption is marked processed.
            if (str_contains($contents, $line . "\n") || str_ends_with($contents, $line)) {
                return;
            }

            fseek($handle, 0, \SEEK_END);
            if (false === fwrite($handle, $line . "\n")) {
                throw new \RuntimeException(\sprintf('Notification file "%s" could not be written.', $this->filePath));
            }
        } finally {
            fclose($handle);
        }
    }
}
