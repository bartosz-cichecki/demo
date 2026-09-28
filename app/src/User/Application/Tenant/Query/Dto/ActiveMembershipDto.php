<?php

declare(strict_types=1);

namespace App\User\Application\Tenant\Query\Dto;

final readonly class ActiveMembershipDto
{
    /**
     * @param array<string> $roles
     */
    public function __construct(
        public string $clientId,
        public string $clientName,
        public array $roles,
    ) {
    }
}
