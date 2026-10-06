<?php

declare(strict_types=1);

namespace App\Tests\Client\Domain\ClientInvitation;

use App\Client\Domain\ClientInvitation\Outside\ClientInvitationOutsideInterface;
use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\Event\DomainEventsRecorder;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class FakeClientInvitationOutside implements ClientInvitationOutsideInterface
{
    /**
     * @param array<string, Email> $userEmails user id => email
     */
    public function __construct(
        private DomainEventsRecorder $recorder,
        private \DateTimeImmutable $fixedTime = new \DateTimeImmutable('2026-09-29 10:00:00'),
        private bool $pendingInvitationExists = false,
        private bool $membershipExists = false,
        private array $userEmails = [],
    ) {
    }

    public function pendingInvitationExists(Id $clientId, Email $email): bool
    {
        return $this->pendingInvitationExists;
    }

    public function membershipExists(Id $clientId, Email $email): bool
    {
        return $this->membershipExists;
    }

    public function userEmail(Id $userId): ?Email
    {
        return $this->userEmails[(string) $userId] ?? null;
    }

    public function now(): DateTime
    {
        return new DateTime($this->fixedTime);
    }

    public function record(DomainEvent $event): void
    {
        $this->recorder->record($event);
    }
}
