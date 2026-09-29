<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\ClientInvitation;

use App\Client\Application\ClientInvitation\Query\ClientInvitationQueryInterface;
use App\Client\Application\ClientInvitation\Query\Dto\ClientInvitationDto;
use App\Client\Domain\ClientInvitation\ClientInvitation;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\DBAL\Connection;

final readonly class ClientInvitationQuery implements ClientInvitationQueryInterface
{
    private const string SELECT = 'SELECT i.id, i.client_id, c.name AS client_name, i.email, i.role, i.status, i.created_at, i.updated_at
         FROM client.client_invitations i
         JOIN client.clients c ON c.id = i.client_id';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findById(Id $id): ?ClientInvitationDto
    {
        $row = $this->connection->fetchAssociative(
            self::SELECT . ' WHERE i.id = :id',
            ['id' => (string) $id],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrateDto($row);
    }

    public function findPendingByClientAndEmail(Id $clientId, Email $email): ?ClientInvitationDto
    {
        $row = $this->connection->fetchAssociative(
            self::SELECT . ' WHERE i.client_id = :clientId AND i.email = :email AND i.status = :status',
            [
                'clientId' => (string) $clientId,
                'email' => (string) $email,
                'status' => ClientInvitation::STATUS_PENDING,
            ],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrateDto($row);
    }

    /**
     * @return array<ClientInvitationDto>
     */
    public function listPendingByEmail(Email $email): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::SELECT . ' WHERE i.email = :email AND i.status = :status ORDER BY i.created_at ASC, i.id ASC',
            [
                'email' => (string) $email,
                'status' => ClientInvitation::STATUS_PENDING,
            ],
        );

        return array_map(fn (array $row) => $this->hydrateDto($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateDto(array $row): ClientInvitationDto
    {
        \assert(\is_string($row['id']));
        \assert(\is_string($row['client_id']));
        \assert(\is_string($row['client_name']));
        \assert(\is_string($row['email']));
        \assert(\is_string($row['role']));
        \assert(\is_string($row['status']));
        \assert(\is_string($row['created_at']));
        \assert(\is_string($row['updated_at']));

        return new ClientInvitationDto(
            id: $row['id'],
            clientId: $row['client_id'],
            clientName: $row['client_name'],
            email: $row['email'],
            role: $row['role'],
            status: $row['status'],
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }
}
