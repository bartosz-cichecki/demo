<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\ClientInvitation;

use App\Client\Domain\ClientInvitation\ClientInvitation;
use App\Client\Domain\ClientInvitation\Repository\ClientInvitationRepositoryInterface;
use App\Client\Domain\ClientInvitation\Repository\Exception\ClientInvitationDoesNotExistException;
use App\Client\Domain\ClientInvitation\Repository\Exception\PendingClientInvitationDoesNotExistException;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads require the active CommandBus transaction and must be the invitation's first load.
 * DQL setLockMode() locks the row but does not refresh an entity already in the identity map.
 */
final readonly class ClientInvitationRepository implements ClientInvitationRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function create(ClientInvitation $invitation): void
    {
        $this->em->persist($invitation);
    }

    public function get(Id $id): ClientInvitation
    {
        $invitation = $this->em->find(ClientInvitation::class, $id, LockMode::PESSIMISTIC_WRITE);
        if (null === $invitation) {
            throw new ClientInvitationDoesNotExistException($id);
        }

        return $invitation;
    }

    public function getForClient(Id $id, Id $clientId): ClientInvitation
    {
        /** @var ClientInvitation|null $invitation */
        $invitation = $this->em->createQueryBuilder()
            ->select('i')
            ->from(ClientInvitation::class, 'i')
            ->where('i.id = :id')
            ->andWhere('i.clientId = :clientId')
            ->setParameter('id', $id, 'domain_id')
            ->setParameter('clientId', $clientId, 'domain_id')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
        if (null === $invitation) {
            throw new ClientInvitationDoesNotExistException($id);
        }

        return $invitation;
    }

    public function getPendingForClientAndEmail(Id $clientId, Email $email): ClientInvitation
    {
        /** @var ClientInvitation|null $invitation */
        $invitation = $this->em->createQueryBuilder()
            ->select('i')
            ->from(ClientInvitation::class, 'i')
            ->where('i.clientId = :clientId')
            ->andWhere('i.email = :email')
            ->andWhere('i.status = :status')
            ->setParameter('clientId', $clientId, 'domain_id')
            ->setParameter('email', $email, 'domain_email')
            ->setParameter('status', ClientInvitation::STATUS_PENDING)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
        if (null === $invitation) {
            throw new PendingClientInvitationDoesNotExistException($clientId);
        }

        return $invitation;
    }
}
