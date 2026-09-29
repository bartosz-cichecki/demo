<?php

declare(strict_types=1);

namespace App\Client\Application\IntegrationEvent;

use App\SharedKernel\Application\IntegrationEvent\IntegrationEvent;

final readonly class ClientInvitationCreatedIntegrationEvent implements IntegrationEvent
{
    public function __construct(
        public string $invitationId,
        public string $clientId,
        public string $clientName,
        public string $email,
        public string $role,
        public string $invitedAt,
    ) {
    }
}
