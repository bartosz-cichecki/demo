<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\Client;

use App\Client\Application\Client\Query\ClientQueryInterface;
use App\Client\Application\Client\Query\Dto\ClientDto;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\DBAL\Connection;

final readonly class ClientQuery implements ClientQueryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findById(Id $id): ?ClientDto
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, name, description, status, created_at, updated_at FROM client.clients WHERE id = :id',
            ['id' => (string) $id],
        );

        if (false === $row) {
            return null;
        }

        \assert(\is_string($row['id']));
        \assert(\is_string($row['name']));
        \assert(null === $row['description'] || \is_string($row['description']));
        \assert(\is_string($row['status']));
        \assert(\is_string($row['created_at']));
        \assert(\is_string($row['updated_at']));

        return new ClientDto(
            id: $row['id'],
            name: $row['name'],
            description: $row['description'],
            status: $row['status'],
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }
}
