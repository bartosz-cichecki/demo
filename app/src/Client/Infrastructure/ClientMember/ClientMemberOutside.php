<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\ClientMember;

use App\Client\Application\ClientMember\Query\ClientMemberQueryInterface;
use App\Client\Domain\ClientMember\Outside\ClientMemberOutsideInterface;
use App\SharedKernel\Domain\Clock\ClockInterface;
use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\Event\DomainEventsRecorder;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;
use App\SharedKernel\Infrastructure\Outside\Attribute\AsOutsideFor;

#[AsOutsideFor(ClientMemberOutsideInterface::class)]
final readonly class ClientMemberOutside implements ClientMemberOutsideInterface
{
    public function __construct(
        private DomainEventsRecorder $domainEventsRecorder,
        private ClockInterface $clock,
        private ClientMemberQueryInterface $clientMemberQuery,
    ) {
    }

    public function now(): DateTime
    {
        return $this->clock->now();
    }

    public function membershipExists(Id $clientId, Id $userId): bool
    {
        return null !== $this->clientMemberQuery->findByClientAndUser($clientId, $userId);
    }

    public function record(DomainEvent $event): void
    {
        $this->domainEventsRecorder->record($event);
    }
}
