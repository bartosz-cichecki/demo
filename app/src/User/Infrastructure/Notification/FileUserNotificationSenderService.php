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

    public function sendUserRegisteredNotification(string $userId, string $email, string $registeredAt): void
    {
        $line = \sprintf(
            'user_registered userId=%s email=%s registeredAt=%s',
            $userId,
            $email,
            $registeredAt,
        );

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
