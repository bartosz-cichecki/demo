<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Query\Dto;

final readonly class OtpRateLimitDto
{
    public function __construct(
        public bool $isAllowed,
        public int $retryAfterSeconds,
    ) {
    }
}
