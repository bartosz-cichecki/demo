<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\ClientMember;

use App\Client\Domain\ClientMember\ClientMember;
use App\Client\Domain\ClientMember\Repository\ClientMemberRepositoryInterface;
use App\Client\Domain\ClientMember\Repository\Exception\ClientMemberDoesNotExistException;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ClientMemberRepository implements ClientMemberRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function create(ClientMember $member): void
    {
        $this->em->persist($member);
    }

    public function getByClientAndUser(Id $clientId, Id $userId): ClientMember
    {
        $member = $this->findByClientAndUser($clientId, $userId);
        if (null === $member) {
            throw new ClientMemberDoesNotExistException($clientId, $userId);
        }

        return $member;
    }

    public function findByClientAndUser(Id $clientId, Id $userId): ?ClientMember
    {
        return $this->em->getRepository(ClientMember::class)->findOneBy([
            'clientId' => $clientId,
            'userId' => $userId,
        ]);
    }
}
