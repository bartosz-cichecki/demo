<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\ClientInvitation;

use App\Client\Domain\ClientInvitation\ClientInvitation;
use App\Client\Domain\ClientInvitation\Repository\ClientInvitationRepositoryInterface;
use App\Client\Domain\ClientInvitation\Repository\Exception\ClientInvitationDoesNotExistException;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\ORM\EntityManagerInterface;

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
        $invitation = $this->em->find(ClientInvitation::class, $id);
        if (null === $invitation) {
            throw new ClientInvitationDoesNotExistException($id);
        }

        return $invitation;
    }

    public function getForClient(Id $id, Id $clientId): ClientInvitation
    {
        $invitation = $this->em->getRepository(ClientInvitation::class)->findOneBy([
            'id' => $id,
            'clientId' => $clientId,
        ]);
        if (null === $invitation) {
            throw new ClientInvitationDoesNotExistException($id);
        }

        return $invitation;
    }
}
