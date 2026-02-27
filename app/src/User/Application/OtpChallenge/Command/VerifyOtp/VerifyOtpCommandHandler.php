<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Command\VerifyOtp;

use App\User\Application\OtpChallenge\Command\VerifyOtp\Exception\OtpVerificationFailedException;
use App\User\Domain\OtpChallenge\Repository\OtpChallengeRepositoryInterface;

final readonly class VerifyOtpCommandHandler
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private OtpChallengeRepositoryInterface $otpChallengeRepository,
    ) {
    }

    public function __invoke(VerifyOtpCommand $command): void
    {
        $email = $command->email;
        $challenge = $this->otpChallengeRepository->findLatestByEmail($email);
        if (null === $challenge) {
            throw new OtpVerificationFailedException();
        }

        if (!$challenge->verify($command->code, self::MAX_ATTEMPTS)) {
            throw new OtpVerificationFailedException();
        }
    }
}
