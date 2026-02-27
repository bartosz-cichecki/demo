<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\Client;

use App\Client\Domain\Client\Client;
use App\Client\Domain\Client\Repository\ClientRepositoryInterface;
use App\Client\Domain\Client\Repository\Exception\ClientDoesNotExistException;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ClientRepository implements ClientRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function create(Client $client): void
    {
        $this->em->persist($client);
    }

    public function get(Id $id): Client
    {
        $client = $this->em->find(Client::class, $id);
        if (null === $client) {
            throw new ClientDoesNotExistException($id);
        }

        return $client;
    }
}
