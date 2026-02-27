<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\ClientMember;

use App\Client\Application\ClientMember\Query\ClientMemberQueryInterface;
use App\Client\Application\ClientMember\Query\Dto\ClientMemberDto;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\DBAL\Connection;

final readonly class ClientMemberQuery implements ClientMemberQueryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findById(Id $id): ?ClientMemberDto
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, client_id, user_id, roles_json, status, created_at, updated_at
             FROM client.client_memberships
             WHERE id = :id',
            ['id' => (string) $id],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrateDto($row);
    }

    public function findByClientAndUser(Id $clientId, Id $userId): ?ClientMemberDto
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, client_id, user_id, roles_json, status, created_at, updated_at
             FROM client.client_memberships
             WHERE client_id = :clientId AND user_id = :userId',
            [
                'clientId' => (string) $clientId,
                'userId' => (string) $userId,
            ],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrateDto($row);
    }

    /**
     * @return array<ClientMemberDto>
     */
    public function listByClient(Id $clientId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, client_id, user_id, roles_json, status, created_at, updated_at
             FROM client.client_memberships
             WHERE client_id = :clientId
             ORDER BY created_at ASC',
            ['clientId' => (string) $clientId],
        );

        return array_map(fn (array $row) => $this->hydrateDto($row), $rows);
    }

    /**
     * @return array<ClientMemberDto>
     */
    public function listByUser(Id $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, client_id, user_id, roles_json, status, created_at, updated_at
             FROM client.client_memberships
             WHERE user_id = :userId
             ORDER BY created_at ASC',
            ['userId' => (string) $userId],
        );

        return array_map(fn (array $row) => $this->hydrateDto($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateDto(array $row): ClientMemberDto
    {
        \assert(\is_string($row['id']));
        \assert(\is_string($row['client_id']));
        \assert(\is_string($row['user_id']));
        \assert(\is_string($row['roles_json']));
        \assert(\is_string($row['status']));
        \assert(\is_string($row['created_at']));
        \assert(\is_string($row['updated_at']));

        /** @var array<string> $roles */
        $roles = json_decode($row['roles_json'], true, 512, \JSON_THROW_ON_ERROR);

        return new ClientMemberDto(
            id: $row['id'],
            clientId: $row['client_id'],
            userId: $row['user_id'],
            roles: $roles,
            status: $row['status'],
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }
}
