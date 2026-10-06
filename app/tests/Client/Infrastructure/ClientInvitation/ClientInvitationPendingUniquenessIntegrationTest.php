<?php

declare(strict_types=1);

namespace App\Tests\Client\Infrastructure\ClientInvitation;

use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClientInvitationPendingUniquenessIntegrationTest extends KernelTestCase
{
    public function testDatabaseAllowsOnlyOnePendingInvitationPerClientAndEmail(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        // Concurrent creations pass the factory check; only the partial unique index stops the second one.
        $clientId = (string) Id::new();
        $email = (string) Id::new() . '@example.com';
        $insert = static fn (string $status): int|string => $connection->insert('client.client_invitations', [
            'id' => (string) Id::new(),
            'client_id' => $clientId,
            'email' => $email,
            'role' => 'user',
            'status' => $status,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $connection->beginTransaction();
        try {
            $insert('revoked');
            $insert('pending');

            $this->expectException(UniqueConstraintViolationException::class);
            $insert('pending');
        } finally {
            $connection->rollBack();
        }
    }
}
