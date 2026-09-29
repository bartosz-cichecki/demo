<?php

declare(strict_types=1);

namespace App\Client\Application\ClientInvitation\Query\Dto;

final readonly class ClientInvitationDto
{
    public function __construct(
        public string $id,
        public string $clientId,
        public string $clientName,
        public string $email,
        public string $role,
        public string $status,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
