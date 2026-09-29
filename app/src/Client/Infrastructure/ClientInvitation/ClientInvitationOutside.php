<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\ClientInvitation;

use App\Client\Application\ClientInvitation\Query\ClientInvitationQueryInterface;
use App\Client\Application\ClientMember\Query\ClientMemberQueryInterface;
use App\Client\Application\UserAccount\Query\UserAccountQueryInterface;
use App\Client\Domain\ClientInvitation\Outside\ClientInvitationOutsideInterface;
use App\SharedKernel\Domain\Clock\ClockInterface;
use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\Event\DomainEventsRecorder;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\SharedKernel\Infrastructure\Outside\Attribute\AsOutsideFor;

#[AsOutsideFor(ClientInvitationOutsideInterface::class)]
final readonly class ClientInvitationOutside implements ClientInvitationOutsideInterface
{
    public function __construct(
        private DomainEventsRecorder $domainEventsRecorder,
        private ClockInterface $clock,
        private ClientInvitationQueryInterface $clientInvitationQuery,
        private ClientMemberQueryInterface $clientMemberQuery,
        private UserAccountQueryInterface $userAccountQuery,
    ) {
    }

    public function pendingInvitationExists(Id $clientId, Email $email): bool
    {
        return null !== $this->clientInvitationQuery->findPendingByClientAndEmail($clientId, $email);
    }

    public function membershipExists(Id $clientId, Email $email): bool
    {
        $userId = $this->userAccountQuery->findUserIdByEmail($email);
        if (null === $userId) {
            return false;
        }

        return null !== $this->clientMemberQuery->findByClientAndUser($clientId, $userId);
    }

    public function userEmail(Id $userId): ?Email
    {
        return $this->userAccountQuery->findEmailByUserId($userId);
    }

    public function now(): DateTime
    {
        return $this->clock->now();
    }

    public function record(DomainEvent $event): void
    {
        $this->domainEventsRecorder->record($event);
    }
}
