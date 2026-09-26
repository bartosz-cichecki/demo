<?php

declare(strict_types=1);

namespace App\User\Infrastructure\OtpChallenge;

use App\SharedKernel\Domain\ValueObject\Email;
use App\User\Application\OtpChallenge\Service\OtpCodeSenderServiceInterface;

final readonly class FileOtpCodeSenderService implements OtpCodeSenderServiceInterface
{
    public function __construct(
        private string $filePath,
    ) {
    }

    public function send(Email $email, string $plainCode): void
    {
        $directory = \dirname($this->filePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('OTP mailbox directory "%s" could not be created.', $directory));
        }

        $message = json_encode(['email' => (string) $email, 'code' => $plainCode], \JSON_THROW_ON_ERROR) . "\n";

        // Local demo: delivery is not rolled back if the subsequent database commit fails.
        if (\strlen($message) !== file_put_contents($this->filePath, $message, \FILE_APPEND | \LOCK_EX)) {
            throw new \RuntimeException(\sprintf('OTP mailbox "%s" could not be written.', $this->filePath));
        }
    }
}
