<?php

declare(strict_types=1);

namespace App\Tests\Client\Application\ClientInvitation;

use App\Client\Application\Client\Command\CreateClient\CreateClientCommand;
use App\Client\Application\ClientInvitation\Command\AcceptClientInvitation\AcceptClientInvitationCommand;
use App\Client\Application\ClientInvitation\Command\CreateClientInvitation\CreateClientInvitationCommand;
use App\Client\Application\ClientInvitation\Query\ClientInvitationQueryInterface;
use App\Client\Application\ClientMember\Query\ClientMemberQueryInterface;
use App\Client\Domain\ClientInvitation\ClientInvitation;
use App\Client\Domain\ClientInvitation\Event\ClientInvitationAccepted;
use App\Client\Domain\ClientMember\ClientMember;
use App\Client\Domain\ClientMember\Factory\ClientMemberFactoryInterface;
use App\Client\Domain\ClientMember\Repository\ClientMemberRepositoryInterface;
use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\SharedKernel\Domain\Event\DomainEventsBuffer;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\User\Command\LogInUserByEmail\LogInUserByEmailCommand;
use App\User\Application\User\Query\UserQueryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AcceptClientInvitationIntegrationTest extends KernelTestCase
{
    #[DataProvider('failureStages')]
    public function testMembershipFailureRollsBackAcceptance(string $stage): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $invitationQuery = $container->get(ClientInvitationQueryInterface::class);
        $memberQuery = $container->get(ClientMemberQueryInterface::class);
        $userQuery = $container->get(UserQueryInterface::class);
        $events = $container->get(DomainEventsBuffer::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(ClientInvitationQueryInterface::class, $invitationQuery);
        self::assertInstanceOf(ClientMemberQueryInterface::class, $memberQuery);
        self::assertInstanceOf(UserQueryInterface::class, $userQuery);
        self::assertInstanceOf(DomainEventsBuffer::class, $events);

        $clientId = Id::new();
        $invitationId = Id::new();
        $email = Email::fromString((string) Id::new() . '@example.com');
        $failure = new \RuntimeException('Simulated membership creation failure');
        $failAfterFlush = static function () use ($em, $invitationQuery, $invitationId, $failure): never {
            self::assertTrue($em->getConnection()->isTransactionActive());
            // Prove rollback of a real database write, not just an unflushed entity.
            $em->flush();
            self::assertSame(ClientInvitation::STATUS_ACCEPTED, $invitationQuery->findById($invitationId)?->status);
            throw $failure;
        };
        if ('factory' === $stage) {
            $factory = $this->createMock(ClientMemberFactoryInterface::class);
            $factory->expects(self::once())->method('create')->willReturnCallback($failAfterFlush);
            $container->set(ClientMemberFactoryInterface::class, $factory);
        } else {
            $repository = $this->createMock(ClientMemberRepositoryInterface::class);
            $repository->expects(self::once())->method('create')->willReturnCallback(
                static function (ClientMember $member) use ($em, $failAfterFlush): never {
                    $em->persist($member);
                    $failAfterFlush();
                },
            );
            $container->set(ClientMemberRepositoryInterface::class, $repository);
        }

        $bus = $container->get(CommandBusInterface::class);
        self::assertInstanceOf(CommandBusInterface::class, $bus);
        $bus->dispatch(new CreateClientCommand($clientId, 'Atomic acceptance', null));
        $bus->dispatch(new CreateClientInvitationCommand($invitationId, $clientId, $email, 'user'));
        $bus->dispatch(new LogInUserByEmailCommand($email));
        $user = $userQuery->findByEmail($email);
        self::assertNotNull($user);
        $before = $invitationQuery->findById($invitationId);
        self::assertNotNull($before);
        self::assertSame(ClientInvitation::STATUS_PENDING, $before->status);
        $em->clear();

        try {
            $bus->dispatch(new AcceptClientInvitationCommand($invitationId, new Id($user->id)));
            self::fail('Acceptance must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($em->getConnection()->isTransactionActive());
        self::assertEquals($before, $invitationQuery->findById($invitationId));
        self::assertSame([], $memberQuery->listByClient($clientId));
        self::assertNull($events->poll());
        self::assertSame([], $em->getConnection()->fetchFirstColumn(
            "SELECT event_id FROM shared.event_log WHERE payload ->> 'clientInvitationId' = :id AND event_name = :event",
            ['id' => (string) $invitationId, 'event' => ClientInvitationAccepted::class],
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function failureStages(): iterable
    {
        yield 'factory failure' => ['factory'];
        yield 'repository failure after membership persist' => ['repository'];
    }
}
