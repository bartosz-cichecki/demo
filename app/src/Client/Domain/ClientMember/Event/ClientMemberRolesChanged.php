<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientMember\Event;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class ClientMemberRolesChanged implements DomainEvent
{
    /**
     * @param array<string> $oldRoles
     * @param array<string> $newRoles
     */
    public function __construct(
        public Id $clientMemberId,
        public array $oldRoles,
        public array $newRoles,
        public DateTime $occurredAt,
    ) {
    }
}
