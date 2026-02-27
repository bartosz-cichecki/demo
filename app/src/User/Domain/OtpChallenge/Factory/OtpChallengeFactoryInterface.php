<?php

declare(strict_types=1);

namespace App\User\Domain\OtpChallenge\Factory;

use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;

interface OtpChallengeFactoryInterface
{
    public function issue(
        Id $id,
        Email $email,
        ?string $ipAddress,
        ?string $userAgent,
    ): OtpChallengeIssue;
}
