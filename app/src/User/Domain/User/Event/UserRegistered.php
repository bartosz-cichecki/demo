<?php

declare(strict_types=1);

namespace App\User\Domain\User\Event;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class UserRegistered implements DomainEvent
{
    public function __construct(
        public Id $userId,
        public string $email,
        public DateTime $occurredAt,
    ) {
    }
}
