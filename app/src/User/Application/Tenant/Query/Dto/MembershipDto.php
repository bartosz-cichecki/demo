<?php

declare(strict_types=1);

namespace App\User\Application\Tenant\Query\Dto;

final readonly class MembershipDto
{
    /**
     * @param array<string> $roles
     */
    public function __construct(
        public array $roles,
        public string $status,
    ) {
    }
}
