<?php

declare(strict_types=1);

namespace App\Tests\Client\Domain\ClientInvitation;

use App\Client\Domain\ClientInvitation\Event\ClientInvitationCreated;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationRoleNotAllowedException;
use App\Client\Domain\ClientInvitation\Exception\InviteeAlreadyMemberException;
use App\Client\Domain\ClientInvitation\Exception\PendingClientInvitationAlreadyExistsException;
use App\Client\Domain\ClientInvitation\Factory\ClientInvitationFactory;
use App\SharedKernel\Domain\Event\InMemoryDomainEventsCollector;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientInvitationFactoryTest extends TestCase
{
    public function testCreatesPendingInvitationWhenNoPendingInvitationOrMembershipExists(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeClientInvitationOutside($collector);
        $id = Id::new();
        $clientId = Id::new();

        (new ClientInvitationFactory($outside))->createByClientAdmin($id, $clientId, Email::fromString('Bob@Example.com'), 'user');

        $events = $collector->pull();
        self::assertCount(1, $events);
        self::assertInstanceOf(ClientInvitationCreated::class, $events[0]);
        self::assertEquals($id, $events[0]->clientInvitationId);
        self::assertEquals($clientId, $events[0]->clientId);
        self::assertSame('bob@example.com', $events[0]->email);
        self::assertSame('user', $events[0]->role);
        self::assertEquals($outside->now(), $events[0]->occurredAt);
    }

    public function testPlatformCreatesAdminInvitation(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeClientInvitationOutside($collector);
        (new ClientInvitationFactory($outside))->createByPlatformAdmin(Id::new(), Id::new(), Email::fromString('Admin@Example.com'));

        $events = $collector->pull();
        self::assertCount(1, $events);
        self::assertInstanceOf(ClientInvitationCreated::class, $events[0]);
        self::assertSame('admin', $events[0]->role);
        self::assertSame('admin@example.com', $events[0]->email);
    }

    /**
     * @param \Closure(ClientInvitationFactory): mixed $create
     */
    #[DataProvider('creators')]
    public function testRefusesSecondPendingInvitationForSameClientAndEmail(\Closure $create): void
    {
        $collector = new InMemoryDomainEventsCollector();

        try {
            $create(new ClientInvitationFactory(new FakeClientInvitationOutside($collector, pendingInvitationExists: true)));
            self::fail('Expected a duplicate pending invitation to be refused.');
        } catch (PendingClientInvitationAlreadyExistsException) {
        }

        self::assertSame([], $collector->pull());
    }

    /**
     * @param \Closure(ClientInvitationFactory): mixed $create
     */
    #[DataProvider('creators')]
    public function testRefusesInvitationOfExistingMember(\Closure $create): void
    {
        $collector = new InMemoryDomainEventsCollector();

        try {
            $create(new ClientInvitationFactory(new FakeClientInvitationOutside($collector, membershipExists: true)));
            self::fail('Expected an existing member to be refused.');
        } catch (InviteeAlreadyMemberException) {
        }

        self::assertSame([], $collector->pull());
    }

    /**
     * @return iterable<string, array{\Closure(ClientInvitationFactory): mixed}>
     */
    public static function creators(): iterable
    {
        yield 'client admin' => [static fn (ClientInvitationFactory $factory) => $factory->createByClientAdmin(Id::new(), Id::new(), Email::fromString('bob@example.com'), 'user')];
        yield 'platform admin' => [static fn (ClientInvitationFactory $factory) => $factory->createByPlatformAdmin(Id::new(), Id::new(), Email::fromString('bob@example.com'))];
    }

    public function testClientAdminRoleRefusalTakesPrecedenceOverConflicts(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeClientInvitationOutside($collector, pendingInvitationExists: true, membershipExists: true);

        try {
            (new ClientInvitationFactory($outside))->createByClientAdmin(Id::new(), Id::new(), Email::fromString('bob@example.com'), 'admin');
            self::fail('Expected role admin to be refused for a client admin.');
        } catch (ClientInvitationRoleNotAllowedException) {
        }

        self::assertSame([], $collector->pull());
    }
}
