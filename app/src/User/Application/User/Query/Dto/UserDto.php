<?php

declare(strict_types=1);

namespace App\User\Application\User\Query\Dto;

final readonly class UserDto
{
    public function __construct(
        public string $id,
        public string $email,
        public string $status,
        public string $createdAt,
        public ?string $lastLoginAt,
        public ?string $blockedAt,
        public ?string $blockedReason,
    ) {
    }
}
