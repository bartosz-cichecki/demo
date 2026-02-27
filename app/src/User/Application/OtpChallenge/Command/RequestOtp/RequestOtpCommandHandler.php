<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Command\RequestOtp;

use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\OtpChallenge\Query\OtpRateLimitQueryInterface;
use App\User\Domain\OtpChallenge\Factory\OtpChallengeFactoryInterface;
use App\User\Domain\OtpChallenge\Repository\OtpChallengeRepositoryInterface;

final readonly class RequestOtpCommandHandler
{
    public function __construct(
        private OtpRateLimitQueryInterface $otpRateLimitQuery,
        private OtpChallengeFactoryInterface $otpChallengeFactory,
        private OtpChallengeRepositoryInterface $otpChallengeRepository,
    ) {
    }

    public function __invoke(RequestOtpCommand $command): void
    {
        $email = $command->email;
        $rateLimit = $this->otpRateLimitQuery->check($email, $command->ipAddress);
        if (!$rateLimit->isAllowed) {
            return;
        }

        $issue = $this->otpChallengeFactory->issue(
            Id::new(),
            $email,
            $command->ipAddress,
            $command->userAgent,
        );

        $this->otpChallengeRepository->create($issue->challenge);
    }
}
