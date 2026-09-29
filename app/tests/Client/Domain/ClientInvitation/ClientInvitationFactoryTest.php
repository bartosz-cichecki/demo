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

        self::assertSame(['pendingInvitationExists', 'membershipExists'], $outside->lookups);
        $events = $collector->pull();
        self::assertCount(1, $events);
        self::assertInstanceOf(ClientInvitationCreated::class, $events[0]);
        self::assertEquals($id, $events[0]->clientInvitationId);
        self::assertEquals($clientId, $events[0]->clientId);
        self::assertSame('bob@example.com', $events[0]->email);
        self::assertSame('user', $events[0]->role);
        self::assertEquals($outside->now(), $events[0]->occurredAt);
    }

    public function testClientAdminCannotInviteWithRoleAdmin(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeClientInvitationOutside($collector);

        try {
            (new ClientInvitationFactory($outside))->createByClientAdmin(Id::new(), Id::new(), Email::fromString('bob@example.com'), 'admin');
            self::fail('Expected role admin to be refused for a client admin.');
        } catch (ClientInvitationRoleNotAllowedException) {
        }

        self::assertSame([], $outside->lookups);
        self::assertSame([], $collector->pull());
    }

    public function testRejectsSecondPendingInvitationForSameClientAndEmail(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeClientInvitationOutside($collector, pendingInvitationExists: true);

        try {
            (new ClientInvitationFactory($outside))->createByClientAdmin(Id::new(), Id::new(), Email::fromString('bob@example.com'), 'user');
            self::fail('Expected a duplicate pending invitation to be refused.');
        } catch (PendingClientInvitationAlreadyExistsException) {
        }

        self::assertSame([], $collector->pull());
    }

    public function testRejectsInvitationOfExistingActiveOrSuspendedMember(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeClientInvitationOutside($collector, membershipExists: true);

        try {
            (new ClientInvitationFactory($outside))->createByClientAdmin(Id::new(), Id::new(), Email::fromString('bob@example.com'), 'user');
            self::fail('Expected an existing member to be refused.');
        } catch (InviteeAlreadyMemberException) {
        }

        self::assertSame(['pendingInvitationExists', 'membershipExists'], $outside->lookups);
        self::assertSame([], $collector->pull());
    }
}
