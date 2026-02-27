<?php

declare(strict_types=1);

namespace App\User\Application\Tenant\Query\Dto;

final readonly class ActiveMembershipDto
{
    public function __construct(
        public string $clientId,
    ) {
    }
}
