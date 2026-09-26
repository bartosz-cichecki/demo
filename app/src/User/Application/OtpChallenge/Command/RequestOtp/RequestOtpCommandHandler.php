<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Command\RequestOtp;

use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\OtpChallenge\Service\OtpCodeSenderServiceInterface;
use App\User\Domain\OtpChallenge\Factory\OtpChallengeFactoryInterface;
use App\User\Domain\OtpChallenge\Repository\OtpChallengeRepositoryInterface;

final readonly class RequestOtpCommandHandler
{
    public function __construct(
        private OtpChallengeFactoryInterface $otpChallengeFactory,
        private OtpChallengeRepositoryInterface $otpChallengeRepository,
        private OtpCodeSenderServiceInterface $otpCodeSender,
    ) {
    }

    public function __invoke(RequestOtpCommand $command): void
    {
        $email = $command->email;
        $issue = $this->otpChallengeFactory->issue(
            Id::new(),
            $email,
            $command->ipAddress,
            $command->userAgent,
        );

        if (null === $issue) {
            return;
        }

        $this->otpChallengeRepository->create($issue->challenge);
        $this->otpCodeSender->send($email, $issue->plainCode);
    }
}
