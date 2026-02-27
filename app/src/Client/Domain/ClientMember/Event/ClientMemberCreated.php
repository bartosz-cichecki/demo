<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientMember\Event;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class ClientMemberCreated implements DomainEvent
{
    /**
     * @param array<string> $roles
     */
    public function __construct(
        public Id $clientMemberId,
        public Id $clientId,
        public Id $userId,
        public array $roles,
        public DateTime $occurredAt,
    ) {
    }
}
