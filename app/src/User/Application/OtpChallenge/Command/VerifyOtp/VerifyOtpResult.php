<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Command\VerifyOtp;

final readonly class VerifyOtpResult
{
    public function __construct(
        public bool $verified,
    ) {
    }
}
