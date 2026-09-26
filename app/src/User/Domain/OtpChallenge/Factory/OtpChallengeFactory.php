<?php

declare(strict_types=1);

namespace App\User\Domain\OtpChallenge\Factory;

use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Domain\OtpChallenge\OtpChallenge;
use App\User\Domain\OtpChallenge\Outside\OtpChallengeOutsideInterface;

final readonly class OtpChallengeFactory implements OtpChallengeFactoryInterface
{
    private const int COOLDOWN_SECONDS = 60;

    public function __construct(
        private OtpChallengeOutsideInterface $otpChallengeOutside,
    ) {
    }

    public function issue(
        Id $id,
        Email $email,
        ?string $ipAddress,
        ?string $userAgent,
    ): ?OtpChallengeIssue {
        $now = $this->otpChallengeOutside->now();
        $emailLastSentAt = $this->otpChallengeOutside->findLatestSentAtByEmail($email);
        $ipLastSentAt = null === $ipAddress ? null : $this->otpChallengeOutside->findLatestSentAtByIp($ipAddress);

        if ($this->isCoolingDown($emailLastSentAt, $now) || $this->isCoolingDown($ipLastSentAt, $now)) {
            return null;
        }

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

    private function isCoolingDown(?DateTime $lastSentAt, DateTime $now): bool
    {
        return null !== $lastSentAt
            && $now->value < $lastSentAt->value->modify(\sprintf('+%d seconds', self::COOLDOWN_SECONDS));
    }
}
