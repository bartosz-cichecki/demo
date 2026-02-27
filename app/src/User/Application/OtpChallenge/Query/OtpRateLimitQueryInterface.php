<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Query;

use App\SharedKernel\Domain\ValueObject\Email;
use App\User\Application\OtpChallenge\Query\Dto\OtpRateLimitDto;

interface OtpRateLimitQueryInterface
{
    public function check(Email $email, ?string $ipAddress): OtpRateLimitDto;
}
