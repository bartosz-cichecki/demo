<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientInvitation\Outside;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;

interface ClientInvitationOutsideInterface
{
    public function pendingInvitationExists(Id $clientId, Email $email): bool;

    /**
     * Active or suspended membership of the user with this email; no user means no membership.
     */
    public function membershipExists(Id $clientId, Email $email): bool;

    public function userEmail(Id $userId): ?Email;

    public function now(): DateTime;

    public function record(DomainEvent $event): void;
}
