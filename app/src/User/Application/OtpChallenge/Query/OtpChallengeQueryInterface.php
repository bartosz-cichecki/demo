<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Query;

use App\SharedKernel\Domain\ValueObject\Email;
use App\User\Application\OtpChallenge\Query\Dto\OtpChallengeDto;
use App\User\Application\OtpChallenge\Query\Dto\OtpChallengeLastSentAtDto;

interface OtpChallengeQueryInterface
{
    public function findLatestByEmail(Email $email): ?OtpChallengeDto;

    public function findLatestSentAtByEmail(Email $email): ?OtpChallengeLastSentAtDto;

    public function findLatestSentAtByIpHash(string $ipHash): ?OtpChallengeLastSentAtDto;
}
