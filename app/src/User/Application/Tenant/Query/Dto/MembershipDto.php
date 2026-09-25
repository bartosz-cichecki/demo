<?php

declare(strict_types=1);

namespace App\User\Application\Tenant\Query\Dto;

final readonly class MembershipDto
{
    public function __construct(
        public bool $isActive,
        public bool $isAdmin,
    ) {
    }
}
