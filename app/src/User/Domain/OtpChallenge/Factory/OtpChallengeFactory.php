<?php

declare(strict_types=1);

namespace App\User\Domain\OtpChallenge\Factory;

use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Domain\OtpChallenge\OtpChallenge;
use App\User\Domain\OtpChallenge\Outside\OtpChallengeOutsideInterface;

final readonly class OtpChallengeFactory implements OtpChallengeFactoryInterface
{
    public function __construct(
        private OtpChallengeOutsideInterface $otpChallengeOutside,
    ) {
    }

    public function issue(
        Id $id,
        Email $email,
        ?string $ipAddress,
        ?string $userAgent,
    ): OtpChallengeIssue {
        $plainCode = $this->otpChallengeOutside->generateCode();

        return new OtpChallengeIssue(
            new OtpChallenge(
                $this->otpChallengeOutside,
                $id,
                $email,
                $plainCode,
                $ipAddress,
                $userAgent,
            ),
            $plainCode,
        );
    }
}
