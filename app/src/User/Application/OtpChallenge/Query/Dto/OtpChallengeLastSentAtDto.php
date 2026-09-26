<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Query\Dto;

final readonly class OtpChallengeLastSentAtDto
{
    public function __construct(
        public string $lastSentAt,
    ) {
    }
}
