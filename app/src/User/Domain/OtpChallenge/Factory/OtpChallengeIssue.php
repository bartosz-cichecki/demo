<?php

declare(strict_types=1);

namespace App\User\Domain\OtpChallenge\Factory;

use App\User\Domain\OtpChallenge\OtpChallenge;

final readonly class OtpChallengeIssue
{
    public function __construct(
        public OtpChallenge $challenge,
        public string $plainCode,
    ) {
    }
}
