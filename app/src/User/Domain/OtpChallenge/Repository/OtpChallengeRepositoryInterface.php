<?php

declare(strict_types=1);

namespace App\User\Domain\OtpChallenge\Repository;

use App\SharedKernel\Domain\ValueObject\Email;
use App\User\Domain\OtpChallenge\OtpChallenge;

interface OtpChallengeRepositoryInterface
{
    public function create(OtpChallenge $otpChallenge): void;

    public function findLatestByEmail(Email $email): ?OtpChallenge;
}
