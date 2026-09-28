<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientMember\Outside;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;

interface ClientMemberOutsideInterface
{
    public function membershipExists(Id $clientId, Id $userId): bool;

    public function now(): DateTime;

    public function record(DomainEvent $event): void;
}
