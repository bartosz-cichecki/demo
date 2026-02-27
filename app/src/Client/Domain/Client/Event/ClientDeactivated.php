<?php

declare(strict_types=1);

namespace App\Client\Domain\Client\Event;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class ClientDeactivated implements DomainEvent
{
    public function __construct(
        public Id $clientId,
        public DateTime $occurredAt,
    ) {
    }
}
