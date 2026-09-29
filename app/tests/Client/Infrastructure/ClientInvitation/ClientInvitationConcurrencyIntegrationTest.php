<?php

declare(strict_types=1);

namespace App\Tests\Client\Infrastructure\ClientInvitation;

use App\Client\Application\Client\Command\CreateClient\CreateClientCommand;
use App\Client\Application\ClientInvitation\Command\AcceptClientInvitation\AcceptClientInvitationCommand;
use App\Client\Application\ClientInvitation\Command\CreateClientInvitation\CreateClientInvitationCommand;
use App\Client\Application\ClientInvitation\Command\RejectClientInvitation\RejectClientInvitationCommand;
use App\Client\Application\ClientInvitation\Command\RevokeClientInvitation\RevokeClientInvitationCommand;
use App\Client\Domain\ClientInvitation\Event\ClientInvitationAccepted;
use App\Client\Domain\ClientInvitation\Event\ClientInvitationRejected;
use App\Client\Domain\ClientInvitation\Event\ClientInvitationRevoked;
use App\Client\Domain\ClientMember\Event\ClientMemberCreated;
use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\User\Command\LogInUserByEmail\LogInUserByEmailCommand;
use App\User\Application\User\Query\UserQueryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Real races between two writers. The second writer runs in a separately booted kernel with its own
 * EntityManager and DBAL connection and commits between the first writer's load and flush.
 * A change on the first connection would join its CommandBus transaction and roll back with it.
 */
final class ClientInvitationConcurrencyIntegrationTest extends KernelTestCase
{
    private CommandBusInterface $commandBus;
    private Connection $connection;
    private EntityManagerInterface $em;
    private ?KernelInterface $secondWriterKernel = null;
    private Id $clientId;
    private Id $invitationId;
    private Id $userId;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $commandBus = $container->get(CommandBusInterface::class);
        $connection = $container->get(Connection::class);
        $em = $container->get(EntityManagerInterface::class);
        $userQuery = $container->get(UserQueryInterface::class);
        self::assertInstanceOf(CommandBusInterface::class, $commandBus);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(UserQueryInterface::class, $userQuery);
        $this->commandBus = $commandBus;
        $this->connection = $connection;
        $this->em = $em;

