<?php

declare(strict_types=1);

namespace App\Client\Application\ClientMember\Query\Dto;

final readonly class ClientMemberDto
{
    /**
     * @param array<string> $roles
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public string $userId,
        public array $roles,
        public string $status,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
