<?php

declare(strict_types=1);

namespace App\Tests\Client\Infrastructure\ClientInvitation;

use App\Client\Application\Client\Command\CreateClient\CreateClientCommand;
use App\Client\Application\ClientInvitation\Command\CreateClientInvitation\CreateClientInvitationCommand;
use App\Client\Domain\ClientInvitation\Repository\ClientInvitationRepositoryInterface;
use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class ClientInvitationConcurrencyIntegrationTest extends KernelTestCase
{
    private ?KernelInterface $secondWriterKernel = null;

    protected function tearDown(): void
    {
        $this->secondWriterKernel?->shutdown();
        $this->secondWriterKernel = null;
        parent::tearDown();
    }

    #[DataProvider('repositoryReads')]
    public function testRepositoryHoldsRowLockUntilTransactionEnds(string $read): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $commandBus = $container->get(CommandBusInterface::class);
        $em = $container->get(EntityManagerInterface::class);
        $repository = $container->get(ClientInvitationRepositoryInterface::class);
        self::assertInstanceOf(CommandBusInterface::class, $commandBus);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(ClientInvitationRepositoryInterface::class, $repository);

        $clientId = Id::new();
        $invitationId = Id::new();
        $email = Email::fromString((string) Id::new() . '@example.com');
        $commandBus->dispatch(new CreateClientCommand($clientId, 'Concurrency', null));
        $commandBus->dispatch(new CreateClientInvitationCommand($invitationId, $clientId, $email, 'user'));
        // A new request loads the aggregate for the first time with the lock.
        $em->clear();

        $connection = $em->getConnection();
        $secondConnection = $this->secondWriterConnection();
        self::assertNotSame($connection, $secondConnection);
        self::assertNotSame($connection->fetchOne('SELECT pg_backend_pid()'), $secondConnection->fetchOne('SELECT pg_backend_pid()'));
        $sql = 'SELECT id FROM client.client_invitations WHERE id = :id FOR UPDATE NOWAIT';
        $parameters = ['id' => (string) $invitationId];

        $connection->beginTransaction();
        try {
            match ($read) {
                'get' => $repository->get($invitationId),
                'getForClient' => $repository->getForClient($invitationId, $clientId),
                'getPendingForClientAndEmail' => $repository->getPendingForClientAndEmail($clientId, $email),
                default => throw new \LogicException($read),
            };

            // Autocommit keeps a failed NOWAIT statement from poisoning a second transaction.
            try {
                $secondConnection->fetchOne($sql, $parameters);
                self::fail('The repository must hold a row lock on the invitation.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }
        } finally {
            $connection->rollBack();
        }

        self::assertSame((string) $invitationId, $secondConnection->fetchOne($sql, $parameters));
    }

    /** @return iterable<string, array{string}> */
    public static function repositoryReads(): iterable
    {
        yield 'accept and reject' => ['get'];
        yield 'tenant revoke' => ['getForClient'];
        yield 'platform revoke' => ['getPendingForClientAndEmail'];
    }

    private function secondWriterConnection(): Connection
    {
        $this->secondWriterKernel = static::createKernel();
        $this->secondWriterKernel->boot();
        $container = $this->secondWriterKernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(\Symfony\Component\DependencyInjection\ContainerInterface::class, $container);
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
