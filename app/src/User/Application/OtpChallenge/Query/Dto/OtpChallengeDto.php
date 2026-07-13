<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Query\Dto;

final readonly class OtpChallengeDto
{
    public function __construct(
        public int $attempts,
        public ?string $consumedAt,
    ) {
    }
}
