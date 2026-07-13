<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Query;

use App\SharedKernel\Domain\ValueObject\Email;
use App\User\Application\OtpChallenge\Query\Dto\OtpChallengeDto;

interface OtpChallengeQueryInterface
{
    public function findLatestByEmail(Email $email): ?OtpChallengeDto;
}
