<?php

declare(strict_types=1);

namespace App\User\Infrastructure\User;

use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\User\Query\Dto\UserDto;
use App\User\Application\User\Query\UserQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class UserQuery implements UserQueryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findById(Id $id): ?UserDto
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, email, status, created_at, last_login_at, blocked_at, blocked_reason FROM "user".users WHERE id = :id',
            ['id' => (string) $id],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrateDto($row);
    }

    public function findByEmail(Email $email): ?UserDto
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, email, status, created_at, last_login_at, blocked_at, blocked_reason FROM "user".users WHERE email = :email',
            ['email' => (string) $email],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrateDto($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateDto(array $row): UserDto
    {
        \assert(\is_string($row['id']));
        \assert(\is_string($row['email']));
        \assert(\is_string($row['status']));
        \assert(\is_string($row['created_at']));
        \assert(null === $row['last_login_at'] || \is_string($row['last_login_at']));
        \assert(null === $row['blocked_at'] || \is_string($row['blocked_at']));
        \assert(null === $row['blocked_reason'] || \is_string($row['blocked_reason']));

        return new UserDto(
            id: $row['id'],
            email: $row['email'],
            status: $row['status'],
            createdAt: $row['created_at'],
            lastLoginAt: $row['last_login_at'],
            blockedAt: $row['blocked_at'],
            blockedReason: $row['blocked_reason'],
        );
    }
}
