<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientInvitation\Event;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class ClientInvitationAccepted implements DomainEvent
{
    public function __construct(
        public Id $clientInvitationId,
        public Id $clientId,
        public Id $userId,
        public string $role,
        public DateTime $occurredAt,
    ) {
    }
}