        $this->clientId = Id::new();
        $this->invitationId = Id::new();
        $email = Email::fromString((string) Id::new() . '@example.com');
        $this->commandBus->dispatch(new CreateClientCommand($this->clientId, 'Concurrency', null));
        $this->commandBus->dispatch(new CreateClientInvitationCommand($this->invitationId, $this->clientId, $email, 'user'));
        $this->commandBus->dispatch(new LogInUserByEmailCommand($email));
        $user = $userQuery->findByEmail($email);
        self::assertNotNull($user);
        $this->userId = new Id($user->id);
    }

    protected function tearDown(): void
    {
        $this->secondWriterKernel?->shutdown();
        $this->secondWriterKernel = null;
        parent::tearDown();
    }

    public function testAcceptWithoutConcurrentWriterCreatesMembershipAndBumpsVersion(): void
    {
        $this->commandBus->dispatch(new AcceptClientInvitationCommand($this->invitationId, $this->userId));

        self::assertSame(['status' => 'accepted', 'version' => 2], $this->invitationState());
        self::assertSame(1, $this->membershipCount());
    }

    public function testRevokeWinsOverConcurrentAcceptAndRollsBackTheCreatedMembership(): void
    {
        $exception = $this->runWithConcurrentWriter(
            new AcceptClientInvitationCommand($this->invitationId, $this->userId),
            new RevokeClientInvitationCommand($this->invitationId, $this->clientId),
        );

        self::assertInstanceOf(OptimisticLockException::class, $exception);
        self::assertSame(['status' => 'revoked', 'version' => 2], $this->invitationState());
        self::assertSame(0, $this->membershipCount());
        self::assertSame(0, $this->eventLogCount(ClientMemberCreated::class, 'clientId', $this->clientId));
        self::assertSame(0, $this->eventLogCount(ClientInvitationAccepted::class, 'clientInvitationId', $this->invitationId));
        self::assertSame(1, $this->eventLogCount(ClientInvitationRevoked::class, 'clientInvitationId', $this->invitationId));
    }

    public function testRevokeWinsOverConcurrentRejectWithoutPartialWrite(): void
    {
        $exception = $this->runWithConcurrentWriter(
            new RejectClientInvitationCommand($this->invitationId, $this->userId),
            new RevokeClientInvitationCommand($this->invitationId, $this->clientId),
        );

        self::assertInstanceOf(OptimisticLockException::class, $exception);
        self::assertSame(['status' => 'revoked', 'version' => 2], $this->invitationState());
        self::assertSame(0, $this->membershipCount());
        self::assertSame(0, $this->eventLogCount(ClientInvitationRejected::class, 'clientInvitationId', $this->invitationId));
        self::assertSame(1, $this->eventLogCount(ClientInvitationRevoked::class, 'clientInvitationId', $this->invitationId));
    }

    public function testConcurrentAcceptCreatesExactlyOneMembership(): void
    {
        $exception = $this->runWithConcurrentWriter(
            new AcceptClientInvitationCommand($this->invitationId, $this->userId),
            new AcceptClientInvitationCommand($this->invitationId, $this->userId),
        );

        // The loser's membership insert hits the unique index before its versioned update runs.
        self::assertInstanceOf(UniqueConstraintViolationException::class, $exception);
        self::assertSame(['status' => 'accepted', 'version' => 2], $this->invitationState());
        self::assertSame(1, $this->membershipCount());
        self::assertSame(1, $this->eventLogCount(ClientMemberCreated::class, 'clientId', $this->clientId));
        self::assertSame(1, $this->eventLogCount(ClientInvitationAccepted::class, 'clientInvitationId', $this->invitationId));
    }

    /**
     * Dispatches $first; right before its flush, $second is dispatched and committed by the independent writer.
     */
    private function runWithConcurrentWriter(CommandInterface $first, CommandInterface $second): ?\Throwable
    {
        $secondWriter = $this->secondWriterCommandBus();
        $listener = new class(static fn () => $secondWriter->dispatch($second)) {
            private bool $done = false;

            public function __construct(private readonly \Closure $secondWriter)
            {
            }

            public function preFlush(): void
            {
                if (!$this->done) {
                    $this->done = true;
                    ($this->secondWriter)();
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::preFlush, $listener);

        try {
            $this->commandBus->dispatch($first);
        } catch (\Throwable $exception) {
            self::assertFalse($this->connection->isTransactionActive(), 'The losing transaction must be rolled back.');

            return $exception;
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::preFlush, $listener);
        }

        return null;
    }

    private function secondWriterCommandBus(): CommandBusInterface
    {
        $this->secondWriterKernel = static::createKernel();
        $this->secondWriterKernel->boot();
        $container = $this->secondWriterKernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(\Symfony\Component\DependencyInjection\ContainerInterface::class, $container);
        $commandBus = $container->get(CommandBusInterface::class);
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(CommandBusInterface::class, $commandBus);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertNotSame($this->connection, $connection);

        return $commandBus;
    }

    /**
     * @return array{status: string, version: int}
     */
    private function invitationState(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT status, version FROM client.client_invitations WHERE id = :id',
            ['id' => (string) $this->invitationId],
        );
        self::assertIsArray($row);
        self::assertIsString($row['status']);
        self::assertIsInt($row['version']);

        return ['status' => $row['status'], 'version' => $row['version']];
    }

    private function membershipCount(): int
    {
        return $this->intValue($this->connection->fetchOne(
            'SELECT COUNT(*) FROM client.client_memberships WHERE client_id = :clientId AND user_id = :userId',
            ['clientId' => (string) $this->clientId, 'userId' => (string) $this->userId],
        ));
    }

    private function eventLogCount(string $eventName, string $field, Id $value): int
    {
        return $this->intValue($this->connection->fetchOne(
            'SELECT COUNT(*) FROM shared.event_log WHERE event_name = :eventName AND payload ->> :field = :value',
            ['eventName' => $eventName, 'field' => $field, 'value' => (string) $value],
        ));
    }

    private function intValue(mixed $value): int
    {
        self::assertTrue(\is_int($value) || \is_string($value));

        return (int) $value;
    }
}
