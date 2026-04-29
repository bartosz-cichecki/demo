<?php

declare(strict_types=1);

namespace App\User\Application\IntegrationEvent;

use App\SharedKernel\Application\IntegrationEvent\IntegrationEvent;

final readonly class UserRegisteredIntegrationEvent implements IntegrationEvent
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $registeredAt,
    ) {
    }
}
