<?php

declare(strict_types=1);

namespace App\Tests\Client\Infrastructure\ClientMember;

use App\Client\Application\Client\Command\CreateClient\CreateClientCommand;
use App\Client\Application\ClientMember\Command\CreateClientMember\CreateClientMemberCommand;
use App\Client\Application\ClientMember\Command\CreateClientMember\CreateClientMemberCommandHandler;
use App\Client\Application\ClientMember\Command\SuspendClientMember\SuspendClientMemberCommand;
use App\Client\Application\ClientMember\Query\ClientMemberQueryInterface;
use App\Client\Domain\ClientMember\ClientMember;
use App\Client\Domain\ClientMember\Event\ClientMemberCreated;
use App\Client\Domain\ClientMember\Factory\ClientMemberFactory;
use App\Client\Domain\ClientMember\Repository\ClientMemberRepositoryInterface;
use App\Client\Domain\ClientMember\Repository\Exception\ClientMemberAlreadyExistsException;
use App\Client\Infrastructure\ClientMember\ClientMemberOutside;
use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\SharedKernel\Domain\Clock\MutableClock;
use App\SharedKernel\Domain\Event\InMemoryDomainEventsCollector;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClientMemberCreationIntegrationTest extends KernelTestCase
{
    #[DataProvider('creationAttempts')]
    public function testCreationUsesOneLookupAndRejectsExistingMemberships(?string $status): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $commandBus = $container->get(CommandBusInterface::class);
        $query = $container->get(ClientMemberQueryInterface::class);
        $repository = $container->get(ClientMemberRepositoryInterface::class);
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(CommandBusInterface::class, $commandBus);
        self::assertInstanceOf(ClientMemberQueryInterface::class, $query);
        self::assertInstanceOf(ClientMemberRepositoryInterface::class, $repository);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $clientId = Id::new();
        $userId = Id::new();
        $commandBus->dispatch(new CreateClientCommand($clientId, 'Membership uniqueness', null));
        if (null !== $status) {
            $commandBus->dispatch(new CreateClientMemberCommand($clientId, $userId, ['admin']));
            if ('suspended' === $status) {
                $commandBus->dispatch(new SuspendClientMemberCommand($clientId, $userId));
            }
        }
        $before = $query->findByClientAndUser($clientId, $userId);

        // Observe the real DBAL read and ORM write without hiding duplicate reads in a handler.
        $observedQuery = $this->createMock(ClientMemberQueryInterface::class);
        $observedQuery->expects(self::once())->method('findByClientAndUser')
            ->with($clientId, $userId)->willReturnCallback($query->findByClientAndUser(...));
        $observedRepository = $this->createMock(ClientMemberRepositoryInterface::class);
        $observedRepository->expects(self::never())->method('findByClientAndUser');
        $observedRepository->expects(null === $status ? self::once() : self::never())
            ->method('create')->willReturnCallback($repository->create(...));
        $collector = new InMemoryDomainEventsCollector();
        $factory = new ClientMemberFactory(new ClientMemberOutside(
            $collector,
            new MutableClock(DateTime::fromStorageString('2026-01-15 10:00:00')),
            $observedQuery,
        ));

        try {
            $handler = new CreateClientMemberCommandHandler($factory, $observedRepository);
            $handler(new CreateClientMemberCommand($clientId, $userId, ['user']));
            self::assertNull($status, 'An existing membership must be rejected.');
        } catch (ClientMemberAlreadyExistsException) {
            self::assertNotNull($status, 'An absent membership must be admitted.');
        }

        $em->flush();
        $after = $query->findByClientAndUser($clientId, $userId);
        self::assertNotNull($after);
        self::assertCount(1, $query->listByClient($clientId));
        $events = $collector->pull();
        if (null !== $status) {
            self::assertEquals($before, $after);
            self::assertSame([], $events);
        } else {
            self::assertSame(ClientMember::STATUS_ACTIVE, $after->status);
            self::assertSame(['user'], $after->roles);
            self::assertCount(1, $events);
            self::assertInstanceOf(ClientMemberCreated::class, $events[0]);
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function creationAttempts(): iterable
    {
        yield 'absent' => [null];
        yield 'active duplicate' => ['active'];
        yield 'suspended duplicate' => ['suspended'];
    }
}
