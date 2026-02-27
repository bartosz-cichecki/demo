<?php

declare(strict_types=1);

namespace App\User\Domain\OtpChallenge\Event;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class OtpChallengeVerified implements DomainEvent
{
    public function __construct(
        public Id $otpChallengeId,
        public Email $email,
        public DateTime $occurredAt,
    ) {
    }
}
