<?php

declare(strict_types=1);

namespace App\Tests\Client\Domain\ClientMember;

use App\Client\Domain\ClientMember\Outside\ClientMemberOutsideInterface;
use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\Event\DomainEventsRecorder;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;

final class FakeClientMemberOutside implements ClientMemberOutsideInterface
{
    /** @var list<array{Id, Id}> */
    public array $membershipLookups = [];

    public function __construct(
        private readonly DomainEventsRecorder $recorder,
        private readonly \DateTimeImmutable $fixedTime = new \DateTimeImmutable('2024-01-15 10:00:00'),
        private readonly bool $membershipExists = false,
    ) {
    }

    public function now(): DateTime
    {
        return new DateTime($this->fixedTime);
    }

    public function membershipExists(Id $clientId, Id $userId): bool
    {
        $this->membershipLookups[] = [$clientId, $userId];

        return $this->membershipExists;
    }

    public function record(DomainEvent $event): void
    {
        $this->recorder->record($event);
    }
}
